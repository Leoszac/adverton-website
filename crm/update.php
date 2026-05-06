<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/email_track.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/sequences.php';
require_once __DIR__ . '/lib/routing.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/stripe.php';

$user = crm_requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /crm/');
    exit;
}

if (!crm_csrfCheck($_POST['csrf'] ?? null)) {
    http_response_code(403);
    crm_log("csrf_fail uid={$user['id']} ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit('CSRF token invalid. Refresh the page and try again.');
}

$mode = (string)($_POST['mode'] ?? 'pipeline');

switch ($mode) {

case 'pipeline': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || !crm_getLead($id)) {
        http_response_code(404); header('Location: /crm/'); exit;
    }
    crm_updateLead($id, [
        'status'            => (string)($_POST['status'] ?? ''),
        'owner_user_id'     => $_POST['owner_user_id']     ?? null,
        'notes'             => (string)($_POST['notes']    ?? ''),
        'monthly_fee'       => $_POST['monthly_fee']       ?? null,
        'ad_budget'         => $_POST['ad_budget']         ?? null,
        'mgmt_fee_pct'      => $_POST['mgmt_fee_pct']      ?? null,
        'expected_close_at' => $_POST['expected_close_at'] ?? null,
        'temperature'       => $_POST['temperature']       ?? null,
        'lost_reason'       => $_POST['lost_reason']       ?? null,
        'lost_reason_note'  => $_POST['lost_reason_note']  ?? null,
        'won_reason_note'   => $_POST['won_reason_note']   ?? null,
        'bant_budget'       => $_POST['bant_budget']       ?? null,
        'bant_authority'    => $_POST['bant_authority']    ?? null,
        'bant_need'         => $_POST['bant_need']         ?? null,
        'bant_timeline'     => $_POST['bant_timeline']     ?? null,
        'bant_notes'        => $_POST['bant_notes']        ?? null,
    ], (int)$user['id']);
    header('Location: /crm/lead.php?id=' . $id . '&saved=1');
    exit;
}

case 'file_upload': {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0 || !crm_getLead($leadId)) { http_response_code(404); exit; }
    if (!isset($_FILES['file'])) {
        header('Location: /crm/lead.php?id=' . $leadId . '&fileerror=' . urlencode('No file'));
        exit;
    }
    $r = crm_storeUploadedFile($leadId, $_FILES['file'], (int)$user['id']);
    if ($r['ok']) {
        crm_logActivity($leadId, (int)$user['id'], 'system', 'file_uploaded',
            'Uploaded file: ' . ($r['name'] ?? '?'));
    }
    $back = '/crm/lead.php?id=' . $leadId;
    if (!$r['ok']) $back .= '&fileerror=' . urlencode($r['error']);
    else           $back .= '&saved=1';
    header('Location: ' . $back);
    exit;
}

case 'file_delete': {
    $fileId = (int)($_POST['file_id'] ?? 0);
    $f = $fileId > 0 ? crm_getFile($fileId) : null;
    if (!$f) { http_response_code(404); exit; }
    crm_deleteFile($fileId);
    crm_logActivity((int)$f['lead_id'], (int)$user['id'], 'system', 'file_deleted',
        'Removed file: ' . $f['original_name']);
    header('Location: /crm/lead.php?id=' . (int)$f['lead_id']);
    exit;
}

case 'template_send': {
    $leadId     = (int)($_POST['lead_id']     ?? 0);
    $templateId = (int)($_POST['template_id'] ?? 0);
    $lead = $leadId > 0 ? crm_getLead($leadId) : null;
    if (!$lead) { http_response_code(404); exit; }

    // Accept user-edited subject/body (from email-compose.php). Fall back to
    // re-rendering the template if the editor wasn't used.
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body    = (string)($_POST['body'] ?? '');
    if ($subject === '' || $body === '') {
        $tpl = $templateId > 0 ? crm_getTemplate($templateId) : null;
        if (!$tpl) { http_response_code(400); exit('Subject and body required'); }
        $subject = crm_renderTemplate($tpl['subject'], $lead);
        $body    = crm_renderTemplate($tpl['body'],    $lead);
    } else {
        // Re-render the user's edits in case they kept variables like {first_name}
        // (so they can choose to leave placeholders OR write literal copy)
        $subject = crm_renderTemplate($subject, $lead);
        $body    = crm_renderTemplate($body,    $lead);
    }

    $r = crm_sendTrackedEmail($leadId, $lead, $templateId ?: null, (int)$user['id'], $subject, $body);
    $back = '/crm/lead.php?id=' . $leadId;
    $composeBack = '/crm/email-compose.php?lead_id=' . $leadId . ($templateId ? '&template_id=' . $templateId : '');
    if ($r['ok']) {
        if ($lead['status'] === 'new') {
            crm_updateLead($leadId, ['status' => 'contacted'], (int)$user['id']);
        }
        $back .= '&saved=1';
        header('Location: ' . $back);
    } else {
        // On send failure, return to compose so user can fix and retry
        header('Location: ' . $composeBack . '&err=' . urlencode($r['error'] ?? 'send failed'));
    }
    exit;
}

case 'pipeline_status': {
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    if ($id <= 0 || !in_array($status, CRM_LEAD_STATUSES, true)) {
        http_response_code(400); exit('bad request');
    }
    $ok = crm_updateLead($id, ['status' => $status], (int)$user['id']);
    if (!$ok) { http_response_code(500); exit('update failed'); }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'id' => $id, 'status' => $status]);
    exit;
}

case 'activity': {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0 || !crm_getLead($leadId)) {
        http_response_code(404); header('Location: /crm/'); exit;
    }
    $type        = (string)($_POST['type'] ?? 'note');
    $disposition = (string)($_POST['disposition'] ?? '');
    $body        = trim((string)($_POST['body'] ?? ''));
    crm_logActivity($leadId, (int)$user['id'], $type, $disposition ?: null, $body ?: null);

    // Touch last_contacted_at when an outbound channel is logged
    if (in_array($type, ['call','email','sms'], true)) {
        crm_touchLastContacted($leadId);
    }

    $current = crm_getLead($leadId);
    if ($current && $current['status'] === 'new' && in_array($type, ['call','email','sms'], true)) {
        crm_updateLead($leadId, ['status' => 'contacted'], (int)$user['id']);
    }
    if ($current && $disposition === 'interested' && in_array($current['status'], ['new','contacted'], true)) {
        crm_updateLead($leadId, ['status' => 'qualified'], (int)$user['id']);
    }

    // If posted via fetch (template-use logger), skip the redirect
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
        ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
    }
    header('Location: /crm/lead.php?id=' . $leadId . '&saved=1');
    exit;
}

case 'task_create': {
    $taskId = crm_createTask([
        'lead_id'     => $_POST['lead_id']     ?? null,
        'assigned_to' => $_POST['assigned_to'] ?? $user['id'],
        'created_by'  => $user['id'],
        'title'       => $_POST['title']       ?? '',
        'notes'       => $_POST['notes']       ?? null,
        'due_at'      => $_POST['due_at']      ?? '',
    ]);
    if ($taskId && !empty($_POST['lead_id'])) {
        crm_logActivity((int)$_POST['lead_id'], (int)$user['id'], 'system', 'task_created',
            'Task: ' . $_POST['title'] . ' (due ' . $_POST['due_at'] . ')');
    }
    $back = !empty($_POST['lead_id'])
        ? '/crm/lead.php?id=' . (int)$_POST['lead_id'] . '&saved=1'
        : '/crm/today.php';
    header('Location: ' . $back);
    exit;
}

case 'task_complete':
case 'task_uncomplete': {
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) { http_response_code(400); exit('bad task'); }
    if ($mode === 'task_complete') crm_completeTask($taskId, (int)$user['id']);
    else                            crm_uncompleteTask($taskId);
    $back = $_SERVER['HTTP_REFERER'] ?? '/crm/today.php';
    if (!str_starts_with($back, '/crm/') && !str_contains($back, '/crm/')) $back = '/crm/today.php';
    header('Location: ' . $back);
    exit;
}

case 'tag_add': {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $name   = trim((string)($_POST['tag'] ?? ''));
    if ($leadId > 0 && $name !== '') {
        $tagId = crm_addTagToLead($leadId, $name);
        if ($tagId) {
            crm_logActivity($leadId, (int)$user['id'], 'system', 'tag_added', 'Tag: ' . $name);
        }
    }
    header('Location: /crm/lead.php?id=' . $leadId);
    exit;
}

case 'tag_remove': {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $tagId  = (int)($_POST['tag_id']  ?? 0);
    if ($leadId > 0 && $tagId > 0) {
        crm_removeTagFromLead($leadId, $tagId);
    }
    header('Location: /crm/lead.php?id=' . $leadId);
    exit;
}

case 'bulk': {
    $ids    = $_POST['ids'] ?? [];
    if (!is_array($ids) || !$ids) {
        header('Location: /crm/'); exit;
    }
    $action = (string)($_POST['bulk_action'] ?? '');
    $value  = '';
    switch ($action) {
        case 'status':  $value = $_POST['bulk_value_status'] ?? '';  break;
        case 'owner':   $value = $_POST['bulk_value_owner']  ?? '';  break;
        case 'tag_add': $value = $_POST['bulk_value_tag']    ?? '';  break;
        case 'delete':  $value = '';  break;
        default:
            header('Location: /crm/'); exit;
    }
    crm_bulkUpdate($ids, $action, $value, (int)$user['id']);
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/crm/'));
    exit;
}

case 'template_save': {
    $id   = (int)($_POST['id'] ?? 0);
    $del  = !empty($_POST['delete']);
    if ($id > 0 && $del) {
        crm_deleteTemplate($id);
        header('Location: /crm/templates.php');
        exit;
    }
    $newId = crm_saveTemplate($id, [
        'name'    => $_POST['name']    ?? '',
        'subject' => $_POST['subject'] ?? '',
        'body'    => $_POST['body']    ?? '',
    ], (int)$user['id']);
    header('Location: /crm/templates.php?edit=' . $newId . '&saved=1');
    exit;
}

case 'lead_delete': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) crm_deleteLead($id);
    header('Location: /crm/');
    exit;
}

case 'lead_create': {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $name  = trim((string)($_POST['first_name'] ?? '') . ' ' . (string)($_POST['last_name'] ?? ''));
    if ($email === '' && $name === '' && empty($_POST['business_name'])) {
        header('Location: /crm/lead-new.php?err=empty'); exit;
    }
    $sourceIn = (string)($_POST['source'] ?? 'manual');
    $allowedManual = ['manual','lead_magnet','referral','affiliate','inbound_call'];
    if (!in_array($sourceIn, $allowedManual, true)) $sourceIn = 'manual';
    $sourcePageIn = trim((string)($_POST['source_page'] ?? ''));
    if ($sourcePageIn === '') $sourcePageIn = 'manual entry by ' . ($user['username'] ?? '');

    $leadId = crm_insertLead([
        'source'        => $sourceIn,
        'source_page'   => $sourcePageIn,
        'first_name'    => $_POST['first_name']    ?? null,
        'last_name'     => $_POST['last_name']     ?? null,
        'email'         => $_POST['email']         ?? null,
        'phone'         => $_POST['phone']         ?? null,
        'business_name' => $_POST['business_name'] ?? null,
        'trade'         => $_POST['trade']         ?? null,
        'city_state'    => $_POST['city_state']    ?? null,
        'website'       => $_POST['website']       ?? null,
    ]);
    if (!$leadId) { header('Location: /crm/lead-new.php?err=insert'); exit; }

    // Apply owner override + initial status (each may trigger downstream effects)
    $owner = $_POST['owner_user_id'] ?? '';
    $patch = [];
    if ($owner !== '') $patch['owner_user_id'] = (int)$owner;
    if (!empty($_POST['notes'])) $patch['notes'] = (string)$_POST['notes'];
    if ($patch) crm_updateLead($leadId, $patch, (int)$user['id']);

    $initial = (string)($_POST['initial_status'] ?? 'new');
    if (in_array($initial, CRM_LEAD_STATUSES, true) && $initial !== 'new') {
        crm_updateLead($leadId, ['status' => $initial], (int)$user['id']);
    }
    header('Location: /crm/lead.php?id=' . $leadId . '&saved=1');
    exit;
}

case 'client_create': {
    $bn = trim((string)($_POST['business_name'] ?? ''));
    if ($bn === '') {
        header('Location: /crm/client-new.php?err=' . urlencode('Business name is required'));
        exit;
    }
    $clientId = crm_createClient([
        'business_name'      => $_POST['business_name']      ?? null,
        'trade'              => $_POST['trade']              ?? null,
        'primary_email'      => $_POST['primary_email']      ?? null,
        'primary_phone'      => $_POST['primary_phone']      ?? null,
        'contract_start_at'  => $_POST['contract_start_at']  ?? null,
        'contract_end_at'    => $_POST['contract_end_at']    ?? null,
        'monthly_fee'        => $_POST['monthly_fee']        ?? null,
        'ad_budget'          => $_POST['ad_budget']          ?? null,
        'mgmt_fee_pct'       => $_POST['mgmt_fee_pct']       ?? null,
        'status'             => $_POST['status']             ?? 'active',
        'installment_count'  => $_POST['installment_count']  ?? 0,
        'account_manager_id' => $_POST['account_manager_id'] ?? null,
        'notes'              => $_POST['notes']              ?? null,
    ], (int)$user['id']);
    if (!$clientId) { header('Location: /crm/client-new.php?err=insert'); exit; }
    header('Location: /crm/client.php?id=' . $clientId . '&saved=1');
    exit;
}

case 'client_update': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || !crm_getClient($id)) { http_response_code(404); header('Location: /crm/clients.php'); exit; }
    crm_updateClient($id, [
        'business_name'        => $_POST['business_name']      ?? null,
        'trade'                => $_POST['trade']              ?? null,
        'primary_email'        => $_POST['primary_email']      ?? null,
        'primary_phone'        => $_POST['primary_phone']      ?? null,
        'contract_start_at'    => $_POST['contract_start_at']  ?? null,
        'contract_end_at'      => $_POST['contract_end_at']    ?? null,
        'monthly_fee'          => $_POST['monthly_fee']        ?? null,
        'ad_budget'            => $_POST['ad_budget']          ?? null,
        'mgmt_fee_pct'         => $_POST['mgmt_fee_pct']       ?? null,
        'status'               => $_POST['status']             ?? null,
        'payment_status'       => $_POST['payment_status']     ?? null,
        'installment_count'    => $_POST['installment_count']  ?? null,
        'account_manager_id'   => $_POST['account_manager_id'] ?? null,
        'stripe_customer_id'   => $_POST['stripe_customer_id']     ?? null,
        'stripe_subscription_id' => $_POST['stripe_subscription_id'] ?? null,
        'cancellation_reason'  => $_POST['cancellation_reason']     ?? null,
        'cancellation_note'    => $_POST['cancellation_note']       ?? null,
        'notes'                => $_POST['notes']                   ?? null,
    ], (int)$user['id']);
    header('Location: /crm/client.php?id=' . $id . '&saved=1');
    exit;
}

case 'client_addon_add': {
    $id    = (int)($_POST['client_id'] ?? 0);
    $code  = (string)($_POST['code'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    if ($price <= 0) {
        $defaults = ['ai_voice'=>349,'meta_ads'=>199,'yelp_mgmt'=>149,'content_updates'=>99,'multi_location'=>199,'extra_email'=>15,'leads_marketplace_1'=>199];
        $price = (float)($defaults[$code] ?? 0);
    }
    if ($id > 0 && $code !== '' && $price > 0) {
        crm_addAddonToClient($id, $code, $price, (int)$user['id']);
    }
    header('Location: /crm/client.php?id=' . $id . '&saved=1');
    exit;
}

case 'client_addon_remove': {
    $id   = (int)($_POST['client_id'] ?? 0);
    $code = (string)($_POST['code'] ?? '');
    if ($id > 0 && $code !== '') crm_removeAddonFromClient($id, $code, (int)$user['id']);
    header('Location: /crm/client.php?id=' . $id);
    exit;
}

case 'sequence_save': {
    if (!in_array($user['role'] ?? 'sales', ['founder','sales'], true)) { http_response_code(403); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $newId = crm_saveSequence($id, [
        'name'          => $_POST['name'] ?? '',
        'trigger_event' => $_POST['trigger_event'] ?? '',
        'trigger_value' => $_POST['trigger_value'] ?? '',
        'active'        => !empty($_POST['active']),
    ], (int)$user['id']);
    if ($newId > 0) {
        $stepsJson = (string)($_POST['steps_json'] ?? '');
        $parsed = json_decode($stepsJson, true);
        if (is_array($parsed)) crm_replaceSequenceSteps($newId, $parsed);
        header('Location: /crm/sequences.php?edit=' . $newId . '&saved=1');
    } else {
        header('Location: /crm/sequences.php');
    }
    exit;
}

case 'routing_save': {
    if (($user['role'] ?? '') !== 'founder') { http_response_code(403); exit; }
    $id = (int)($_POST['id'] ?? 0);
    crm_saveRoutingRule($id, $_POST);
    header('Location: /crm/routing.php?saved=1');
    exit;
}

case 'proposal_send': {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $lead = $leadId > 0 ? crm_getLead($leadId) : null;
    if (!$lead) { http_response_code(404); header('Location: /crm/'); exit; }
    if (empty($lead['email'])) {
        header('Location: /crm/proposal-send.php?lead_id=' . $leadId . '&err=' . urlencode('Lead has no email — add it first'));
        exit;
    }

    $monthlyFee  = (float)($_POST['monthly_fee']  ?? 799);
    $adBudget    = (float)($_POST['ad_budget']    ?? 0);
    $mgmtPct     = (float)($_POST['mgmt_fee_pct'] ?? 0);
    $addonCodes  = (array)($_POST['addons']       ?? []);

    // Update the lead with the proposed terms (so the existing client_create
    // path picks them up). Also bump status to 'proposal' if not already there.
    crm_updateLead($leadId, [
        'monthly_fee'  => $monthlyFee,
        'ad_budget'    => $adBudget > 0 ? $adBudget : null,
        'mgmt_fee_pct' => $mgmtPct,
    ], (int)$user['id']);
    if ($lead['status'] !== 'proposal' && !in_array($lead['status'], ['won','lost'], true)) {
        crm_updateLead($leadId, ['status' => 'proposal'], (int)$user['id']);
    }

    // Get-or-create the client (idempotent)
    $client = crm_getClientByLead($leadId);
    if (!$client) {
        $clientId = crm_promoteLeadToClient($leadId, (int)$user['id']);
        if (!$clientId) {
            header('Location: /crm/proposal-send.php?lead_id=' . $leadId . '&err=' . urlencode('Failed to create client'));
            exit;
        }
        $client = crm_getClient($clientId);
        // promoteLeadToClient set status=onboarding; demote to onboarding+pending until they pay
        crm_updateClient($clientId, ['status' => 'onboarding', 'payment_status' => 'pending'], (int)$user['id']);
    }

    // Apply selected add-ons (skip duplicates)
    foreach ($addonCodes as $code) {
        $code = (string)$code;
        if (!isset(CRM_STRIPE_ADDON_CATALOG[$code])) continue;
        $price = (float) CRM_STRIPE_ADDON_CATALOG[$code]['monthly'];
        crm_addAddonToClient((int)$client['id'], $code, $price, (int)$user['id']);
    }
    // Refresh client with new addons
    $client = crm_getClient((int)$client['id']);

    // Now reuse the existing client_send_payment_link path by re-injecting POST
    $_POST['client_id'] = (int)$client['id'];
    crm_logActivity($leadId, (int)$user['id'], 'system', 'proposal_sent',
        'Proposal sent · monthly_fee=' . $monthlyFee . ' addons=' . count($addonCodes));
    // Fall through into the next case
}
// fall-through

case 'client_send_payment_link': {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $client = $clientId > 0 ? crm_getClient($clientId) : null;
    if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }

    $r = crm_stripeCreatePaymentLink($client);
    if (!$r['ok']) {
        crm_logClientEvent($clientId, (int)$user['id'], 'note',
            'Payment link creation failed: ' . $r['error']);
        header('Location: /crm/client.php?id=' . $clientId . '&payerr=' . urlencode($r['error']));
        exit;
    }

    crm_updateClient($clientId, [
        'stripe_checkout_url'        => $r['url'],
        'stripe_checkout_session_id' => $r['session_id'],
        'stripe_checkout_sent_at'    => date('Y-m-d H:i:s'),
    ], (int)$user['id']);

    crm_logClientEvent($clientId, (int)$user['id'], 'note',
        "Stripe Checkout session created · \${$r['monthly']}/mo · " . count($r['items']) . ' line items',
        ['session_id' => $r['session_id'], 'url' => $r['url']]);

    // Build the email body inline (don't depend on a template existing)
    $name    = trim((string)($client['business_name'] ?? '')) ?: 'there';
    $monthly = '$' . number_format((float)$r['monthly'], 2);
    $itemsList = '';
    foreach ($r['items'] as $it) {
        $itemsList .= '· ' . $it['name'] . ' — $' . number_format((float)$it['monthly'], 2) . "/mo\n";
    }

    $commitmentEnd = date('F Y', strtotime('+12 months'));
    $monthlyFmt    = '$' . number_format((float)$r['monthly'], 2);
    $subject       = "Activate your Adverton subscription";

    // Reply-To = the salesperson who clicked "Send" (so client replies route back to them)
    $replyTo = crm_resolveUserSender((int)$user['id'])['reply_to'];

    // Create an email_send row so we can track open + click on the CTA button.
    $send = crm_createEmailSend(null, null, (int)$user['id'], $subject, $clientId);
    $trackedUrl = $send['click_token']
        ? crm_redirectUrl($send['click_token'], $r['url'])
        : $r['url'];
    $pixelTag = $send['open_token']
        ? '<img src="' . htmlspecialchars(crm_pixelUrl($send['open_token']), ENT_QUOTES, 'UTF-8') . '" alt="" width="1" height="1" style="display:block;border:0">'
        : '';

    // Items list as HTML rows
    $itemsHtml = '';
    foreach ($r['items'] as $it) {
        $itemsHtml .= '<tr><td style="padding:6px 12px 6px 0;color:#0e0d12;font-size:14px">'
                   . htmlspecialchars((string)$it['name']) . '</td>'
                   . '<td style="padding:6px 0;color:#6b6877;font-size:14px;text-align:right;white-space:nowrap">'
                   . '$' . number_format((float)$it['monthly'], 2) . ' / mo</td></tr>';
    }
    $nameHtml    = htmlspecialchars($name);
    $urlHtml     = htmlspecialchars($trackedUrl);

    $bodyHtml = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f4f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#0e0d12">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f9">
  <tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.05)">
      <tr><td style="padding:32px 32px 8px 32px">
        <div style="font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6d28d9">Adverton</div>
      </td></tr>
      <tr><td style="padding:8px 32px 0 32px">
        <h1 style="margin:0;font-size:22px;line-height:1.3;color:#0e0d12;font-weight:700">Activate your Adverton subscription</h1>
        <p style="margin:14px 0 0;font-size:15px;line-height:1.55;color:#383640">
          Hi {$nameHtml}, here's your secure payment link. Click below to enter your card and activate your account today.
        </p>
      </td></tr>

      <tr><td align="center" style="padding:28px 32px">
        <a href="{$urlHtml}"
           style="display:inline-block;background:#6d28d9;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:14px 32px;border-radius:10px;line-height:1">
          💳 Pay {$monthlyFmt} / month →
        </a>
        <div style="margin-top:10px;font-size:11px;color:#6b6877">Card processing by Stripe · we never see card details</div>
      </td></tr>

      <tr><td style="padding:8px 32px">
        <div style="background:#faf9ff;border:1px solid #e7e4ee;border-radius:10px;padding:16px 18px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b6877;margin-bottom:10px">What's included</div>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            {$itemsHtml}
            <tr><td colspan="2" style="border-top:1px solid #e7e4ee;padding:10px 0 0;font-weight:700;color:#0e0d12;font-size:15px">
              Total <span style="color:#6b6877;font-weight:500;font-size:13px">(billed monthly)</span>
              <span style="float:right">{$monthlyFmt} / mo</span>
            </td></tr>
          </table>
        </div>
      </td></tr>

      <tr><td style="padding:20px 32px 8px">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b6877;margin-bottom:8px">Terms</div>
        <ul style="margin:0;padding:0 0 0 18px;color:#383640;font-size:14px;line-height:1.7">
          <li>12-month commitment, charged monthly through {$commitmentEnd}</li>
          <li>Auto-renews for another 12 months unless either party gives 90-day notice</li>
          <li>Per the Adverton Service Agreement you signed</li>
        </ul>
      </td></tr>

      <tr><td style="padding:24px 32px 32px">
        <p style="margin:0;font-size:14px;color:#383640;line-height:1.55">
          Once payment is confirmed, your account goes active and onboarding kicks off the same day.
        </p>
        <p style="margin:14px 0 0;font-size:14px;color:#383640;line-height:1.55">
          Any questions, just reply to this email.
        </p>
        <p style="margin:18px 0 0;font-size:14px;color:#0e0d12">— The Adverton team</p>
      </td></tr>

      <tr><td style="padding:14px 32px 28px;border-top:1px solid #f0eef5">
        <div style="font-size:11px;color:#a8a3b3;line-height:1.5">
          Adverton · MDS LLC · 16192 Coastal Highway, Lewes, DE 19958 · adverton.net
        </div>
      </td></tr>
    </table>
  </td></tr>
</table>
{$pixelTag}
</body></html>
HTML;

    // Plain-text fallback (auto-derived from html for clients that prefer text)
    $bodyText = "Hi {$name},\n\n"
              . "Activate your Adverton subscription:\n\n"
              . $r['url'] . "\n\n"
              . "What's included ({$monthlyFmt}/mo, billed monthly):\n"
              . $itemsList . "\n"
              . "Terms (per the Adverton Service Agreement you signed):\n"
              . "· 12-month commitment, charged monthly through {$commitmentEnd}\n"
              . "· Auto-renews for another 12 months unless either party gives 90-day notice\n"
              . "· Card processing by Stripe — we never see card details\n\n"
              . "Once payment is confirmed, onboarding kicks off the same day.\n\n"
              . "— The Adverton team";

    $apiKey = crm_config('RESEND_API_KEY');
    if ($apiKey) {
        // Branded sender — transactional email comes from the company, replies go to salesperson
        $payload = [
            'from'     => 'Adverton <hello@adverton.net>',
            'to'       => [$client['primary_email']],
            'subject'  => $subject,
            'html'     => $bodyHtml,
            'text'     => $bodyText,
            'reply_to' => $replyTo,
        ];
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            crm_logClientEvent($clientId, (int)$user['id'], 'note',
                'Payment link email send failed (Resend ' . $code . '): ' . substr((string)$resp, 0, 200));
        } else {
            crm_logClientEvent($clientId, (int)$user['id'], 'note',
                'Payment link email sent to ' . $client['primary_email'] . ' (reply-to: ' . $replyTo . ')');
        }
    } else {
        crm_logClientEvent($clientId, (int)$user['id'], 'note',
            'Payment link created but RESEND_API_KEY not set — copy the URL manually');
    }

    header('Location: /crm/client.php?id=' . $clientId . '&saved=1&paylink=1');
    exit;
}

case 'client_send_card_update': {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $client = $clientId > 0 ? crm_getClient($clientId) : null;
    if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }
    if (empty($client['primary_email'])) {
        header('Location: /crm/client.php?id=' . $clientId . '&payerr=' . urlencode('Client has no primary_email'));
        exit;
    }

    $r = crm_stripeCreateCardUpdateLink($client);
    if (!$r['ok']) {
        crm_logClientEvent($clientId, (int)$user['id'], 'note',
            'Card-update link creation failed: ' . $r['error']);
        header('Location: /crm/client.php?id=' . $clientId . '&payerr=' . urlencode($r['error']));
        exit;
    }

    crm_logClientEvent($clientId, (int)$user['id'], 'note',
        "Stripe card-update link created", ['url' => $r['url']]);

    $apiKey  = crm_config('RESEND_API_KEY');
    $name    = trim((string)($client['business_name'] ?? '')) ?: 'there';
    $nameH   = htmlspecialchars($name);
    $replyTo = crm_resolveUserSender((int)$user['id'])['reply_to'];
    $subject = "Update your card on file · Adverton";

    $send = crm_createEmailSend(null, null, (int)$user['id'], $subject, $clientId);
    $url     = htmlspecialchars($send['click_token'] ? crm_redirectUrl($send['click_token'], $r['url']) : $r['url']);
    $pixel   = $send['open_token'] ? '<img src="' . htmlspecialchars(crm_pixelUrl($send['open_token']), ENT_QUOTES, 'UTF-8') . '" alt="" width="1" height="1" style="display:block;border:0">' : '';

    $bodyHtml = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f4f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#0e0d12">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4f9">
  <tr><td align="center" style="padding:32px 16px">
    <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.05)">
      <tr><td style="padding:32px 32px 8px">
        <div style="font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6d28d9">Adverton</div>
      </td></tr>
      <tr><td style="padding:8px 32px">
        <h1 style="margin:0;font-size:20px;line-height:1.3;color:#0e0d12;font-weight:700">Update your card on file</h1>
        <p style="margin:14px 0 0;font-size:15px;line-height:1.55;color:#383640">
          Hi {$nameH}, use the secure link below to update the card we have on file. This won't change your subscription or billing date — just the payment method.
        </p>
      </td></tr>
      <tr><td align="center" style="padding:24px 32px">
        <a href="{$url}" style="display:inline-block;background:#6d28d9;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:14px 32px;border-radius:10px;line-height:1">
          💳 Update card →
        </a>
        <div style="margin-top:10px;font-size:11px;color:#6b6877">Hosted by Stripe · we never see card details</div>
      </td></tr>
      <tr><td style="padding:8px 32px 32px">
        <p style="margin:0;font-size:14px;color:#383640;line-height:1.55">Any questions, just reply.</p>
        <p style="margin:14px 0 0;font-size:14px;color:#0e0d12">— The Adverton team</p>
      </td></tr>
    </table>
  </td></tr>
</table>
{$pixel}
</body></html>
HTML;

    $bodyText = "Hi {$name},\n\nUpdate your card on file:\n\n{$r['url']}\n\nThis won't change your subscription or billing date — just the payment method.\n\n— The Adverton team";

    if ($apiKey) {
        $payload = [
            'from'     => 'Adverton <hello@adverton.net>',
            'to'       => [$client['primary_email']],
            'subject'  => $subject,
            'html'     => $bodyHtml,
            'text'     => $bodyText,
            'reply_to' => $replyTo,
        ];
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            crm_logClientEvent($clientId, (int)$user['id'], 'note',
                'Card-update email send failed (Resend ' . $code . ')');
        } else {
            crm_logClientEvent($clientId, (int)$user['id'], 'note',
                'Card-update link emailed to ' . $client['primary_email']);
        }
    }

    header('Location: /crm/client.php?id=' . $clientId . '&saved=1&cardlink=1');
    exit;
}

case 'client_cancel_subscription': {
    if (($user['role'] ?? '') !== 'founder') { http_response_code(403); exit; }
    $clientId = (int)($_POST['client_id'] ?? 0);
    $client = $clientId > 0 ? crm_getClient($clientId) : null;
    if (!$client) { http_response_code(404); exit; }
    if (empty($client['stripe_subscription_id'])) {
        header('Location: /crm/client.php?id=' . $clientId . '&payerr=' . urlencode('No active subscription'));
        exit;
    }

    // 12-month commitment guard. Override allowed only for founders who
    // actively pass &override=1 (e.g. mutual termination, fraud).
    $override = !empty($_POST['override']);
    if (!$override && (int)$client['installment_count'] < 12) {
        $missing = 12 - (int)$client['installment_count'];
        header('Location: /crm/client.php?id=' . $clientId
             . '&payerr=' . urlencode("12-month commitment in force ({$missing} installments remaining). Re-submit with override=1 if mutual termination."));
        exit;
    }

    $r = crm_stripeCancelSubscription((string)$client['stripe_subscription_id'], false);
    if (!$r['ok']) {
        crm_logClientEvent($clientId, (int)$user['id'], 'note',
            'Subscription cancel failed: ' . $r['error']);
        header('Location: /crm/client.php?id=' . $clientId . '&payerr=' . urlencode($r['error']));
        exit;
    }

    crm_logClientEvent($clientId, (int)$user['id'], 'status_change',
        'Stripe subscription scheduled to cancel at period end'
        . ($override ? ' (override · pre-12-month)' : ''),
        ['cancel_at_period_end' => true, 'override' => $override]);

    header('Location: /crm/client.php?id=' . $clientId . '&saved=1');
    exit;
}

case 'integration_save': {
    if (($user['role'] ?? '') !== 'founder') { http_response_code(403); exit; }
    $saved = 0; $errors = [];
    foreach (CRM_DB_BACKED_KEYS as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $v = trim((string)$_POST[$k]);

        // Per-key validation. Empty is always allowed (clears the override).
        if ($v !== '') {
            if ($k === 'CRM_FROM_ADDRESS') {
                $ok = preg_match('/<[^@\s]+@[^@\s>]+>/', $v) || filter_var($v, FILTER_VALIDATE_EMAIL);
                if (!$ok) { $errors[] = "{$k}: must be 'Name <email@domain>' or just 'email@domain'"; continue; }
            }
            if ($k === 'CRM_REPLY_TO' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$k}: must be a valid email"; continue;
            }
            if ($k === 'STRIPE_API_KEY' && !preg_match('/^sk_(live|test)_/', $v)) {
                $errors[] = "{$k}: must start with sk_live_ or sk_test_"; continue;
            }
            if ($k === 'NEW_LEAD_WEBHOOK_URL' && !filter_var($v, FILTER_VALIDATE_URL)) {
                $errors[] = "{$k}: must be a valid URL"; continue;
            }
        }
        if (crm_saveSetting($k, $v, (int)$user['id'])) $saved++;
    }
    crm_log("integration_save uid={$user['id']} keys={$saved} errors=" . count($errors));
    $qs = $errors ? '?err=' . urlencode(implode(' · ', $errors)) : '?saved=1';
    header('Location: /crm/integrations.php' . $qs);
    exit;
}

case 'client_delete': {
    if (($user['role'] ?? '') !== 'founder') { http_response_code(403); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); exit; }
    $client = crm_getClient($id);
    if (!$client) { http_response_code(404); exit; }

    $warnings = [];

    // 1. Cancel any active Stripe subscription IMMEDIATELY (deleting would orphan it)
    if (!empty($client['stripe_subscription_id'])) {
        $r = crm_stripeCancelSubscription((string)$client['stripe_subscription_id'], true); // immediate
        if (!$r['ok']) {
            // Hard fail — refuse to delete if we can't kill the sub, otherwise the
            // customer keeps getting billed with no CRM record.
            header('Location: /crm/client.php?id=' . $id . '&payerr=' . urlencode(
                'Could not cancel Stripe subscription: ' . $r['error'] . ' — deletion aborted to prevent orphaned billing'));
            exit;
        }
        $warnings[] = 'Stripe sub ' . substr((string)$client['stripe_subscription_id'], 0, 14) . '… cancelled immediately';
    }

    // 2. Delete the Stripe customer too (so test data doesn't pile up there either)
    if (!empty($client['stripe_customer_id'])) {
        $r = crm_stripeRequest('DELETE', 'customers/' . $client['stripe_customer_id']);
        if ($r['ok']) {
            $warnings[] = 'Stripe customer ' . substr((string)$client['stripe_customer_id'], 0, 14) . '… deleted';
        }
    }

    // 3. Delete the client row (client_events cascade via FK)
    try {
        $stmt = crm_db()->prepare('DELETE FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        crm_log("client_delete uid={$user['id']} cid={$id} biz='" . ($client['business_name'] ?? '') . "' warnings=" . count($warnings));
        $msg = 'Client deleted.' . ($warnings ? ' Also: ' . implode(' · ', $warnings) : '');
        header('Location: /crm/clients.php?saved=1&msg=' . urlencode($msg));
    } catch (Throwable $e) {
        error_log('[client_delete] ' . $e->getMessage());
        header('Location: /crm/client.php?id=' . $id . '&payerr=' . urlencode('DB delete failed: ' . $e->getMessage()));
    }
    exit;
}

case 'routing_delete': {
    if (($user['role'] ?? '') !== 'founder') { http_response_code(403); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) crm_deleteRoutingRule($id);
    header('Location: /crm/routing.php');
    exit;
}

default:
    http_response_code(400);
    exit('unknown mode');
}
