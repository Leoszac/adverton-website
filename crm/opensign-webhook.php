<?php
// ⏸ DORMANT — Adverton currently uses Stripe click-wrap T&C, not OpenSign.
// This file stays in the repo for the day the user upgrades to OpenSign
// Paid. Until OPENSIGN_WEBHOOK_SECRET is set, requests get 503'd; URL is
// not advertised anywhere.
//
// OpenSign webhook — receives document.signed events and bumps the matching
// client to contract_signed. Paired with crm/lib/opensign.php (outbound).
//
// Auth: shared token via ?token=... matching OPENSIGN_WEBHOOK_SECRET. The
// receiver also tolerates the token in an X-OpenSign-Signature header for
// future HMAC support — for now it's a shared secret (OpenSign's free tier
// signs simply, like PandaDoc).
//
// Event matched: anything containing "completed" or "signed" — different
// OpenSign versions emit slightly different event names. We match by the
// document's metadata.client_id we set when creating it (lib/opensign.php).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';

header('Content-Type: text/plain');

$secret = crm_config('OPENSIGN_WEBHOOK_SECRET');
if (!$secret) {
    http_response_code(503);
    error_log('[opensign-webhook] OPENSIGN_WEBHOOK_SECRET not configured');
    exit("Webhook receiver not configured.\n");
}

$got = $_GET['token'] ?? ($_SERVER['HTTP_X_OPENSIGN_SIGNATURE'] ?? '');
if (!hash_equals((string)$secret, (string)$got)) {
    http_response_code(403); echo "bad token"; exit;
}

$payload = file_get_contents('php://input');
$body = json_decode((string)$payload, true);
if (!is_array($body)) { http_response_code(400); echo "bad payload"; exit; }

// OpenSign typically sends one event per call; tolerate either shape.
$events = isset($body['event']) || isset($body['eventType']) ? [$body] : ($body['events'] ?? $body);
if (!is_array($events)) { http_response_code(400); echo "bad payload shape"; exit; }

$processed = 0;
foreach ($events as $ev) {
    if (!is_array($ev)) continue;
    $type = strtolower((string)($ev['event'] ?? $ev['eventType'] ?? ''));
    if (!str_contains($type, 'sign') && !str_contains($type, 'complet')) continue;

    $doc = $ev['data'] ?? $ev['document'] ?? $ev;
    $docId = (string)($doc['objectId'] ?? $doc['id'] ?? $doc['documentId'] ?? '');
    $clientIdMeta = (int)($doc['metadata']['client_id'] ?? 0);

    // Resolve the client either by metadata (preferred) or by stored doc id
    $client = null;
    if ($clientIdMeta > 0) {
        $client = crm_getClient($clientIdMeta);
    }
    if (!$client && $docId !== '') {
        try {
            $stmt = crm_db()->prepare('SELECT * FROM clients WHERE sign_doc_id = ? LIMIT 1');
            $stmt->execute([$docId]);
            $client = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[opensign-webhook lookup] ' . $e->getMessage());
        }
    }
    if (!$client) {
        error_log("[opensign-webhook] no matching client for docId={$docId} meta_client_id={$clientIdMeta}");
        continue;
    }

    $clientId = (int)$client['id'];

    // Mark the contract as signed
    try {
        crm_updateClient($clientId, ['contract_signed_at' => date('Y-m-d H:i:s')], null);
    } catch (Throwable $e) {
        error_log('[opensign-webhook update] ' . $e->getMessage());
    }

    // Audit trail on the originating lead (if present)
    if (!empty($client['lead_id'])) {
        crm_logActivity((int)$client['lead_id'], null, 'system', 'contract_signed',
            'Contract signed via OpenSign (docId ' . $docId . ')');
    }

    // Operator task: kick off Stripe checkout link
    crm_createTask([
        'client_id'  => $clientId,
        'title'      => 'Send Stripe checkout to ' . ($client['business_name'] ?: 'client #' . $clientId),
        'kind'       => 'onboarding',
        'priority'   => 'high',
        'due_at'     => date('Y-m-d', strtotime('+1 day')),
    ]);

    $processed++;
}

echo "ok processed={$processed}\n";
