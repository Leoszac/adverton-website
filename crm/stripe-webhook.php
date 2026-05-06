<?php
// Stripe webhook receiver. Updates client payment_status / installment_count
// and creates urgent tasks on payment_failed or subscription_deleted.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/commissions.php';

header('Content-Type: text/plain');

$secret = crm_config('STRIPE_WEBHOOK_SECRET');
if (!$secret) { http_response_code(500); echo "STRIPE_WEBHOOK_SECRET not configured"; exit; }

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripeVerifySignature((string)$payload, (string)$sigHeader, (string)$secret)) {
    http_response_code(400); echo "bad signature"; exit;
}

$event = json_decode((string)$payload, true);
$type = (string)($event['type'] ?? '');
$obj  = $event['data']['object'] ?? [];

switch ($type) {

case 'checkout.session.completed': {
    // Subscription mode session — link the resulting subscription_id back
    // to the client and flip status to active.
    $sessionId = $obj['id'] ?? '';
    $subId     = $obj['subscription'] ?? '';
    $custId    = $obj['customer']     ?? '';
    $clientIdMeta = $obj['metadata']['client_id'] ?? null;

    $client = null;
    if ($clientIdMeta) $client = crm_getClient((int)$clientIdMeta);
    if (!$client && $custId) {
        // Fallback: lookup by customer id
        try {
            $stmt = crm_db()->prepare('SELECT * FROM clients WHERE stripe_customer_id = ? LIMIT 1');
            $stmt->execute([$custId]);
            $client = $stmt->fetch() ?: null;
        } catch (Throwable $e) {}
    }
    if (!$client) break;

    $patch = [
        'status'                 => 'active',
        'payment_status'         => 'current',
        'stripe_customer_id'     => $custId ?: $client['stripe_customer_id'],
        'stripe_subscription_id' => $subId,
    ];
    crm_updateClient((int)$client['id'], $patch, null);
    crm_logClientEvent((int)$client['id'], null, 'payment_succeeded',
        "Checkout completed · sub {$subId}", ['session_id' => $sessionId]);
    break;
}

case 'invoice.payment_succeeded': {
    $subId = $obj['subscription'] ?? '';
    if (!$subId) break;
    $client = crm_getClientByStripeSub($subId);
    if (!$client) break;
    $newCount = min(12, (int)$client['installment_count'] + 1);
    crm_updateClient((int)$client['id'], [
        'installment_count' => $newCount,
        'payment_status'    => 'current',
    ], null);
    crm_logClientEvent((int)$client['id'], null, 'payment_succeeded',
        'Installment ' . $newCount . '/12 paid · invoice ' . ($obj['id'] ?? '?'),
        ['amount' => ($obj['amount_paid'] ?? 0) / 100]);
    break;
}

case 'invoice.payment_failed': {
    $subId = $obj['subscription'] ?? '';
    if (!$subId) break;
    $client = crm_getClientByStripeSub($subId);
    if (!$client) break;
    crm_updateClient((int)$client['id'], [
        'payment_status' => 'past_due',
    ], null);
    crm_logClientEvent((int)$client['id'], null, 'payment_failed',
        'Payment failed · invoice ' . ($obj['id'] ?? '?'));
    if ($client['account_manager_id']) {
        crm_createTask([
            'lead_id'     => $client['lead_id'],
            'assigned_to' => (int)$client['account_manager_id'],
            'title'       => '🚨 Payment failed — call ' . ($client['business_name'] ?: ('client #' . $client['id'])),
            'due_at'      => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);
    }
    break;
}

case 'customer.subscription.deleted': {
    $subId = $obj['id'] ?? '';
    if (!$subId) break;
    $client = crm_getClientByStripeSub($subId);
    if (!$client) break;
    crm_updateClient((int)$client['id'], [
        'status'         => 'cancelled',
        'payment_status' => 'cancelled',
    ], null);
    crm_logClientEvent((int)$client['id'], null, 'status_change',
        'Stripe subscription cancelled');
    // Commissions disabled per founder decision — no clawback
    break;
}

case 'customer.subscription.updated': {
    $subId = $obj['id'] ?? '';
    if (!$subId) break;
    $client = crm_getClientByStripeSub($subId);
    if (!$client) break;
    crm_logClientEvent((int)$client['id'], null, 'subscription_changed',
        'Subscription updated', $obj);
    break;
}

default:
    // Ignore unrecognized events but always 200
    break;
}

http_response_code(200);
echo "ok";

// ====================================================================

function stripeVerifySignature(string $payload, string $header, string $secret): bool {
    if ($header === '') return false;
    $parts = [];
    foreach (explode(',', $header) as $p) {
        [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
        $parts[$k][] = $v;
    }
    $t = $parts['t'][0] ?? '';
    $sigs = $parts['v1'] ?? [];
    if (!$t || !$sigs) return false;
    // Reject anything older than 5 min
    if (abs(time() - (int)$t) > 300) return false;
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
