<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$leadId = (int)($_GET['lead_id'] ?? 0);
$lead = $leadId > 0 ? crm_getLead($leadId) : null;
if (!$lead) { http_response_code(404); header('Location: /crm/'); exit; }

// Has this lead already been promoted to a client?
$existing = crm_getClientByLead($leadId);
$err = (string)($_GET['err'] ?? '');

crm_renderHead('Build proposal');
crm_renderHeader($user, '');

// Build the catalog list (codes → labels + price) from lib/stripe.php constants
$addons = CRM_STRIPE_ADDON_CATALOG;
?>
<style>
  .wrap{max-width:760px;margin:0 auto}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px}
  h1{margin:0 0 4px;font-size:22px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  .err{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .warn{background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=number],input[type=text]{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  @media (max-width:600px){.row3{grid-template-columns:1fr}}

  .addon-list{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px}
  @media (max-width:600px){.addon-list{grid-template-columns:1fr}}
  .addon-list label{display:flex;align-items:center;gap:8px;background:#faf9ff;border:1px solid #e7e4ee;border-radius:8px;padding:10px 12px;cursor:pointer;font-size:13px;color:#0e0d12;text-transform:none;letter-spacing:0;font-weight:500;margin:0}
  .addon-list label:hover{border-color:#6d28d9}
  .addon-list label input{margin:0}
  .addon-list label .price{margin-left:auto;color:#6b6877;font-weight:600;font-size:12px}
  .addon-list label.checked{background:#fae8ff;border-color:#6d28d9}

  .totals{background:#0e0d12;color:#fff;padding:18px 22px;border-radius:12px;margin-top:18px;display:flex;justify-content:space-between;align-items:center}
  .totals .label{font-size:11px;color:#bcb6ca;text-transform:uppercase;letter-spacing:.08em;font-weight:700}
  .totals .amt{font-size:28px;font-weight:800;letter-spacing:-.01em;font-variant-numeric:tabular-nums}

  button.primary{margin-top:18px;width:100%;background:#6d28d9;color:#fff;border:0;padding:13px 22px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer}
  button.primary:hover{background:#5b21b6}
</style>
<main class="wrap">
  <a href="/crm/lead.php?id=<?= (int)$leadId ?>" style="font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px">‹ Back to lead</a>

  <form class="card" method="post" action="/crm/update.php" id="propForm">
    <h1>Build &amp; send proposal</h1>
    <div class="sub">Pick the plan + add-ons, click "Send proposal". The CRM creates a client + Stripe payment link + emails it. Lead auto-moves to <code>won</code> when they pay.</div>

    <?php if ($err): ?><div class="err"><?= crm_h($err) ?></div><?php endif; ?>
    <?php if ($existing): ?>
      <div class="warn">⚠ This lead already has a client (<a href="/crm/client.php?id=<?= (int)$existing['id'] ?>">#<?= (int)$existing['id'] ?></a>). Sending again will create a separate Stripe link for the same client.</div>
    <?php endif; ?>

    <div style="background:#faf9ff;border:1px solid #e7e4ee;border-radius:10px;padding:14px;margin-bottom:14px">
      <div style="font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:4px">Sending to</div>
      <div style="font-weight:600"><?= crm_h(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))) ?: 'Lead #' . (int)$leadId ?></div>
      <div style="font-size:13px;color:#6b6877"><?= crm_h($lead['email'] ?? '(no email)') ?> · <?= crm_h($lead['business_name'] ?? '?') ?> · <?= crm_h($lead['trade'] ?? '?') ?></div>
    </div>

    <input type="hidden" name="mode" value="proposal_send">
    <input type="hidden" name="lead_id" value="<?= (int)$leadId ?>">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <label>Adverton — base monthly fee</label>
    <input type="number" step="0.01" min="0" name="monthly_fee" value="<?= crm_h((string)($lead['monthly_fee'] ?? 799)) ?>" required id="fee">

    <label>Add-ons (optional)</label>
    <div class="addon-list" id="addons">
      <?php foreach ($addons as $code => $a): ?>
        <label data-price="<?= (float)$a['monthly'] ?>">
          <input type="checkbox" name="addons[]" value="<?= crm_h($code) ?>">
          <span><?= crm_h($a['name']) ?></span>
          <span class="price">$<?= number_format((float)$a['monthly'], 0) ?>/mo</span>
        </label>
      <?php endforeach; ?>
    </div>

    <label>Ad-spend management (optional, only if Adverton manages ad payment)</label>
    <div class="row3">
      <div>
        <label style="margin:0;text-transform:none;font-weight:500;font-size:12px;color:#6b6877">Ad budget / mo ($)</label>
        <input type="number" step="0.01" min="0" name="ad_budget" placeholder="0" id="budget">
      </div>
      <div>
        <label style="margin:0;text-transform:none;font-weight:500;font-size:12px;color:#6b6877">Mgmt fee (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="mgmt_fee_pct" value="0" id="pct">
      </div>
      <div>
        <label style="margin:0;text-transform:none;font-weight:500;font-size:12px;color:#6b6877">Mgmt fee/mo</label>
        <input type="text" disabled id="mgmtAmt" value="$0">
      </div>
    </div>

    <div class="totals">
      <div>
        <div class="label">Monthly total</div>
        <div style="font-size:11px;color:#bcb6ca;margin-top:2px">12-month commitment</div>
      </div>
      <div class="amt" id="total">$<?= number_format(799, 0) ?></div>
    </div>

    <button type="submit" class="primary">📧 Send proposal email + Stripe link</button>
  </form>
</main>
<script>
function num(v){ return Math.max(0, parseFloat(v) || 0); }
function recalc(){
  const fee = num(document.getElementById('fee').value);
  let addons = 0;
  document.querySelectorAll('#addons input[type=checkbox]').forEach(c => {
    const lbl = c.closest('label');
    lbl.classList.toggle('checked', c.checked);
    if (c.checked) addons += num(lbl.dataset.price);
  });
  const budget = num(document.getElementById('budget').value);
  const pct    = num(document.getElementById('pct').value);
  const mgmt = +(budget * pct / 100).toFixed(2);
  document.getElementById('mgmtAmt').value = '$' + mgmt.toLocaleString('en-US', {maximumFractionDigits: 2});
  const total = fee + addons + mgmt;
  document.getElementById('total').textContent = '$' + total.toLocaleString('en-US', {maximumFractionDigits: 2});
}
['fee','budget','pct'].forEach(id => document.getElementById(id).addEventListener('input', recalc));
document.querySelectorAll('#addons input').forEach(c => c.addEventListener('change', recalc));
recalc();
</script>
</body></html>
