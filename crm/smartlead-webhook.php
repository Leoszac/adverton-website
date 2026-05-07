<?php
// Smartlead / Instantly webhook — log opens/replies/bounces from cold email
// campaigns onto the matching lead.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/sequences.php';

header('Content-Type: text/plain');

$secret = crm_config('SMARTLEAD_WEBHOOK_SECRET');
if (!$secret) {
    http_response_code(503);
    error_log('[smartlead-webhook] SMARTLEAD_WEBHOOK_SECRET not configured');
    exit("Webhook receiver not configured.\n");
}

$got = $_GET['token'] ?? ($_SERVER['HTTP_X_SMARTLEAD_TOKEN'] ?? '');
if (!hash_equals((string)$secret, (string)$got)) {
    http_response_code(403); echo "bad token"; exit;
}

$payload = file_get_contents('php://input');
$event   = json_decode((string)$payload, true);

$type   = strtolower((string)($event['event_type'] ?? $event['event'] ?? ''));
$email  = strtolower(trim((string)($event['lead_email'] ?? $event['email'] ?? '')));
$campaign = (string)($event['campaign_name'] ?? $event['campaign'] ?? '');
$subject  = (string)($event['subject'] ?? '');
$replySnippet = (string)($event['reply_message'] ?? $event['reply_body'] ?? '');

if ($email === '') { http_response_code(200); echo "no email"; exit; }

$leadId = crm_findDuplicateLead($email, '');
// If unknown, create a placeholder lead so we don't lose the signal
if (!$leadId && in_array($type, ['email_opened','email_replied','email_bounced','open','reply','bounce'], true)) {
    $leadId = crm_insertLead([
        'source'      => 'manual',
        'email'       => $email,
        'source_page' => 'Smartlead campaign: ' . $campaign,
    ]);
}
if (!$leadId) { http_response_code(200); echo "skip"; exit; }

$disposition = null;
$body = '';
switch (true) {
    case str_contains($type, 'open'):
        $disposition = 'opened';
        $body = "Cold-email open · {$campaign}";
        break;
    case str_contains($type, 'reply'):
        $disposition = 'replied';
        $body = "Cold-email REPLY · {$campaign}\n" . mb_substr($replySnippet, 0, 500);
        break;
    case str_contains($type, 'bounce'):
        $disposition = 'bounced';
        $body = "Bounced · {$campaign}";
        break;
    case str_contains($type, 'click'):
        $disposition = 'clicked';
        $body = "Cold-email click · {$campaign}";
        break;
}

if ($disposition) {
    crm_logActivity($leadId, null, 'email', $disposition, $body ?: $subject);
}

// On reply: bump status to contacted (if still new) and unenroll from sequences
if ($disposition === 'replied') {
    $lead = crm_getLead($leadId);
    if ($lead && $lead['status'] === 'new') {
        crm_updateLead($leadId, ['status' => 'contacted'], null);
    }
    crm_unenrollLead($leadId, 'replied');
}

http_response_code(200);
echo "ok";
