<?php
// ⏸ DORMANT — not invoked anywhere right now. The pre-contract flow uses
// Stripe Checkout + click-wrap T&C (see crm/lib/stripe.php and
// pre-contract-submit.php). Activate this lib by:
//   1. Sign up for OpenSign Paid (~\$9.99/mo) OR self-host
//   2. Add OPENSIGN_API_KEY/TEMPLATE_ID/WEBHOOK_SECRET/BASE_URL to the
//      whitelist in crm/lib/settings.php
//   3. Restore the OpenSign section in /crm/integrations.php
//   4. Swap the Stripe-link block in pre-contract-submit.php back to a
//      crm_opensignCreateContract($clientId) call.
//
// OpenSign API wrapper — create + send contracts from an OpenSign template.
//
// Pairs with crm/opensign-webhook.php (inbound: receives "signed" events
// and bumps the matching client to contract_signed).
//
// Auth: API key from OPENSIGN_API_KEY (managed via /crm/integrations.php).
// Template ID: OPENSIGN_TEMPLATE_ID (the UUID of the template that has
// the 15 placeholders for the billing fields we collect).
//
// Endpoint reference: https://docs.opensignlabs.com/  (verify against the
// current docs — endpoint paths can shift between minor versions).

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';

// OpenSign Cloud uses *.opensignlabs.com; self-hosted instances let you
// point at your own host (we'll add OPENSIGN_BASE_URL the day you self-host).
function crm_opensignBaseUrl(): string {
    $custom = (string)crm_config('OPENSIGN_BASE_URL');
    return $custom !== '' ? rtrim($custom, '/') : 'https://api.opensignlabs.com';
}

// Map a clients row to template placeholders. The 15 keys must match
// the placeholders the operator put in the OpenSign template.
function crm_opensignFieldsFromClient(array $client): array {
    return [
        'Client.LegalName'    => (string)($client['legal_entity_name']   ?? $client['business_name'] ?? ''),
        'Client.BusinessName' => (string)($client['business_name']       ?? ''),
        'Client.Trade'        => (string)($client['trade']               ?? ''),
        'Client.SignerName'   => (string)($client['authorized_signer']   ?? ''),
        'Client.SignerRole'   => (string)($client['signer_role']         ?? ''),
        'Client.Email'        => (string)($client['billing_email']       ?? $client['primary_email'] ?? ''),
        'Client.Phone'        => (string)($client['primary_phone']       ?? ''),
        'Client.Address'      => (string)($client['billing_address']     ?? ''),
        'Client.City'         => (string)($client['billing_city']        ?? ''),
        'Client.State'        => (string)($client['billing_state']       ?? ''),
        'Client.Zip'          => (string)($client['billing_zip']         ?? ''),
        'Client.TaxId'        => (string)($client['tax_id']              ?? ''),
        'Contract.MonthlyFee' => '$' . number_format((float)($client['monthly_fee'] ?? 799), 2),
        'Contract.StartDate'  => (string)($client['contract_start_at']   ?? date('Y-m-d')),
        'Contract.EndDate'    => (string)($client['contract_end_at']     ?? date('Y-m-d', strtotime('+1 year'))),
    ];
}

// Create + send a document from the OpenSign template, populated with
// the client's billing data. Returns ['ok' => bool, 'doc_id' => string,
// 'view_url' => string|null, 'error' => string|null].
//
// OpenSign's createdocument-from-template endpoint creates AND emails the
// signer in one call (different from PandaDoc's two-step flow).
function crm_opensignCreateContract(int $clientId): array {
    $apiKey     = crm_config('OPENSIGN_API_KEY');
    $templateId = crm_config('OPENSIGN_TEMPLATE_ID');
    if (!$apiKey)     return ['ok' => false, 'error' => 'OPENSIGN_API_KEY not set'];
    if (!$templateId) return ['ok' => false, 'error' => 'OPENSIGN_TEMPLATE_ID not set'];

    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'error' => 'Client not found'];
    $signerEmail = $client['billing_email'] ?: $client['primary_email'] ?: '';
    $signerName  = $client['authorized_signer'] ?: '';
    if (!$signerEmail) return ['ok' => false, 'error' => 'Client has no billing/primary email'];

    // Flatten placeholder map into OpenSign's expected shape.
    $fields = [];
    foreach (crm_opensignFieldsFromClient($client) as $name => $value) {
        $fields[] = ['name' => $name, 'value' => $value];
    }

    $payload = [
        'templateId' => $templateId,
        'title'      => 'Adverton Service Agreement — ' . ($client['business_name'] ?: ('Client #' . $clientId)),
        'message'    => 'Hi — your service agreement is ready. Click below to review and sign. Reach out if anything looks off.',
        'signers'    => [[
            'email' => $signerEmail,
            'name'  => $signerName ?: $signerEmail,
            'role'  => 'Client',
        ]],
        'fields'     => $fields,
        'metadata'   => ['client_id' => (string)$clientId],
        'sendInOrder' => false,
    ];

    $url = crm_opensignBaseUrl() . '/api/v1/createdocumentfromtemplate';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-token: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'error' => "OpenSign HTTP {$code}: " . substr((string)($resp ?: $err), 0, 300)];
    }
    $data = json_decode((string)$resp, true) ?: [];
    // OpenSign returns either {objectId: "..."} or {id: "..."} depending on endpoint version.
    $docId = (string)($data['objectId'] ?? $data['id'] ?? $data['documentId'] ?? '');
    if (!$docId) return ['ok' => false, 'error' => 'OpenSign returned no doc id; raw: ' . substr((string)$resp, 0, 200)];

    // Persist on the client so the webhook can match later.
    try {
        $stmt = crm_db()->prepare(
            'UPDATE clients SET sign_provider = ?, sign_doc_id = ? WHERE id = ?'
        );
        $stmt->execute(['opensign', $docId, $clientId]);
    } catch (Throwable $e) {
        error_log('[crm_opensignCreateContract persist sign_doc_id] ' . $e->getMessage());
    }

    $viewUrl = (string)($data['signurl'] ?? $data['url'] ?? '');
    return [
        'ok'       => true,
        'doc_id'   => $docId,
        'view_url' => $viewUrl ?: null,
        'error'    => null,
    ];
}
