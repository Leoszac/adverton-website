<?php
// PandaDoc API wrapper — create + send contracts from a PandaDoc template.
//
// We ALREADY have pandadoc-webhook.php that receives events when a doc is
// signed; this lib is the OUTBOUND side: trigger the contract creation
// after the client completes the pre-contract form.
//
// Auth: API key from PANDADOC_API_KEY in crm-config.php (PandaDoc dashboard
// → Settings → Integrations → API key). Different from the WEBHOOK_SECRET.
//
// Template ID: PANDADOC_TEMPLATE_ID in crm-config.php (the PandaDoc template
// that has token placeholders for the billing fields we collect).

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';

// Map a clients row to PandaDoc tokens. Each key matches a {{token}} the
// PandaDoc template author defined. Adjust here if the template changes.
function crm_pandadocTokensFromClient(array $client): array {
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

// Create a PandaDoc document from the configured template, populated with
// the client's billing data. Returns ['ok' => bool, 'doc_id' => string,
// 'view_url' => string|null, 'error' => string|null].
//
// Two-step API: (1) POST /documents creates the doc in 'document.uploaded'
// state, (2) we poll until status='document.draft', then (3) POST to /send
// to fire the email to the signer. We only do step 1 here; sending is a
// separate call so the operator can review first if needed.
function crm_pandadocCreateContract(int $clientId): array {
    $apiKey     = crm_config('PANDADOC_API_KEY');
    $templateId = crm_config('PANDADOC_TEMPLATE_ID');
    if (!$apiKey)     return ['ok' => false, 'error' => 'PANDADOC_API_KEY not set'];
    if (!$templateId) return ['ok' => false, 'error' => 'PANDADOC_TEMPLATE_ID not set'];

    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'error' => 'Client not found'];
    $signerEmail = $client['billing_email'] ?: $client['primary_email'] ?: '';
    $signerName  = $client['authorized_signer'] ?: '';
    if (!$signerEmail) return ['ok' => false, 'error' => 'Client has no billing/primary email'];

    // PandaDoc expects "tokens" as a flat list of {name, value} objects
    $tokens = [];
    foreach (crm_pandadocTokensFromClient($client) as $name => $value) {
        $tokens[] = ['name' => $name, 'value' => $value];
    }

    $payload = [
        'name'        => 'Adverton Service Agreement — ' . ($client['business_name'] ?: ('Client #' . $clientId)),
        'template_uuid' => $templateId,
        'recipients'  => [[
            'email'      => $signerEmail,
            'first_name' => trim(explode(' ', $signerName, 2)[0] ?? ''),
            'last_name'  => trim(explode(' ', $signerName, 2)[1] ?? ''),
            'role'       => 'Client',
        ]],
        'tokens'      => $tokens,
        'metadata'    => ['client_id' => (string)$clientId],
    ];

    $ch = curl_init('https://api.pandadoc.com/public/v1/documents');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: API-Key ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'error' => "PandaDoc HTTP {$code}: " . substr((string)($resp ?: $err), 0, 300)];
    }
    $data = json_decode((string)$resp, true) ?: [];
    $docId = (string)($data['id'] ?? '');
    if (!$docId) return ['ok' => false, 'error' => 'PandaDoc returned no doc id'];

    // Persist the doc id on the client for the webhook handler to match later.
    try {
        $stmt = crm_db()->prepare('UPDATE clients SET pandadoc_doc_id = ? WHERE id = ?');
        $stmt->execute([$docId, $clientId]);
    } catch (Throwable $e) {
        error_log('[crm_pandadocCreateContract persist doc_id] ' . $e->getMessage());
    }

    return [
        'ok'       => true,
        'doc_id'   => $docId,
        'view_url' => "https://app.pandadoc.com/a/#/documents/{$docId}",
        'error'    => null,
    ];
}

// Send the document to the signer (after the doc has reached 'document.draft'
// state — usually a few seconds after create). Trying immediately may 400
// with "document not ready"; ops can retry from the client.php UI.
function crm_pandadocSendContract(string $docId, ?string $message = null): array {
    $apiKey = crm_config('PANDADOC_API_KEY');
    if (!$apiKey) return ['ok' => false, 'error' => 'PANDADOC_API_KEY not set'];

    $payload = [
        'silent'  => false,
        'subject' => 'Your Adverton service agreement — please review and sign',
        'message' => $message ?: 'Hi — your service agreement is ready. Click below to review and sign. Reach out if anything looks off.',
    ];
    $ch = curl_init("https://api.pandadoc.com/public/v1/documents/{$docId}/send");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: API-Key ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'error' => "PandaDoc HTTP {$code}: " . substr((string)($resp ?: $err), 0, 300)];
    }
    return ['ok' => true, 'error' => null];
}
