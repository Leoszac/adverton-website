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
    $leadId = crm_insertLead([
        'source'        => 'manual',
        'source_page'   => 'manual entry by ' . ($user['username'] ?? ''),
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
    $subject = "Set up your Adverton subscription · {$monthly}/mo";
    $body = "Hi {$name},\n\n"
          . "Here's your secure payment link to activate your Adverton subscription:\n\n"
          . $r['url'] . "\n\n"
          . "What's included (\${$r['monthly']}/mo, billed monthly):\n"
          . $itemsList . "\n"
          . "Terms (per the Service Agreement you signed):\n"
          . "· 12-month commitment, charged monthly until {$commitmentEnd}\n"
          . "· Auto-renews for another 12 months unless either party gives 90-day notice\n"
          . "· Card processing handled by Stripe — we never see card details\n\n"
          . "Once payment is confirmed, your account goes active and onboarding kicks off the same day.\n\n"
          . "Any questions, just reply.\n\n"
          . "— Leandro\nAdverton";

    // Synthesize a "lead" payload for crm_sendTrackedEmail (it wants email + first_name)
    $leadProxy = [
        'id'         => 0, // not a real lead
        'email'      => $client['primary_email'],
        'first_name' => $client['business_name'] ?? '',
        'last_name'  => '',
    ];
    // crm_sendTrackedEmail logs to lead_activities/email_sends — those FK to leads, so
    // we can't reuse it for a client-only email. Instead, use Resend directly.
    $apiKey = crm_config('RESEND_API_KEY');
    if ($apiKey) {
        $sender = crm_resolveUserSender((int)$user['id']);
        $payload = [
            'from'     => $sender['from'],
            'to'       => [$client['primary_email']],
            'subject'  => $subject,
            'text'     => $body,
            'reply_to' => $sender['reply_to'],
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
                'Payment link email send failed (Resend ' . $code . ')');
        } else {
            crm_logClientEvent($clientId, (int)$user['id'], 'note',
                'Payment link email sent to ' . $client['primary_email']);
        }
    } else {
        crm_logClientEvent($clientId, (int)$user['id'], 'note',
            'Payment link created but RESEND_API_KEY not set — copy the URL manually');
    }

    header('Location: /crm/client.php?id=' . $clientId . '&saved=1&paylink=1');
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
