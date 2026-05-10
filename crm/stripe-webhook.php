<?php
// Stripe webhook receiver. Updates client payment_status / installment_count
// and creates urgent tasks on payment_failed or subscription_deleted.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/commissions.php';
require_once __DIR__ . '/lib/magic-tokens.php';
require_once __DIR__ . '/lib/email_track.php';
require_once __DIR__ . '/lib/leads.php';

header('Content-Type: text/plain');

$secret = crm_config('STRIPE_WEBHOOK_SECRET');
if (!$secret) {
    http_response_code(503);
    error_log('[stripe-webhook] STRIPE_WEBHOOK_SECRET not configured');
    exit("Webhook receiver not configured.\n");
}

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

    // Click-wrap acceptance: capture the consent record from the session.
    // consent.terms_of_service = "accepted" iff the user checked the
    // required ToS box in Checkout. This + payment = binding contract
    // for sub-$1k SaaS in US (replaces a separate eSignature tool).
    $consent = $obj['consent']['terms_of_service'] ?? '';
    if ($consent === 'accepted') {
        $patch['contract_signed_at'] = date('Y-m-d H:i:s');
        $patch['tos_consented_at']   = date('Y-m-d H:i:s');
        // Stripe puts the customer's IP on the session as customer_details.address
        // is post-billing; the IP itself comes through on the PaymentIntent.
        // For audit-trail purposes the session_id + Stripe-side log is enough.
        $patch['tos_consented_ip']   = (string)($obj['customer_details']['ip'] ?? '');
    }

    crm_updateClient((int)$client['id'], $patch, null);
    crm_logClientEvent((int)$client['id'], null, 'payment_succeeded',
        "Checkout completed · sub {$subId}" . ($consent === 'accepted' ? ' · ToS accepted' : ''),
        ['session_id' => $sessionId, 'consent_tos' => $consent]);

    // Send the welcome + kickoff email. Resend handles delivery; if it fails
    // (e.g. RESEND_API_KEY missing), we just log — the client status is
    // already correct and the operator can manually nudge from /crm/client.php.
    try {
        $clientId = (int)$client['id'];
        $clientFresh = crm_getClient($clientId) ?: $client;
        $kickoffToken = crm_setClientMagicToken($clientId, 60);
        $kickoffUrl   = 'https://adverton.net/kickoff?t=' . urlencode($kickoffToken);

        $signer = trim((string)($clientFresh['authorized_signer']
                            ?? $clientFresh['business_name'] ?? 'there')) ?: 'there';
        $business = trim((string)($clientFresh['business_name'] ?? ''));
        $billingEmail = (string)($clientFresh['billing_email']
                              ?? $clientFresh['primary_email'] ?? '');
        if (!$billingEmail) throw new RuntimeException('Client has no billing/primary email');

        // Match sender to the operator who originally sent the pre-contract,
        // so welcome email comes from the same identity ("Leo from Adverton")
        // as the rest of the chain.
        $senderUserId = null;
        try {
            $stmt = crm_db()->prepare(
                "SELECT user_id FROM lead_activities
                 WHERE lead_id = ? AND type = 'system' AND disposition = 'pre_contract_sent'
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([(int)($clientFresh['lead_id'] ?? 0)]);
            $row = $stmt->fetchColumn();
            if ($row) $senderUserId = (int)$row;
        } catch (Throwable $e) {
            error_log('[stripe-webhook welcome sender lookup] ' . $e->getMessage());
        }

        $bodyHtml =
            '<p>Hi ' . htmlspecialchars($signer, ENT_QUOTES) . ',</p>'
          . '<p>Welcome to Adverton — your subscription is active and your '
          . 'Service Agreement is on file. Here\'s what happens next:</p>'
          . '<ol style="line-height:1.7">'
          . '<li><strong>Kickoff intake</strong> — fill out 8 short questions about '
          . htmlspecialchars($business ?: 'your business', ENT_QUOTES)
          . ' so we can tailor the website + Google profile copy. Takes ~10 min.</li>'
          . '<li><strong>We build</strong> — site goes live on a preview URL within 5 business days.</li>'
          . '<li><strong>You review</strong> — request changes from your phone, we tweak, then we deploy to your domain.</li>'
          . '<li><strong>Billing</strong> — Stripe handles renewals automatically; you get receipts in your inbox.</li>'
          . '</ol>'
          . '<p style="margin:28px 0">'
          . '<a href="' . htmlspecialchars($kickoffUrl, ENT_QUOTES) . '" '
          . 'style="display:inline-block;background:#6d28d9;color:#fff;padding:12px 24px;'
          . 'border-radius:8px;text-decoration:none;font-weight:600">Start the kickoff intake →</a>'
          . '</p>'
          . '<p style="font-size:13px;color:#6b6877">Prefer a 30-min call instead? '
          . '<a href="https://calendly.com/meet-adverton/kickoff">Book your kickoff call</a>. Either path works.</p>'
          . '<p style="font-size:13px;color:#6b6877">Receipts + future invoices come straight from Stripe.</p>'
          . '<p style="font-size:13px;color:#6b6877">— Adverton</p>';

        $leadForEmail = ['email' => $billingEmail];
        $r = crm_sendTrackedEmail(
            (int)($clientFresh['lead_id'] ?? 0), $leadForEmail,
            null, $senderUserId,
            'Welcome to Adverton — let\'s start the build',
            $bodyHtml
        );
        if (!$r['ok']) {
            error_log('[stripe-webhook welcome email] ' . ($r['error'] ?? 'unknown'));
        } else {
            crm_logClientEvent($clientId, null, 'note',
                "Welcome + kickoff email sent to {$billingEmail}",
                ['kickoff_token' => substr($kickoffToken, 0, 8) . '…']);
        }
    } catch (Throwable $e) {
        error_log('[stripe-webhook welcome] ' . $e->getMessage());
    }

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
