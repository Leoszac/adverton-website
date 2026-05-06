<?php
// OpenPhone webhook — auto-log calls/SMS as activities on the matching lead.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';

header('Content-Type: text/plain');

$secret = crm_config('OPENPHONE_WEBHOOK_SECRET');
if (!$secret) { http_response_code(500); echo "OPENPHONE_WEBHOOK_SECRET not configured"; exit; }

// OpenPhone signs with HMAC-SHA256 over the raw body; header is openphone-signature
$payload = file_get_contents('php://input');
$sig = $_SERVER['HTTP_OPENPHONE_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', (string)$payload, (string)$secret);
if (!$sig || !hash_equals($expected, (string)$sig)) {
    // Fallback: also accept a plain shared-token query param so it's easy to test
    if (!hash_equals((string)$secret, (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "bad signature"; exit;
    }
}

$event = json_decode((string)$payload, true);
$type  = (string)($event['type'] ?? '');
$obj   = $event['data']['object'] ?? $event['data'] ?? [];

// Pull both numbers from the call/message; we don't know which is the lead.
$candidates = [];
foreach (['from', 'to', 'phoneNumber', 'participants'] as $f) {
    if (empty($obj[$f])) continue;
    $v = $obj[$f];
    if (is_string($v))    $candidates[] = $v;
    if (is_array($v))     foreach ($v as $vv) $candidates[] = is_array($vv) ? ($vv['phoneNumber'] ?? '') : (string)$vv;
}
$leadId = null;
foreach ($candidates as $num) {
    $digits = preg_replace('/\D/', '', (string)$num);
    if (strlen($digits) < 10) continue;
    $leadId = crm_findDuplicateLead('', $digits);
    if ($leadId) break;
}

// If no match, try to create a new lead from inbound call
if (!$leadId && in_array($type, ['call.completed','call.recording.completed','message.received'], true)) {
    $unknown = '';
    foreach ($candidates as $num) {
        $d = preg_replace('/\D/', '', (string)$num);
        if (strlen($d) >= 10) { $unknown = $num; break; }
    }
    if ($unknown) {
        $leadId = crm_insertLead([
            'source'      => 'inbound_call',
            'phone'       => $unknown,
            'source_page' => 'OpenPhone inbound',
        ]);
    }
}

if (!$leadId) { http_response_code(200); echo "no match"; exit; }

switch ($type) {

case 'call.completed':
case 'call.recording.completed': {
    $direction  = (string)($obj['direction'] ?? '');
    $answered   = !empty($obj['answeredAt']);
    $duration   = (int)($obj['duration'] ?? 0);
    $recording  = (string)($obj['media']['url'] ?? $obj['recording']['url'] ?? '');
    $disposition = $answered ? 'answered' : ($direction === 'incoming' ? 'missed' : 'voicemail');
    $body = "OpenPhone {$direction} · {$duration}s";
    if ($recording) $body .= "\nRecording: " . $recording;
    crm_logActivity($leadId, null, 'call', $disposition, $body);
    crm_touchLastContacted($leadId);
    break;
}

case 'message.received':
case 'message.delivered': {
    $disposition = $type === 'message.received' ? 'replied' : 'sent';
    $body = (string)($obj['body'] ?? $obj['text'] ?? '');
    crm_logActivity($leadId, null, 'sms', $disposition, mb_substr($body, 0, 1000));
    crm_touchLastContacted($leadId);
    break;
}

default:
    break;
}

http_response_code(200);
echo "ok";
