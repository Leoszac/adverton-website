<?php
// POST handler for /pre-contract.php form.
// Validates the magic token, persists the billing data into clients,
// invalidates the token, kicks off Stripe Checkout creation with the
// click-wrap T&C consent, and redirects to a "payment link on its way"
// thank-you page.

declare(strict_types=1);

define('CRM_ENTRY', 1);
require_once __DIR__ . '/crm/lib/db.php';
require_once __DIR__ . '/crm/lib/leads.php';
require_once __DIR__ . '/crm/lib/clients.php';
require_once __DIR__ . '/crm/lib/magic-tokens.php';
require_once __DIR__ . '/crm/lib/activities.php';
require_once __DIR__ . '/crm/lib/stripe.php';
require_once __DIR__ . '/crm/lib/email_track.php';

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
    'Pre-contract form completed; client #' . $clientId . ' created/updated; Stripe checkout link triggered');

// Build the Stripe Checkout session with required ToS consent and email
// the link to the client. Click-wrap (consent_collection[terms_of_service]
// = required) makes payment + checkbox legally binding for a sub-$1k SaaS
// agreement — replaces a separate eSignature tool until Adverton scales.
//
// Best-effort: if Stripe or Resend isn't configured, the client save still
// succeeds and the operator can resend the link manually from client.php.
$client = crm_getClient($clientId);
if ($client) {
    $pl = crm_stripeCreatePaymentLink($client);
    if (!$pl['ok']) {
        error_log('[pre-contract-submit stripe] ' . ($pl['error'] ?? 'unknown'));
        crm_logActivity($leadId, null, 'system', 'stripe_link_failed',
            'Stripe payment link create failed: ' . ($pl['error'] ?? 'unknown'));
    } else {
        $url = (string)$pl['url'];
        $monthly = number_format((float)$pl['monthly'], 2);
        crm_logActivity($leadId, null, 'system', 'stripe_link_created',
            "Stripe payment link created · monthly \${$monthly}", ['url' => $url]);

        $subject = 'Your Adverton service agreement — review + activate';
        $bodyHtml =
            '<p>Hi ' . htmlspecialchars((string)($client['authorized_signer'] ?: $client['business_name']), ENT_QUOTES) . ',</p>'
          . '<p>Your Adverton subscription is ready to activate. The link below opens a secure Stripe checkout where you can:</p>'
          . '<ul>'
          . '<li>Review the <a href="https://adverton.net/legal/service-agreement.html">Service Agreement</a> (12-month term, $799/mo + add-ons)</li>'
          . '<li>Confirm acceptance with one click</li>'
          . '<li>Enter your payment details</li>'
          . '</ul>'
          . '<p style="margin:24px 0"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" '
          . 'style="background:#6d28d9;color:#fff;padding:12px 22px;text-decoration:none;border-radius:8px;font-weight:600">'
          . 'Review and activate — $' . $monthly . '/mo</a></p>'
          . '<p>By clicking the agreement checkbox + paying, you’re entering into a binding 12-month service agreement with Adverton. The checkout records your acceptance with timestamp.</p>'
          . '<p>Reply to this email if anything looks off.</p>'
          . '<p>— Adverton</p>';

        $leadForEmail = $lead;
        $leadForEmail['email'] = $client['billing_email'] ?: $client['primary_email'] ?: ($lead['email'] ?? '');

        // Match the sender to whoever clicked "Send pre-contract" so the
        // invitation email and the follow-up Stripe-checkout email come
        // from the same person ("Leo from Adverton" instead of one being
        // "Leo" and the next one falling back to the global default
        // "Adverton <leandro@>"). Looks up the most recent
        // `pre_contract_sent` activity actor.
        $senderUserId = null;
        try {
            $stmt = crm_db()->prepare(
                "SELECT user_id FROM lead_activities
                 WHERE lead_id = ? AND type = 'system' AND disposition = 'pre_contract_sent'
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([$leadId]);
            $row = $stmt->fetchColumn();
            if ($row) $senderUserId = (int)$row;
        } catch (Throwable $e) {
            error_log('[pre-contract-submit sender lookup] ' . $e->getMessage());
        }

        $send = crm_sendTrackedEmail($leadId, $leadForEmail, null, $senderUserId, $subject, $bodyHtml);
        if (!$send['ok']) {
            error_log('[pre-contract-submit email] ' . ($send['error'] ?? 'unknown'));
            crm_logActivity($leadId, null, 'system', 'stripe_link_email_failed',
                'Stripe link generated but email send failed: ' . ($send['error'] ?? 'unknown'));
        } else {
            crm_logActivity($leadId, null, 'email', 'stripe_link_emailed',
                'Stripe checkout link emailed to ' . $leadForEmail['email']);
        }
    }
}

// Done — redirect to a thank-you. Reuses the audit thank-you page since the
// message is the same: "we've got it, check your email".
header('Location: /pre-contract-thank-you.html');
exit;
