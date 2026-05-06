<?php
// Pull Calendly iCal feed, find new bookings, log activity on matching leads.
// Designed to be invoked by cron every 15 min via:
//   php /home2/advertonnet/public_html/crm/cron-calendly.php
// or via a curl with the SEED_TOKEN if invoked over HTTP.
//
// Calendly iCal feed URL is configured in /home2/advertonnet/crm-config.php as
// CALENDLY_ICAL_URL. Find yours at: Calendly account → Settings → Calendar
// connections → "Get iCal feed".

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';

$cli = (php_sapi_name() === 'cli');

if (!$cli) {
    // HTTP invocation — require SEED_TOKEN
    header('Content-Type: text/plain; charset=utf-8');
    $expected = crm_config('SEED_TOKEN');
    $got = $_GET['token'] ?? '';
    if (!$expected || !hash_equals((string)$expected, (string)$got)) {
        http_response_code(403);
        echo "Forbidden.\n"; exit;
    }
}

$icalUrl = crm_config('CALENDLY_ICAL_URL');
if (!$icalUrl) {
    echo "CALENDLY_ICAL_URL not configured. Skipping.\n"; exit;
}

$ch = curl_init($icalUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => 'Adverton-CRM/1.0',
]);
$ical = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if (!is_string($ical) || $code >= 400) {
    echo "iCal fetch failed: HTTP {$code}\n"; exit;
}

// Crude iCal parser — splits VEVENT blocks.
// Calendly's invitee email lands in ATTENDEE, ORGANIZER and DESCRIPTION.
$events = [];
$lines = preg_split('/\r?\n/', $ical);
$cur = null; $continueKey = null;
foreach ($lines as $line) {
    // RFC5545 unfolding: lines starting with space/tab continue the previous one
    if (strlen($line) && ($line[0] === ' ' || $line[0] === "\t")) {
        if ($cur !== null && $continueKey !== null) {
            $cur[$continueKey] .= substr($line, 1);
        }
        continue;
    }
    if ($line === 'BEGIN:VEVENT') { $cur = []; continue; }
    if ($line === 'END:VEVENT')   { if ($cur !== null) $events[] = $cur; $cur = null; $continueKey = null; continue; }
    if ($cur === null) continue;
    if (!str_contains($line, ':')) continue;
    [$keyPart, $value] = explode(':', $line, 2);
    $key = strtoupper(explode(';', $keyPart, 2)[0]);
    if (in_array($key, ['UID','SUMMARY','DESCRIPTION','DTSTART','DTEND','ATTENDEE','ORGANIZER','LOCATION','STATUS'], true)) {
        $cur[$key] = ($cur[$key] ?? '') . $value;
        $continueKey = $key;
    }
}

$created = 0; $skipped = 0;
foreach ($events as $ev) {
    $uid = $ev['UID'] ?? '';
    if ($uid === '') { $skipped++; continue; }

    // Cancelled events: skip
    if (strtoupper($ev['STATUS'] ?? '') === 'CANCELLED') { $skipped++; continue; }

    // Extract email from ATTENDEE / DESCRIPTION
    $email = null;
    foreach (['ATTENDEE','DESCRIPTION','ORGANIZER'] as $f) {
        if (empty($ev[$f])) continue;
        if (preg_match('/[a-z0-9._+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', (string)$ev[$f], $m)) {
            $cand = strtolower($m[0]);
            if (!str_ends_with($cand, '@adverton.net') && !str_ends_with($cand, '@calendly.com')) {
                $email = $cand; break;
            }
        }
    }
    if (!$email) { $skipped++; continue; }

    // Match to a lead by email
    $leadId = crm_findDuplicateLead($email, '');
    if (!$leadId) { $skipped++; continue; }

    // Have we already logged this UID? Check activities for the marker
    $stmt = crm_db()->prepare(
        "SELECT 1 FROM lead_activities WHERE lead_id = ? AND type = 'meeting' AND body LIKE ? LIMIT 1"
    );
    $stmt->execute([$leadId, '%calendly:' . $uid . '%']);
    if ($stmt->fetch()) { $skipped++; continue; }

    $summary = trim((string)($ev['SUMMARY'] ?? 'Meeting'));
    $when    = (string)($ev['DTSTART'] ?? '');
    $whenFmt = '';
    // DTSTART can be 20260315T140000Z or 20260315T140000 (floating)
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', $when, $m)) {
        $whenFmt = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
    }

    $body = "📅 {$summary}" . ($whenFmt ? " · {$whenFmt} UTC" : '') . " · calendly:{$uid}";
    crm_logActivity($leadId, null, 'meeting', 'scheduled', $body);

    // Auto-task: prepare for the meeting (if it's in the future)
    $startTs = $whenFmt ? strtotime($whenFmt . ' UTC') : 0;
    if ($startTs > time() + 3600) {
        $prepDue = date('Y-m-d H:i:s', $startTs - 3600); // 1h before
        $name = trim((($l = crm_getLead($leadId))['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
        crm_createTask([
            'lead_id' => $leadId,
            'title'   => 'Prep meeting with ' . ($name ?: 'lead'),
            'due_at'  => $prepDue,
        ]);
    }

    // Bump status if currently 'new'
    $lead = crm_getLead($leadId);
    if ($lead && $lead['status'] === 'new') {
        crm_updateLead($leadId, ['status' => 'qualified'], null);
    }

    $created++;
}

echo "Calendly sync done. New: {$created}. Skipped/already-known: {$skipped}.\n";
