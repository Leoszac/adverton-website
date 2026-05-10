<?php
// Pull Calendly scheduled events via API v2, find new bookings, log activity
// on matching leads. Designed to be invoked by cron every 15 min:
//   php /home2/advertonnet/public_html/crm/cron-calendly.php
//
// Requires CALENDLY_API_TOKEN (Personal Access Token). Generate at:
// Calendly → Integrations & apps → API and webhooks → Personal Access Tokens.
// Paste into /crm/integrations.php (no shell access needed).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';

$cli = (php_sapi_name() === 'cli');

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $expected = crm_config('SEED_TOKEN');
    $got = $_GET['token'] ?? '';
    if (!$expected || !hash_equals((string)$expected, (string)$got)) {
        http_response_code(403);
        echo "Forbidden.\n"; exit;
    }
}

$token = crm_config('CALENDLY_API_TOKEN');
if (!$token) {
    echo "CALENDLY_API_TOKEN not configured. Skipping.\n"; exit;
}

function calendly_api(string $token, string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_USERAGENT => 'Adverton-CRM/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $code >= 400) {
        error_log("[cron-calendly] HTTP {$code} on {$url}");
        return null;
    }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

// The PAT JWT carries user_uuid in its payload, so we skip /users/me (which
// requires the users:read scope — not granted to default PATs).
$parts = explode('.', $token);
if (count($parts) !== 3) {
    echo "Malformed token (not a JWT).\n"; exit;
}
$payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
$payload = is_string($payloadJson) ? json_decode($payloadJson, true) : null;
$uuid = is_array($payload) ? ($payload['user_uuid'] ?? null) : null;
if (!is_string($uuid) || $uuid === '') {
    echo "Could not extract user_uuid from token.\n"; exit;
}
$userUri = 'https://api.calendly.com/users/' . $uuid;

$min = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
$max = gmdate('Y-m-d\TH:i:s\Z', time() + 90 * 86400);
$next = 'https://api.calendly.com/scheduled_events?'
    . http_build_query([
        'user'           => $userUri,
        'min_start_time' => $min,
        'max_start_time' => $max,
        'status'         => 'active',
        'count'          => 100,
    ]);

$created = 0; $skipped = 0;
while ($next) {
    $list = calendly_api($token, $next);
    if (!$list || empty($list['collection'])) break;

    foreach ($list['collection'] as $ev) {
        $uri   = (string)($ev['uri']  ?? '');
        $name  = (string)($ev['name'] ?? 'Meeting');
        $start = (string)($ev['start_time'] ?? '');
        $uuid  = $uri ? basename($uri) : '';
        if ($uuid === '') { $skipped++; continue; }

        $inv = calendly_api($token, $uri . '/invitees');
        if (!$inv || empty($inv['collection'])) { $skipped++; continue; }

        foreach ($inv['collection'] as $invitee) {
            $email = strtolower(trim((string)($invitee['email'] ?? '')));
            if ($email === '' || str_ends_with($email, '@adverton.net')) { $skipped++; continue; }

            $leadId = crm_findDuplicateLead($email, '');
            if (!$leadId) { $skipped++; continue; }

            $stmt = crm_db()->prepare(
                "SELECT 1 FROM lead_activities WHERE lead_id = ? AND type = 'meeting' AND body LIKE ? LIMIT 1"
            );
            $stmt->execute([$leadId, '%calendly:' . $uuid . '%']);
            if ($stmt->fetch()) { $skipped++; continue; }

            $whenFmt = '';
            if ($start && ($ts = strtotime($start)) !== false) {
                $whenFmt = gmdate('Y-m-d H:i', $ts);
            }
            $body = "📅 {$name}" . ($whenFmt ? " · {$whenFmt} UTC" : '') . " · calendly:{$uuid}";
            crm_logActivity($leadId, null, 'meeting', 'scheduled', $body);

            $startTs = $whenFmt ? strtotime($whenFmt . ' UTC') : 0;
            if ($startTs > time() + 3600) {
                $prepDue = date('Y-m-d H:i:s', $startTs - 3600);
                $lead = crm_getLead($leadId);
                $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                crm_createTask([
                    'lead_id' => $leadId,
                    'title'   => 'Prep meeting with ' . ($leadName ?: 'lead'),
                    'due_at'  => $prepDue,
                ]);
            }

            $lead = crm_getLead($leadId);
            if ($lead && $lead['status'] === 'new') {
                crm_updateLead($leadId, ['status' => 'qualified'], null);
            }

            $created++;
        }
    }

    $next = $list['pagination']['next_page'] ?? null;
}

echo "Calendly sync done. New: {$created}. Skipped/already-known: {$skipped}.\n";
