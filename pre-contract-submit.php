<?php
// POST handler for /pre-contract.php form.
// Validates the magic token, persists the billing data into clients,
// invalidates the token, kicks off the PandaDoc contract creation, and
// redirects the client to a "thank you, contract on its way" page.

declare(strict_types=1);

define('CRM_ENTRY', 1);
require_once __DIR__ . '/crm/lib/db.php';
require_once __DIR__ . '/crm/lib/leads.php';
require_once __DIR__ . '/crm/lib/clients.php';
require_once __DIR__ . '/crm/lib/magic-tokens.php';
require_once __DIR__ . '/crm/lib/activities.php';
require_once __DIR__ . '/crm/lib/opensign.php';

function preContractFail(string $token, string $userMsg, int $status = 400): void {
    http_response_code($status);
    header('Location: /pre-contract.php?t=' . urlencode($token) . '&err=' . urlencode($userMsg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$token = trim((string)($_POST['t'] ?? ''));
$resolved = crm_resolveMagicToken($token);
if (!$resolved || $resolved['kind'] !== 'lead') {
    preContractFail($token, 'Your link expired. We will resend it shortly.', 410);
}
$leadId = $resolved['id'];
$lead = crm_getLead($leadId);
if (!$lead) {
    preContractFail($token, 'We could not find your record. Please reach out.', 404);
}

// Required-field validation
$required = [
    'legal_entity_name', 'authorized_signer', 'billing_email', 'primary_phone',
    'billing_address',  'billing_city',     'billing_state',  'billing_zip',
];
foreach ($required as $f) {
    if (trim((string)($_POST[$f] ?? '')) === '') {
        preContractFail($token, 'Please fill in: ' . str_replace('_', ' ', $f));
    }
}

$billingEmail = trim((string)$_POST['billing_email']);
if (!filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
    preContractFail($token, 'Billing email looks invalid.');
}

// Build client payload. crm_createClient (lib/clients.php) accepts a wide
// $data array and ignores keys it doesn't know — pass extras safely.
$now = date('Y-m-d');
$data = [
    'lead_id'              => $leadId,
    'business_name'        => $lead['business_name'] ?: trim((string)$_POST['legal_entity_name']),
    'trade'                => $lead['trade'] ?: null,
    'primary_email'        => $lead['email'] ?: $billingEmail,
    'primary_phone'        => trim((string)$_POST['primary_phone']),
    'contract_start_at'    => $now,
    'contract_end_at'      => date('Y-m-d', strtotime('+1 year')),
    'monthly_fee'          => 799.00,
    'status'               => 'onboarding',
    'payment_status'       => 'pending',
    // Billing fields (schema-v11)
    'legal_entity_name'    => trim((string)$_POST['legal_entity_name']),
    'billing_email'        => $billingEmail,
    'billing_address'      => trim((string)$_POST['billing_address']),
    'billing_city'         => trim((string)$_POST['billing_city']),
    'billing_state'        => trim((string)$_POST['billing_state']),
    'billing_zip'          => trim((string)$_POST['billing_zip']),
    'tax_id'               => trim((string)($_POST['tax_id'] ?? '')) ?: null,
    'authorized_signer'    => trim((string)$_POST['authorized_signer']),
    'signer_role'          => trim((string)($_POST['signer_role'] ?? '')) ?: null,
    'pre_contract_completed_at' => date('Y-m-d H:i:s'),
];

// crm_createClient is idempotent if a client already exists for this lead;
// we want to UPDATE that one rather than create a duplicate.
$existing = crm_getClientByLead($leadId);
$db = crm_db();
$db->beginTransaction();
try {
    if ($existing) {
        crm_updateClient((int)$existing['id'], $data, null);
        $clientId = (int)$existing['id'];
    } else {
        $clientId = crm_createClient($data, null);
    }
    if (!$clientId) throw new RuntimeException('client persist returned 0');
    crm_invalidateMagicToken('lead', $leadId);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[pre-contract-submit persist] ' . $e->getMessage());
    preContractFail($token, 'We hit a snag saving your info. Try again in a minute.', 500);
}

// Audit trail on the lead — sales VA can see what happened
crm_logActivity($leadId, null, 'system', 'pre_contract_completed',
    'Pre-contract form completed; client #' . $clientId . ' created/updated; PandaDoc contract triggered');

// Kick off the OpenSign contract (creates AND emails signer in one call).
// If it fails, we DON'T rollback the client save — the operator can retry
// from /crm/client.php manually.
$os = crm_opensignCreateContract($clientId);
if (!$os['ok']) {
    error_log('[pre-contract-submit opensign] ' . ($os['error'] ?? 'unknown'));
    crm_logActivity($leadId, null, 'system', 'opensign_create_failed',
        'OpenSign contract create failed: ' . ($os['error'] ?? 'unknown'));
} else {
    crm_logActivity($leadId, null, 'system', 'opensign_doc_created',
        'OpenSign contract sent: ' . ($os['view_url'] ?? $os['doc_id']));
}

// Done — redirect to a thank-you. Reuses the audit thank-you page since the
// message is the same: "we've got it, check your email".
header('Location: /pre-contract-thank-you.html');
exit;
