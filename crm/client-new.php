<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$users = crm_listUsers();

crm_renderHead('New client');
crm_renderHeader($user, 'clients');
?>
<style>
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;max-width:680px;margin:0 auto}
  h1{margin:0 0 6px;font-size:22px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=text],input[type=email],input[type=tel],input[type=number],input[type=date],select,textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
  textarea{min-height:80px;line-height:1.5}
  button.primary{margin-top:18px;background:#6d28d9;color:#fff;border:0;padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  @media (max-width:600px){ .row2,.row3{grid-template-columns:1fr} }
</style>
<main>
  <a href="/crm/clients.php" style="font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px">‹ Back to clients</a>

  <form class="card" method="post" action="/crm/update.php">
    <h1>New client</h1>
    <div class="sub">Direct entry — no lead/pipeline history. Use this for clients you closed off-platform or are migrating in.</div>

    <input type="hidden" name="mode" value="client_create">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <label>Business name *</label>
    <input type="text" name="business_name" required>

    <div class="row2">
      <div>
        <label>Trade</label>
        <select name="trade">
          <option value="">—</option>
          <?php foreach (['HVAC','Plumbing','Roofing','Electrical','Pest Control','Landscaping','Solar','Restoration','Garage Door','Other'] as $t): ?>
            <option value="<?= $t ?>"><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Account manager</label>
        <select name="account_manager_id">
          <option value="<?= (int)$user['id'] ?>">Me (<?= crm_h($user['display_name']) ?>)</option>
          <?php foreach ($users as $u): if ((int)$u['id']===(int)$user['id']) continue; ?>
            <option value="<?= (int)$u['id'] ?>"><?= crm_h($u['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row2">
      <div><label>Primary email</label><input type="email" name="primary_email"></div>
      <div><label>Primary phone</label><input type="tel" name="primary_phone"></div>
    </div>

    <div class="row2">
      <div><label>Contract start</label><input type="date" name="contract_start_at" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Contract end (default = start + 12mo)</label><input type="date" name="contract_end_at" value="<?= date('Y-m-d', strtotime('+12 months')) ?>"></div>
    </div>

    <label>Deal terms — Adverton MRR = monthly fee + (ad budget × mgmt %)</label>
    <div class="row3">
      <div>
        <label style="margin:6px 0 4px;text-transform:none;font-size:12px;color:#6b6877;font-weight:500">Monthly fee ($)</label>
        <input type="number" step="0.01" min="0" name="monthly_fee" value="799">
      </div>
      <div>
        <label style="margin:6px 0 4px;text-transform:none;font-size:12px;color:#6b6877;font-weight:500">Ad budget / mo ($)</label>
        <input type="number" step="0.01" min="0" name="ad_budget" placeholder="optional">
      </div>
      <div>
        <label style="margin:6px 0 4px;text-transform:none;font-size:12px;color:#6b6877;font-weight:500">Mgmt fee (%)</label>
        <input type="number" step="0.01" min="0" max="100" name="mgmt_fee_pct" value="0">
      </div>
    </div>

    <div class="row2">
      <div>
        <label>Status</label>
        <select name="status">
          <option value="active">Active</option>
          <option value="onboarding">Onboarding</option>
          <option value="paused">Paused</option>
        </select>
      </div>
      <div>
        <label>Installments paid (so far)</label>
        <input type="number" min="0" max="12" name="installment_count" value="0">
      </div>
    </div>

    <label>Notes</label>
    <textarea name="notes" placeholder="Anything relevant — onboarding context, past relationship, special terms..."></textarea>

    <button type="submit" class="primary">Create client</button>
  </form>
</main>
</body></html>
