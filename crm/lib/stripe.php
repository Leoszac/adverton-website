<?php
// Stripe API integration — minimal HTTP wrapper + payment-link orchestration.
//
// Requires STRIPE_API_KEY (sk_live_... or sk_test_...) configured in
// /crm/integrations.php. No Composer dependency — direct HTTPS calls.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';

// Adverton catalog. Mirrors operations/Sales_Process_Playbook.md pricing.
// `code` matches the values used in client.addons[].code.
const CRM_STRIPE_BASE_PLAN = [
    'code' => 'base',
    'name' => 'Adverton',
    'monthly' => 799.00,
];
const CRM_STRIPE_ADDON_CATALOG = [
    'ai_voice'             => ['name' => 'AI Voice receptionist',         'monthly' => 349.00],
    'meta_ads'             => ['name' => 'Meta Ads management (IG+FB)',   'monthly' => 199.00],
    'yelp_mgmt'            => ['name' => 'Yelp setup + management',       'monthly' => 149.00],
    'content_updates'      => ['name' => 'Monthly content updates',       'monthly' => 99.00],
    'multi_location'       => ['name' => 'Multi-location (per location)', 'monthly' => 199.00],
    'extra_email'          => ['name' => 'Extra branded email mailbox',   'monthly' => 15.00],
    'leads_marketplace_1'  => ['name' => 'Lead marketplace mgmt — 1 platform',  'monthly' => 199.00],
    'leads_marketplace_2'  => ['name' => 'Lead marketplace mgmt — 2 platforms', 'monthly' => 349.00],
    'leads_marketplace_3'  => ['name' => 'Lead marketplace mgmt — 3 platforms', 'monthly' => 499.00],
];

// Make any Stripe REST call. Returns ['ok'=>bool, 'data'=>array|null, 'error'=>string].
function crm_stripeRequest(string $method, string $path, array $params = []): array {
    $key = crm_config('STRIPE_API_KEY');
    if (!$key) return ['ok' => false, 'data' => null, 'error' => 'STRIPE_API_KEY not configured'];

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch  = curl_init();

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Stripe-Version: 2024-06-20',
        ],
    ];

    $method = strtoupper($method);
    if ($method === 'GET') {
        if ($params) $opts[CURLOPT_URL] .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($params) $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'data' => null, 'error' => "curl: {$err}"];

    $json = json_decode((string)$resp, true);
    if ($code >= 400) {
        $msg = $json['error']['message'] ?? "Stripe HTTP {$code}";
        return ['ok' => false, 'data' => $json, 'error' => $msg];
    }
    return ['ok' => true, 'data' => $json, 'error' => ''];
}

// Get-or-create a Stripe Customer for a client. Persists stripe_customer_id.
function crm_stripeEnsureCustomer(array $client): array {
    if (!empty($client['stripe_customer_id'])) {
        $r = crm_stripeRequest('GET', "customers/{$client['stripe_customer_id']}");
        if ($r['ok']) return $r;
    }

    $params = [
        'email'       => $client['primary_email'] ?? '',
        'name'        => $client['business_name'] ?? '',
        'phone'       => $client['primary_phone'] ?? '',
        'description' => 'Adverton client #' . ($client['id'] ?? '?'),
        'metadata[client_id]' => (int)($client['id'] ?? 0),
        'metadata[trade]'     => $client['trade'] ?? '',
    ];
    $r = crm_stripeRequest('POST', 'customers', $params);
    if ($r['ok'] && !empty($r['data']['id'])) {
        crm_updateClient((int)$client['id'], ['stripe_customer_id' => $r['data']['id']], null);
    }
    return $r;
}

// Build the Stripe subscription line items based on a client's plan.
// Each item uses inline price_data (no upfront product/price creation needed).
function crm_clientStripeLineItems(array $client): array {
    $items = [];

    // Base plan — always included
    $monthlyFee = (float)($client['monthly_fee'] ?? CRM_STRIPE_BASE_PLAN['monthly']);
    $items[] = [
        'name'     => CRM_STRIPE_BASE_PLAN['name'],
        'monthly'  => $monthlyFee,
        'quantity' => 1,
    ];

    // Active add-ons from the JSON column
    $addons = $client['addons_decoded'] ?? [];
    foreach ($addons as $a) {
        if (!empty($a['ended_at']) && $a['ended_at'] <= date('Y-m-d')) continue;
        $code = (string)($a['code'] ?? '');
        $catalog = CRM_STRIPE_ADDON_CATALOG[$code] ?? null;
        $price = (float)($a['price_monthly'] ?? ($catalog['monthly'] ?? 0));
        $name  = $catalog['name'] ?? ucfirst(str_replace('_', ' ', $code));
        if ($price <= 0) continue;
        $items[] = ['name' => $name, 'monthly' => $price, 'quantity' => 1];
    }

    // Ad-spend management fee (15% of ad_budget if both set)
    $adBudget = (float)($client['ad_budget'] ?? 0);
    $mgmtPct  = (float)($client['mgmt_fee_pct'] ?? 0);
    if ($adBudget > 0 && $mgmtPct > 0) {
        $mgmtFee = round($adBudget * $mgmtPct / 100.0, 2);
        if ($mgmtFee > 0) {
            $items[] = [
                'name'    => "Ad-spend management ({$mgmtPct}% of \${$adBudget}/mo)",
                'monthly' => $mgmtFee,
                'quantity' => 1,
            ];
        }
    }

    return $items;
}

// Create a Billing Portal session restricted to payment-method update only.
// No cancel button, no subscription edits — just "swap your card".
// Requires that a portal configuration exists in Stripe with the right perms.
// We use Stripe's "default" config and rely on the `flow_data` API to scope
// this single session to payment_method_update flow.
function crm_stripeCreateCardUpdateLink(array $client): array {
    if (empty($client['stripe_customer_id'])) {
        return ['ok' => false, 'error' => 'Client has no stripe_customer_id (not yet subscribed)'];
    }
    $params = [
        'customer'   => (string)$client['stripe_customer_id'],
        'return_url' => 'https://adverton.net/',
        // Scope this specific session to JUST update the default payment method.
        // Even if your default portal config exposes other flows, this overrides.
        'flow_data[type]' => 'payment_method_update',
    ];
    $r = crm_stripeRequest('POST', 'billing_portal/sessions', $params);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
    return [
        'ok'  => true,
        'url' => $r['data']['url'] ?? '',
    ];
}

// Cancel a Stripe subscription. By default cancels at period end (so the
// client keeps service through the current paid month). Pass $immediate=true
// to refund-eligible immediate cancel. Returns ['ok'=>bool, 'error'=>string].
function crm_stripeCancelSubscription(string $subscriptionId, bool $immediate = false): array {
    if ($immediate) {
        return crm_stripeRequest('DELETE', "subscriptions/{$subscriptionId}");
    }
    // Cancel at period end = subscription stays active until next renewal date,
    // then stops. Standard B2B "graceful cancel".
    return crm_stripeRequest('POST', "subscriptions/{$subscriptionId}", [
        'cancel_at_period_end' => 'true',
    ]);
}

// Compute the total monthly subscription value (for display + email).
function crm_clientStripeMonthlyTotal(array $items): float {
    $sum = 0.0;
    foreach ($items as $it) $sum += (float)$it['monthly'] * (int)$it['quantity'];
    return $sum;
}

// Create a Checkout Session in subscription mode and return the hosted URL.
function crm_stripeCreatePaymentLink(array $client): array {
    if (empty($client['primary_email'])) {
        return ['ok' => false, 'error' => 'Client has no primary_email'];
    }

    // Make sure the customer exists in Stripe
    $cust = crm_stripeEnsureCustomer($client);
    if (!$cust['ok']) return ['ok' => false, 'error' => 'Customer create failed: ' . $cust['error']];
    $customerId = $cust['data']['id'];

    // Build line items
    $items = crm_clientStripeLineItems($client);
    if (!$items) return ['ok' => false, 'error' => 'No items to bill (no base + no addons)'];

    $params = [
        'mode'        => 'subscription',
        'customer'    => $customerId,
        'success_url' => 'https://adverton.net/payment-thanks?session={CHECKOUT_SESSION_ID}',
        'cancel_url'  => 'https://adverton.net/',
        'allow_promotion_codes' => 'true',
        'billing_address_collection' => 'auto',
        'metadata[client_id]' => (int)($client['id'] ?? 0),
    ];
    $i = 0;
    foreach ($items as $it) {
        $unit = (int) round($it['monthly'] * 100); // dollars → cents
        $params["line_items[{$i}][quantity]"] = (int)$it['quantity'];
        $params["line_items[{$i}][price_data][currency]"]              = 'usd';
        $params["line_items[{$i}][price_data][product_data][name]"]    = $it['name'];
        $params["line_items[{$i}][price_data][recurring][interval]"]   = 'month';
        $params["line_items[{$i}][price_data][unit_amount]"]           = $unit;
        $i++;
    }
    // Tie subscription back to our client + record the 12-month commitment
    $params['subscription_data[metadata][client_id]']         = (int)($client['id'] ?? 0);
    $params['subscription_data[metadata][commitment_months]'] = 12;
    $params['subscription_data[metadata][commitment_until]']  = date('Y-m-d', strtotime('+12 months'));
    $params['subscription_data[metadata][adverton_business]'] = (string)($client['business_name'] ?? '');
    // Description shown on Stripe invoices + customer receipts
    $params['subscription_data[description]'] = 'Adverton subscription · 12-month commitment per Service Agreement Section 4';

    $r = crm_stripeRequest('POST', 'checkout/sessions', $params);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];

    return [
        'ok'         => true,
        'url'        => $r['data']['url'] ?? '',
        'session_id' => $r['data']['id']  ?? '',
        'expires_at' => $r['data']['expires_at'] ?? null,
        'items'      => $items,
        'monthly'    => crm_clientStripeMonthlyTotal($items),
    ];
}
