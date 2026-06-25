<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']); // leads role excluded

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Location: /crm/'); exit; }
if (!crm_csrfCheck($_POST['csrf'] ?? null)) { http_response_code(403); exit('CSRF'); }

$leadId = (int)($_POST['lead_id'] ?? 0);
$lead = $leadId > 0 ? crm_getLead($leadId) : null;
if (!$lead) { http_response_code(404); header('Location: /crm/'); exit; }

$monthlyFee = (float)($_POST['monthly_fee']  ?? 799);
$adBudget   = (float)($_POST['ad_budget']    ?? 0);
$mgmtPct    = (float)($_POST['mgmt_fee_pct'] ?? 0);
$addonCodes = (array)($_POST['addons']       ?? []);

// Build the items list using the same logic as the actual send (no DB writes here)
$items = [['name' => CRM_STRIPE_BASE_PLAN['name'], 'monthly' => $monthlyFee, 'quantity' => 1]];
foreach ($addonCodes as $code) {
    $code = (string)$code;
    if (!isset(CRM_STRIPE_ADDON_CATALOG[$code])) continue;
    $items[] = [
        'name'    => CRM_STRIPE_ADDON_CATALOG[$code]['name'],
        'monthly' => (float) CRM_STRIPE_ADDON_CATALOG[$code]['monthly'],
        'quantity' => 1,
    ];
}
if ($adBudget > 0 && $mgmtPct > 0) {
    $mgmtFee = round($adBudget * $mgmtPct / 100.0, 2);
    if ($mgmtFee > 0) {
        $items[] = ['name' => "Ad-spend management ({$mgmtPct}% of \${$adBudget}/mo)", 'monthly' => $mgmtFee, 'quantity' => 1];
    }
}
$total = 0; foreach ($items as $it) $total += $it['monthly'];

$leadName       = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?: 'Lead';
$businessName   = $lead['business_name'] ?? '';
$ctaName        = trim((string)($lead['business_name'] ?? '')) ?: $leadName;
$commitmentEnd  = date('F Y', strtotime('+12 months'));
$monthlyFmt     = '$' . number_format($total, 2);

crm_renderHead('Proposal preview');
crm_renderHeader($user, '');
?>
<style>
  .wrap{max-width:760px;margin:0 auto}
  .back{font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px}
  .step{background:#fef3c7;color:#92400e;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:14px;display:inline-block}
  .preview{background:#f5f4f9;border-radius:14px;padding:24px;margin-bottom:18px}
  .pmail{max-width:560px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.05);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
  .pmail .meta{background:#faf9ff;padding:14px 24px;border-bottom:1px solid #e7e4ee;font-size:12px;color:#6b6877}
  .pmail .meta strong{color:#0e0d12}
  .pmail .body{padding:32px}
  .pmail .brand{font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6d28d9;margin-bottom:16px}
  .pmail h1{margin:0;font-size:22px;line-height:1.3;color:#0e0d12;font-weight:700}
  .pmail p{margin:14px 0 0;font-size:15px;line-height:1.55;color:#383640}
  .pmail .cta{display:block;width:fit-content;margin:24px auto 8px;background:#6d28d9;color:#fff;padding:14px 32px;border-radius:10px;font-weight:600;font-size:15px;text-decoration:none}
  .pmail .ctasub{font-size:11px;color:#6b6877;text-align:center;margin-top:6px}
  .pmail .terms{background:#faf9ff;border:1px solid #e7e4ee;border-radius:10px;padding:16px 18px;margin-top:18px}
  .pmail .terms .t{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b6877;margin-bottom:10px}
  .pmail .it{display:flex;justify-content:space-between;font-size:14px;padding:6px 0}
  .pmail .it span:last-child{color:#6b6877;white-space:nowrap}
  .pmail .total{border-top:1px solid #e7e4ee;padding-top:10px;font-weight:700;color:#0e0d12;font-size:15px;display:flex;justify-content:space-between;align-items:center}
  .pmail .total small{color:#6b6877;font-weight:500;font-size:13px}
  .pmail ul{margin:0;padding:0 0 0 18px;color:#383640;font-size:14px;line-height:1.7}
  .pmail .footer{padding:14px 32px 28px;border-top:1px solid #f0eef5;font-size:11px;color:#a8a3b3;line-height:1.5}

  .actions{display:flex;gap:10px;align-items:center;justify-content:space-between;background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:14px;margin-top:18px}
  .actions button.primary{background:#6d28d9;color:#fff;border:0;padding:13px 24px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer}
  .actions a.cancel{color:#6b6877;text-decoration:none;font-size:13px;font-weight:600;padding:13px 14px}
</style>
<main class="wrap">
  <a class="back" href="/crm/lead.php?id=<?= (int)$leadId ?>">‹ Back to lead</a>
  <div class="step">PREVIEW · nothing has been sent yet</div>

  <div class="preview">
    <div class="pmail">
      <div class="meta">
        <div><strong>To:</strong> <?= crm_h($lead['email'] ?? '(no email)') ?></div>
        <div><strong>From:</strong> Adverton &lt;hello@adverton.net&gt;</div>
        <div><strong>Reply-to:</strong> <?= crm_h(crm_resolveUserSender((int)$user['id'])['reply_to']) ?></div>
        <div><strong>Subject:</strong> Activate your Adverton subscription</div>
      </div>
      <div class="body">
        <div class="brand">Adverton</div>
        <h1>Activate your Adverton subscription</h1>
        <p>Hi <?= crm_h($ctaName) ?>, here's your secure payment link. Click below to enter your card and activate your account today.</p>

        <div style="text-align:center">
          <span class="cta">💳 Pay <?= crm_h($monthlyFmt) ?> / month →</span>
          <div class="ctasub">Card processing by Stripe · we never see card details</div>
        </div>

        <div class="terms">
          <div class="t">What's included</div>
          <?php foreach ($items as $it): ?>
            <div class="it"><span><?= crm_h($it['name']) ?></span><span>$<?= number_format((float)$it['monthly'], 2) ?> / mo</span></div>
          <?php endforeach; ?>
          <div class="total">
            <span>Total <small>(billed monthly)</small></span>
            <span><?= crm_h($monthlyFmt) ?> / mo</span>
          </div>
        </div>

        <div style="margin-top:20px">
          <div class="t" style="color:#6b6877;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Terms</div>
          <ul>
            <li>12-month commitment, charged monthly through <?= crm_h($commitmentEnd) ?></li>
            <li>Auto-renews for another 12 months unless either party gives 90-day notice</li>
            <li>Per the Adverton Service Agreement you signed</li>
          </ul>
        </div>

        <p>Once payment is confirmed, your account goes active and onboarding kicks off the same day.</p>
        <p>Any questions, just reply to this email.</p>
        <p style="font-size:14px;color:#0e0d12">— The Adverton team</p>
      </div>
      <div class="footer">Adverton · MDS LLC · 16192 Coastal Highway, Lewes, DE 19958 · adverton.net</div>
    </div>
  </div>

  <form method="post" action="/crm/update.php" class="actions">
    <input type="hidden" name="mode" value="proposal_send">
    <input type="hidden" name="lead_id" value="<?= (int)$leadId ?>">
    <input type="hidden" name="monthly_fee" value="<?= crm_h((string)$monthlyFee) ?>">
    <input type="hidden" name="ad_budget" value="<?= crm_h((string)$adBudget) ?>">
    <input type="hidden" name="mgmt_fee_pct" value="<?= crm_h((string)$mgmtPct) ?>">
    <?php foreach ($addonCodes as $code): ?>
      <input type="hidden" name="addons[]" value="<?= crm_h((string)$code) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <a class="cancel" href="/crm/proposal-send.php?lead_id=<?= (int)$leadId ?>">‹ Edit</a>
    <button type="submit" class="primary">📧 Looks good · Send to <?= crm_h($lead['email'] ?? '?') ?></button>
  </form>

  <p style="font-size:12px;color:#6b6877;text-align:center;margin-top:10px">
    The CTA button in the actual email goes to a Stripe Checkout link tracked through the CRM
    (open + click logged on the client page).
  </p>
</main>
</body></html>
