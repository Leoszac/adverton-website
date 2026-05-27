<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$users = crm_listUsers();

crm_renderHead('New lead');
crm_renderHeader($user, 'leads');
?>
<style>
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;max-width:680px;margin:0 auto}
  h1{margin:0 0 6px;font-size:22px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=text],input[type=email],input[type=tel],input[type=number],select,textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
  textarea{min-height:80px;line-height:1.5}
  button.primary{margin-top:18px;background:#6d28d9;color:#fff;border:0;padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:600px){ .row2{grid-template-columns:1fr} }
  .check{display:flex;gap:8px;align-items:center;margin-top:14px;padding:12px;background:#faf9ff;border:1px solid #e7e4ee;border-radius:8px;font-size:13px}
  .check input{width:auto;margin:0}
</style>
<main>
  <a href="/crm/" style="font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px">‹ Back to leads</a>

  <form class="card" method="post" action="/crm/update.php">
    <h1>New lead</h1>
    <div class="sub">For leads captured off-platform (referral, cold call, networking, manual entry).</div>

    <input type="hidden" name="mode" value="lead_create">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <div class="row2">
      <div><label>First name</label><input type="text" name="first_name"></div>
      <div><label>Last name</label><input type="text" name="last_name"></div>
    </div>
    <div class="row2">
      <div><label>Email</label><input type="email" name="email"></div>
      <div><label>Phone</label><input type="tel" name="phone"></div>
    </div>
    <div class="row2">
      <div><label>Business name</label><input type="text" name="business_name"></div>
      <div>
        <label>Trade</label>
        <select name="trade">
          <option value="">—</option>
          <?php foreach (['HVAC','Plumbing','Roofing','Electrical','Pest Control','Landscaping','Solar','Restoration','Garage Door','Handyman','Home Cleaning','Home Inspector','Other'] as $t): ?>
            <option value="<?= $t ?>"><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row2">
      <div><label>City / State (e.g. "Tampa, FL")</label><input type="text" name="city_state"></div>
      <div><label>Website</label><input type="text" name="website" placeholder="https://..."></div>
    </div>
    <div class="row2">
      <div>
        <label>Source</label>
        <select name="source">
          <option value="manual">Manual entry</option>
          <option value="lead_magnet">Lead magnet (audit, ebook, etc)</option>
          <option value="referral">Referral</option>
          <option value="affiliate">Affiliate</option>
          <option value="inbound_call">Inbound call</option>
        </select>
      </div>
      <div>
        <label>Source page / referrer (optional)</label>
        <input type="text" name="source_page" placeholder="who referred · campaign · landing page">
      </div>
    </div>

    <div class="row2">
      <div>
        <label>Owner</label>
        <select name="owner_user_id">
          <option value="<?= (int)$user['id'] ?>">Me (<?= crm_h($user['display_name']) ?>)</option>
          <?php foreach ($users as $u): if ((int)$u['id']===(int)$user['id']) continue; ?>
            <option value="<?= (int)$u['id'] ?>"><?= crm_h($u['display_name']) ?></option>
          <?php endforeach; ?>
          <option value="">— Unassigned —</option>
        </select>
      </div>
      <div>
        <label>Initial status</label>
        <select name="initial_status">
          <option value="new">New</option>
          <option value="contacted">Contacted</option>
          <option value="qualified">Qualified</option>
          <option value="proposal">Proposal</option>
          <option value="won">Won (also creates client)</option>
        </select>
      </div>
    </div>

    <label>Notes</label>
    <textarea name="notes" placeholder="How did you find them? Pain points? Next steps?"></textarea>

    <button type="submit" class="primary">Create lead</button>
  </form>
</main>
</body></html>
