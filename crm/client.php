<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$id = (int)($_GET['id'] ?? 0);
$client = $id > 0 ? crm_getClient($id) : null;
if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }

$users   = crm_listUsers();
$events  = crm_listClientEvents((int)$client['id']);
$lead    = $client['lead_id'] ? crm_getLead((int)$client['lead_id']) : null;
$activities = $client['lead_id'] ? crm_listActivities((int)$client['lead_id']) : [];
$tasks   = $client['lead_id'] ? crm_listTasksForLead((int)$client['lead_id']) : [];
$files   = $client['lead_id'] ? crm_listFiles((int)$client['lead_id']) : [];
$saved   = ($_GET['saved'] ?? '') === '1';

$mrr     = crm_clientMrr($client);
$buyout  = crm_buyoutAmount($client);
$daysToEnd = (int)((strtotime((string)$client['contract_end_at']) - time()) / 86400);
$daysFromStart = (int)((time() - strtotime((string)$client['contract_start_at'])) / 86400);

crm_renderHead('Client #' . (int)$client['id']);
crm_renderHeader($user, 'clients');
?>
<style>
  .back{font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
  h1{margin:0 0 4px;font-size:22px;letter-spacing:-.01em}
  .sub{color:#6b6877;font-size:13px;margin-bottom:16px}
  .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-left:6px}
  .pill.cs-onboarding{background:#fef3c7;color:#92400e}
  .pill.cs-active{background:#dcfce7;color:#166534}
  .pill.cs-past_due{background:#fee2e2;color:#991b1b}
  .pill.cs-paused{background:#e5e7eb;color:#374151}
  .pill.cs-cancelled{background:#fecaca;color:#7f1d1d}
  .pill.cs-renewed{background:#fae8ff;color:#6b21a8}

  .grid2{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}
  @media (max-width: 980px){ .grid2{grid-template-columns:1fr} }
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  .card h2{margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}

  .kbox{display:grid;grid-template-columns:repeat(2,1fr);gap:10px 18px}
  .k{font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:3px}
  .v{font-size:14px;color:#0e0d12}
  .mrr-tag{display:inline-block;background:#0e0d12;color:#fff;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;margin-left:8px}

  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  select,input[type=number],input[type=date],input[type=text],textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
  textarea{min-height:80px;line-height:1.5}
  button.primary{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}

  .timeline .ev{display:grid;grid-template-columns:24px 1fr;gap:8px;padding:10px 0;border-bottom:1px solid #f0eef5;font-size:13px}
  .timeline .ev:last-child{border-bottom:0}
  .timeline .ev .meta{font-size:11px;color:#6b6877}

  .addons{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
  .addon{background:#fae8ff;color:#6b21a8;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
  .addon.gone{opacity:.4;text-decoration:line-through}

  .danger{color:#dc2626}
  .deal-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  @media (max-width:700px){ .deal-row{grid-template-columns:1fr} .kbox{grid-template-columns:1fr} }
</style>
<main>
  <a class="back" href="/crm/clients.php">‹ All clients</a>
  <?php if ($saved): ?><div class="saved">Saved.</div><?php endif; ?>

  <div class="card">
    <h1>
      <?= crm_h($client['business_name'] ?? 'Unnamed client') ?>
      <span class="pill cs-<?= crm_h($client['status']) ?>"><?= crm_h($client['status']) ?></span>
      <span class="mrr-tag"><?= crm_h(crm_fmtMoney($mrr)) ?>/mo</span>
    </h1>
    <div class="sub">
      <?= crm_h($client['trade'] ?? '') ?>
      · contract: <?= crm_h(date('M j, Y', strtotime((string)$client['contract_start_at']))) ?>
      → <?= crm_h(date('M j, Y', strtotime((string)$client['contract_end_at']))) ?>
      (<?= $daysToEnd ?>d left, day <?= $daysFromStart ?>)
      <?php if ($lead): ?> · <a href="/crm/lead.php?id=<?= (int)$lead['id'] ?>" style="color:#6d28d9">view lead history</a><?php endif; ?>
    </div>

    <div class="kbox">
      <div><div class="k">Email</div><div class="v"><?= crm_h($client['primary_email'] ?? '—') ?></div></div>
      <div><div class="k">Phone</div><div class="v"><?= crm_h($client['primary_phone'] ?? '—') ?></div></div>
      <div><div class="k">Installments paid</div><div class="v"><?= (int)$client['installment_count'] ?> / 12</div></div>
      <div><div class="k">Buyout amount</div><div class="v"><?= crm_h(crm_fmtMoney($buyout)) ?></div></div>
      <div><div class="k">Stripe customer</div><div class="v"><?= crm_h($client['stripe_customer_id'] ?? '—') ?></div></div>
      <div><div class="k">PandaDoc ID</div><div class="v"><?= crm_h($client['pandadoc_doc_id'] ?? '—') ?></div></div>
    </div>

    <label style="margin-top:18px">Active add-ons</label>
    <div class="addons">
      <?php $ad = $client['addons_decoded'] ?? []; if (!$ad): ?>
        <span style="color:#6b6877;font-size:13px">No add-ons yet</span>
      <?php else: foreach ($ad as $a):
        $gone = !empty($a['ended_at']);
      ?>
        <span class="addon <?= $gone?'gone':'' ?>">
          <?= crm_h($a['code']) ?> · <?= crm_h(crm_fmtMoney((float)$a['price_monthly'])) ?>/mo
        </span>
      <?php endforeach; endif; ?>
    </div>
    <form method="post" action="/crm/update.php" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="mode" value="client_addon_add">
      <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
      <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
      <select name="code" style="width:auto">
        <option value="ai_voice">AI Voice — $349</option>
        <option value="meta_ads">Meta Ads — $199</option>
        <option value="yelp_mgmt">Yelp setup+mgmt — $149</option>
        <option value="content_updates">Content updates — $99</option>
        <option value="multi_location">Multi-location — $199</option>
        <option value="extra_email">Extra email — $15</option>
        <option value="leads_marketplace_1">Lead marketplace (1) — $199</option>
      </select>
      <input type="number" step="0.01" name="price" placeholder="Override $" style="width:120px">
      <button type="submit" class="primary" style="margin-top:0">+ Add</button>
    </form>
  </div>

  <div class="grid2">
    <div>
      <div class="card timeline">
        <h2>Client events</h2>
        <?php if (!$events): ?><div style="color:#6b6877;font-size:13px">No events yet.</div>
        <?php else: foreach ($events as $e): ?>
          <div class="ev">
            <span style="font-size:14px">·</span>
            <div>
              <div><?= crm_h(str_replace('_', ' ', (string)$e['type'])) ?> <?php if ($e['user_name']): ?><span style="color:#6b6877">— <?= crm_h($e['user_name']) ?></span><?php endif; ?></div>
              <div class="meta"><?= crm_h(crm_fmtRelative((string)$e['created_at'])) ?> · <?= crm_h(substr((string)$e['created_at'], 0, 16)) ?></div>
              <?php if ($e['body']): ?><div style="margin-top:3px"><?= crm_h($e['body']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <?php if ($activities): ?>
      <div class="card timeline">
        <h2>Original lead timeline</h2>
        <?php foreach (array_slice($activities, 0, 20) as $a): ?>
          <div class="ev">
            <span><?= crm_activityIcon($a['type']) ?></span>
            <div>
              <div><?= crm_h(crm_activityLabel($a['type'], $a['disposition'])) ?> <span style="color:#6b6877">— <?= crm_h($a['user_name'] ?? 'system') ?></span></div>
              <div class="meta"><?= crm_h(crm_fmtRelative((string)$a['created_at'])) ?></div>
              <?php if ($a['body']): ?><div style="margin-top:3px"><?= crm_h($a['body']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div>
      <form class="card" method="post" action="/crm/update.php">
        <h2>Subscription</h2>
        <input type="hidden" name="mode" value="client_update">
        <input type="hidden" name="id" value="<?= (int)$client['id'] ?>">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

        <div class="kbox">
          <div>
            <label>Status</label>
            <select name="status">
              <?php foreach (CRM_CLIENT_STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= $client['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Payment</label>
            <select name="payment_status">
              <?php foreach (CRM_PAYMENT_STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= $client['payment_status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Installments paid</label>
            <input type="number" min="0" max="12" name="installment_count" value="<?= (int)$client['installment_count'] ?>">
          </div>
          <div>
            <label>Account manager</label>
            <select name="account_manager_id">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$client['account_manager_id']===(int)$u['id']?'selected':'' ?>><?= crm_h($u['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Contract start</label>
            <input type="date" name="contract_start_at" value="<?= crm_h((string)$client['contract_start_at']) ?>">
          </div>
          <div>
            <label>Contract end</label>
            <input type="date" name="contract_end_at" value="<?= crm_h((string)$client['contract_end_at']) ?>">
          </div>
        </div>

        <label>Deal terms</label>
        <div class="deal-row">
          <div>
            <label style="margin:0;text-transform:none;font-weight:500;font-size:12px">Monthly fee</label>
            <input type="number" step="0.01" name="monthly_fee" value="<?= crm_h((string)$client['monthly_fee']) ?>">
          </div>
          <div>
            <label style="margin:0;text-transform:none;font-weight:500;font-size:12px">Ad budget/mo</label>
            <input type="number" step="0.01" name="ad_budget" value="<?= crm_h((string)($client['ad_budget'] ?? '')) ?>">
          </div>
          <div>
            <label style="margin:0;text-transform:none;font-weight:500;font-size:12px">Mgmt fee %</label>
            <input type="number" step="0.01" name="mgmt_fee_pct" value="<?= crm_h((string)($client['mgmt_fee_pct'] ?? 0)) ?>">
          </div>
        </div>

        <label>Stripe IDs (manual until webhook is wired)</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <input type="text" name="stripe_customer_id"      placeholder="cus_..." value="<?= crm_h($client['stripe_customer_id'] ?? '') ?>">
          <input type="text" name="stripe_subscription_id"  placeholder="sub_..." value="<?= crm_h($client['stripe_subscription_id'] ?? '') ?>">
        </div>

        <?php if ($client['status'] === 'cancelled' || $client['cancellation_reason']): ?>
          <label>Cancellation reason</label>
          <select name="cancellation_reason">
            <option value="">—</option>
            <?php foreach (['card_decline'=>'Card decline','chargeback'=>'Chargeback','mutual'=>'Mutual','breach'=>'Breach','voluntary'=>'Voluntary','dissatisfied'=>'Dissatisfied','out_of_business'=>'Out of business'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($client['cancellation_reason']??'')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="cancellation_note" placeholder="Note" value="<?= crm_h($client['cancellation_note'] ?? '') ?>" style="margin-top:6px">
        <?php endif; ?>

        <label>Notes</label>
        <textarea name="notes"><?= crm_h($client['notes'] ?? '') ?></textarea>

        <button type="submit" class="primary">Save</button>
      </form>

      <?php if ($files): ?>
      <div class="card">
        <h2>Files (from lead)</h2>
        <?php foreach ($files as $f): ?>
          <div style="padding:6px 0;border-bottom:1px solid #f0eef5;font-size:13px">
            <a href="/crm/file.php?id=<?= (int)$f['id'] ?>" style="color:#0e0d12;text-decoration:none;font-weight:600"><?= crm_h($f['original_name']) ?></a>
            <span style="color:#6b6877;font-size:11px"> · <?= crm_h(crm_fmtFileSize((int)$f['size_bytes'])) ?> · <?= crm_h(crm_fmtRelative($f['uploaded_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($tasks): ?>
      <div class="card">
        <h2>Open tasks</h2>
        <?php foreach (array_filter($tasks, fn($t) => !$t['done_at']) as $t): ?>
          <div style="padding:6px 0;border-bottom:1px solid #f0eef5;font-size:13px">
            <strong><?= crm_h($t['title']) ?></strong>
            <span style="color:#6b6877"> · due <?= date('M j', strtotime((string)$t['due_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body></html>
