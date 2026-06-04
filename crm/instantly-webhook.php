<?php
// Instantly cold-email webhook receiver.
//
// Subscribes in Instantly to: email_reply, email_bounced, email_opened,
// email_link_clicked, lead_unsubscribed. ONLY a reply auto-creates a lead in
// the CRM (source=cold_email_instantly), logs the reply body as activity,
// bumps lead status to 'qualified', and unenrolls from any active sequences.
// Bounces/unsubscribes only act on contacts that are ALREADY leads — they
// never create new ones (Instantly suppresses dead addresses on its side).
//
// URL format:
//   https://adverton.net/crm/instantly-webhook.php?token=<INSTANTLY_WEBHOOK_SECRET>
//
// Auth: shared-token query string. Paste matching token in /crm/integrations.php
// AND in Instantly's webhook URL.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/sequences.php';

header('Content-Type: text/plain');

// --- Auth ---
$secret = crm_config('INSTANTLY_WEBHOOK_SECRET');
if (!$secret) {
    http_response_code(503);
    error_log('[instantly-webhook] INSTANTLY_WEBHOOK_SECRET not configured');
    exit("Webhook receiver not configured.\n");
}

$got = $_GET['token'] ?? ($_SERVER['HTTP_X_INSTANTLY_TOKEN'] ?? '');
if (!hash_equals((string)$secret, (string)$got)) {
    http_response_code(403);
    echo "bad token";
    exit;
}

// --- Parse payload ---
$raw = file_get_contents('php://input');
$evt = json_decode((string)$raw, true);
if (!is_array($evt)) {
    http_response_code(400);
    echo "invalid json";
    exit;
}

// Instantly variants of the event_type field name across versions
$type = strtolower((string)(
    $evt['event_type']
    ?? $evt['event']
    ?? $evt['type']
    ?? ''
));

$leadEmail = strtolower(trim((string)(
    $evt['lead_email']
    ?? $evt['email']
    ?? $evt['lead']['email']
    ?? ''
)));

$campaign = (string)(
    $evt['campaign_name']
    ?? $evt['campaign']
    ?? $evt['campaign_id']
    ?? ''
);

$subject = (string)($evt['email_subject'] ?? $evt['subject'] ?? $evt['reply_subject'] ?? '');
$replyBody = (string)(
    $evt['reply_text']
    ?? $evt['reply_body']
    ?? $evt['reply_message']
    ?? $evt['email_body']
    ?? ''
);

// --- Skip if no email ---
if ($leadEmail === '') {
    http_response_code(200);
    echo "skip: no email in payload";
    exit;
}

// --- Find or create lead ---
$leadId = crm_findDuplicateLead($leadEmail, '');

// Only a REPLY makes someone a lead. Bounces and unsubscribes are dead
// addresses, not prospects — auto-creating leads for them just floods the
// pipeline with "LOST" cold-email rows (Instantly already suppresses them on
// its side). Opens/clicks are off (we run with tracking disabled) and too
// weak to be a lead anyway. If the contact already exists as a lead, the
// bounce/unsubscribe side-effects below still apply to it.
$shouldCreate = !$leadId && str_contains($type, 'reply');

if ($shouldCreate) {
    // Pull whatever name / company info is in the payload
    $firstName = (string)($evt['first_name'] ?? $evt['lead']['first_name'] ?? '');
    $lastName  = (string)($evt['last_name']  ?? $evt['lead']['last_name']  ?? '');
    $company   = (string)($evt['company']    ?? $evt['lead']['company']    ?? '');

    $leadId = crm_insertLead([
        'source'      => 'cold_email_instantly',
        'email'       => $leadEmail,
        'first_name'  => $firstName ?: null,
        'last_name'   => $lastName  ?: null,
        'company'     => $company   ?: null,
        'source_page' => 'Instantly campaign: ' . ($campaign ?: '?'),
    ]);
}

if (!$leadId) {
    http_response_code(200);
    echo "skip: no lead";
    exit;
}

// --- Map event to disposition + activity ---
$disposition = null;
$body = '';

switch (true) {
    case str_contains($type, 'reply'):
        $disposition = 'replied';
        $bodyPrefix  = "Cold-email REPLY · " . ($campaign ?: 'Instantly');
        $snippet     = mb_substr(trim($replyBody), 0, 800);
        $body = $bodyPrefix . ($subject ? "\nSubject: {$subject}" : '') . ($snippet ? "\n\n{$snippet}" : '');
        break;

    case str_contains($type, 'click') || str_contains($type, 'link'):
        $disposition = 'clicked';
        $body = "Cold-email click · " . ($campaign ?: 'Instantly') . ($subject ? " · {$subject}" : '');
        break;

    case str_contains($type, 'open'):
        $disposition = 'opened';
        $body = "Cold-email open · " . ($campaign ?: 'Instantly') . ($subject ? " · {$subject}" : '');
        break;

    case str_contains($type, 'bounc'):
        $disposition = 'bounced';
        $body = "Bounced · " . ($campaign ?: 'Instantly');
        break;

    case str_contains($type, 'unsubscrib'):
        $disposition = 'unsubscribed';
        $body = "Unsubscribed · " . ($campaign ?: 'Instantly');
        break;

    case str_contains($type, 'sent'):
        $disposition = 'sent';
        $body = "Cold-email sent · " . ($campaign ?: 'Instantly') . ($subject ? " · {$subject}" : '');
        break;
}

if ($disposition) {
    crm_logActivity($leadId, null, 'email', $disposition, $body ?: $subject);
}

// --- Side effects on REPLY: bump status, unenroll, fire hot webhook ---
if ($disposition === 'replied') {
    $lead = crm_getLead($leadId);
    if ($lead) {
        // Cold-email reply is a STRONG signal — bump straight to qualified
        // (vs Smartlead which bumps to 'contacted'). Override only if currently
        // 'new' or 'contacted'.
        if (in_array($lead['status'], ['new', 'contacted'], true)) {
            crm_updateLead($leadId, ['status' => 'qualified'], null);
        }
    }
    crm_unenrollLead($leadId, 'replied');

    // Fire engagement hot webhook so Slack / Discord / Telegram pings
    if (function_exists('crm_fireEngagementHotWebhook')) {
        crm_fireEngagementHotWebhook($leadId, 'cold-email reply', [
            'campaign' => $campaign,
            'subject'  => $subject,
        ]);
    }
}

// --- Side effects on BOUNCE: mark lead as lost ---
if ($disposition === 'bounced') {
    $lead = crm_getLead($leadId);
    if ($lead && !in_array($lead['status'], ['won', 'lost'], true)) {
        crm_updateLead($leadId, ['status' => 'lost'], null);
    }
    crm_unenrollLead($leadId, 'bounced');
}

// --- Side effect on UNSUBSCRIBE: tag DNC + unenroll ---
if ($disposition === 'unsubscribed') {
    crm_unenrollLead($leadId, 'unsubscribed');
    if (function_exists('crm_addTagByName')) {
        crm_addTagByName($leadId, 'DNC');
    }
}

http_response_code(200);
echo "ok: type={$type} lead={$leadId} disp=" . ($disposition ?: 'noop');
