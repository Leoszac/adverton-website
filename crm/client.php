<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/email_track.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/intake.php';
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
$saved      = ($_GET['saved'] ?? '') === '1';
$clientSends = crm_listSendsForClient((int)$client['id']);
$payErr     = (string)($_GET['payerr']  ?? '');
$payLinkOk  = ($_GET['paylink'] ?? '') === '1';
$cardLinkOk = ($_GET['cardlink'] ?? '') === '1';

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
  <?php if ($saved):
      $msg = 'Saved.';
      if ($payLinkOk)  $msg = 'Payment link created and emailed.';
      if ($cardLinkOk) $msg = 'Card-update link emailed.';
  ?><div class="saved"><?= crm_h($msg) ?></div><?php endif; ?>
  <?php if ($payErr): ?><div class="saved" style="background:#fee2e2;color:#991b1b">Stripe error: <?= crm_h($payErr) ?></div><?php endif; ?>

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
        <option value="local_seo_pro">Local SEO Pro — $399</option>
        <option value="social_1">Social — 1 platform — $199</option>
        <option value="social_2">Social — 2 platforms — $349</option>
        <option value="social_3">Social — 3 platforms — $499</option>
        <option value="ai_voice">AI Voice — $349</option>
        <option value="meta_ads">Meta Ads — $199</option>
        <option value="yelp_mgmt">Yelp setup+mgmt — $149</option>
        <option value="multi_location">Multi-location — $199</option>
        <option value="extra_email">Extra email — $15</option>
        <option value="leads_marketplace_1">Lead marketplace (1) — $199</option>
        <option value="leads_marketplace_2">Lead marketplace (2) — $349</option>
        <option value="leads_marketplace_3">Lead marketplace (3) — $499</option>
      </select>
      <input type="number" step="0.01" name="price" placeholder="Override $" style="width:120px">
      <button type="submit" class="primary" style="margin-top:0">+ Add</button>
    </form>
  </div>

  <?php
    $intake = crm_getIntake((int)$client['id']);
    $intakeStatus = $intake['status'] ?? 'not_started';
    $intakeStep   = (int)($intake['current_step'] ?? 1);
    $intakeBadge  = match ($intakeStatus) {
        'not_started'           => ['Not started',  '#fee2e2', '#991b1b'],
        'in_progress'           => ['In progress',  '#fef3c7', '#92400e'],
        'ready_for_ai'          => ['Ready for AI', '#dbeafe', '#1e40af'],
        'ai_generated'          => ['AI generated', '#fae8ff', '#6b21a8'],
        'pending_approval'      => ['Pending approval','#fae8ff','#6b21a8'],
        'approved'              => ['Approved',     '#dcfce7', '#166534'],
        'provisioning_pending'  => ['Provisioning', '#dbeafe', '#1e40af'],
        'deployed'              => ['Live',         '#dcfce7', '#166534'],
        default                 => [$intakeStatus,  '#e7e4ee', '#6b6877'],
    };
  ?>
  <div class="card" style="border-color:#dbeafe">
    <h2 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877">Kickoff intake</h2>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px">
      <span style="background:<?= $intakeBadge[1] ?>;color:<?= $intakeBadge[2] ?>;font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px"><?= crm_h($intakeBadge[0]) ?></span>
      <span style="color:#6b6877;font-size:13px">Step <?= $intakeStep ?> of <?= CRM_INTAKE_TOTAL_STEPS ?></span>
      <?php if (!empty($intake['kickoff_completed_at'])): ?>
        <span style="color:#6b6877;font-size:13px">· Submitted <?= crm_h(crm_fmtRelative($intake['kickoff_completed_at'])) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="primary" href="/crm/client-kickoff.php?id=<?= (int)$client['id'] ?>" style="background:#6d28d9;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none">
        <?= $intakeStatus === 'not_started' ? 'Start kickoff' : 'Continue kickoff' ?>
      </a>
      <?php if (!empty($client['billing_email']) || !empty($client['primary_email'])): ?>
        <form method="post" action="/crm/update.php" style="margin:0"
              onsubmit="return confirm('Email a kickoff link to <?= crm_h($client['billing_email'] ?: $client['primary_email']) ?>?\n\nThe client can fill the wizard async on their phone.')">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <input type="hidden" name="mode" value="intake_send_link">
          <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
          <button type="submit" style="background:#fff;border:1px solid #e7e4ee;color:#383640;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">📧 Email link to client</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="background:#faf9ff;border-color:#e0d6f5">
    <h2 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877">Billing &amp; Stripe</h2>
    <?php
      // Compute what would be billed if we send a fresh link right now
      $previewItems   = crm_clientStripeLineItems($client);
      $previewMonthly = crm_clientStripeMonthlyTotal($previewItems);
      $hasSub         = !empty($client['stripe_subscription_id']);
      $linkSentAt     = $client['stripe_checkout_sent_at'] ?? null;
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-size:18px;font-weight:800;color:#0e0d12">
          <?= crm_h(crm_fmtMoney($previewMonthly)) ?>/mo subscription
        </div>
        <div style="font-size:12px;color:#6b6877;margin-top:3px">
          Base + <?= max(0, count($previewItems) - 1) ?> add-on<?= count($previewItems) === 2 ? '':'s' ?> · billed monthly via Stripe
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($hasSub):
          $monthsPaid = (int)$client['installment_count'];
          $canCancel  = $monthsPaid >= 12;
          $monthsLeft = max(0, 12 - $monthsPaid);
        ?>
          <span class="pill" style="background:#dcfce7;color:#166534">✓ Subscribed (<?= crm_h(substr((string)$client['stripe_subscription_id'], 0, 14)) ?>…)</span>
          <form method="post" action="/crm/update.php" style="margin:0">
            <input type="hidden" name="mode" value="client_send_card_update">
            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <button type="submit"
                    onclick="return confirm('Email a Stripe Billing Portal link to <?= crm_h($client['primary_email'] ?? '?') ?> so they can update their card?\n\nThe portal session will ONLY allow payment-method update — no cancel, no plan changes.')"
                    style="background:#fff;color:#0e0d12;border:1px solid #e7e4ee;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
              💳 Send card-update link
            </button>
          </form>
          <?php if (($user['role'] ?? '') === 'founder'): ?>
            <?php if ($canCancel): ?>
              <form method="post" action="/crm/update.php" style="margin:0">
                <input type="hidden" name="mode" value="client_cancel_subscription">
                <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
                <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
                <button type="submit"
                        onclick="return confirm('Cancel this subscription at the end of the current billing period?\n\nThe client keeps service through their already-paid month, then billing stops. Stripe sends them a cancellation notice. This is a graceful cancel — for immediate refunds, do it from the Stripe dashboard.')"
                        style="background:#fee2e2;color:#991b1b;border:0;padding:9px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
                  Cancel subscription (period-end)
                </button>
              </form>
            <?php else: ?>
              <span style="font-size:11px;color:#6b6877;background:#fef3c7;padding:4px 10px;border-radius:8px">
                🔒 12-month commitment · <?= $monthsLeft ?> installment<?= $monthsLeft===1?'':'s' ?> left
              </span>
            <?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <form method="post" action="/crm/update.php" style="margin:0">
            <input type="hidden" name="mode" value="client_send_payment_link">
            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <button type="submit" style="background:#6d28d9;color:#fff;border:0;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer"
                    onclick="return confirm('Create a Stripe Checkout link for <?= crm_h(crm_fmtMoney($previewMonthly)) ?>/mo and email it to <?= crm_h($client['primary_email'] ?? '?') ?>?')">
              💳 <?= $linkSentAt ? 'Resend payment link' : 'Send payment link' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <details style="margin-top:14px;font-size:13px">
      <summary style="cursor:pointer;color:#6b6877">Line-item breakdown</summary>
      <ul style="margin:8px 0 0;padding:0 0 0 18px;color:#0e0d12">
        <?php foreach ($previewItems as $it): ?>
          <li><?= crm_h($it['name']) ?> — <?= crm_h(crm_fmtMoney((float)$it['monthly'])) ?>/mo</li>
        <?php endforeach; ?>
      </ul>
    </details>
    <div style="margin-top:10px;font-size:11px;color:#6b6877;line-height:1.5">
      🔒 Subscription is set up with a <strong>12-month commitment</strong> per Service Agreement Section 4.
      Stripe Customer Portal is <strong>not</strong> exposed to the client — only you can cancel from this page after month 12.
      Auto-renews for another 12 months unless either party gives 90-day notice.
    </div>
    <?php if ($linkSentAt && !$hasSub): ?>
      <div style="margin-top:10px;font-size:12px;color:#6b6877">
        Last link sent <?= crm_h(crm_fmtRelative($linkSentAt)) ?>
        <?php if (!empty($client['stripe_checkout_url'])): ?>
          · <a href="<?= crm_h($client['stripe_checkout_url']) ?>" target="_blank" rel="noopener" style="color:#6d28d9">Open the live link →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($clientSends): ?>
      <div style="margin-top:14px;border-top:1px solid #e7e4ee;padding-top:14px">
        <div style="font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:8px">Tracked emails sent</div>
        <?php foreach ($clientSends as $s):
          $stat = $s['first_clicked_at'] ? 'clicked' : ($s['first_opened_at'] ? 'opened' : 'sent');
          $statColor = $stat === 'clicked' ? ['#fae8ff','#6b21a8'] : ($stat === 'opened' ? ['#dcfce7','#166534'] : ['#e0e7ff','#3730a3']);
          $statLabel = $stat === 'clicked' ? "Clicked × {$s['click_count']}"
                     : ($stat === 'opened' ? "Opened × {$s['open_count']}" : 'Sent');
        ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0eef5;font-size:13px">
            <div>
              <div style="font-weight:600;color:#0e0d12"><?= crm_h($s['subject']) ?></div>
              <div style="font-size:11px;color:#6b6877">
                <?= crm_h(crm_fmtRelative($s['sent_at'])) ?>
                <?php if ($s['first_opened_at']): ?> · opened <?= crm_h(crm_fmtRelative($s['first_opened_at'])) ?><?php endif; ?>
                <?php if ($s['first_clicked_at']): ?> · clicked <?= crm_h(crm_fmtRelative($s['first_clicked_at'])) ?><?php endif; ?>
              </div>
            </div>
            <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:<?= $statColor[0] ?>;color:<?= $statColor[1] ?>"><?= crm_h($statLabel) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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

  <?php if (($user['role'] ?? '') === 'founder'):
    $hasActiveSub = !empty($client['stripe_subscription_id']);
    $confirmMsg = $hasActiveSub
        ? 'Delete this client AND immediately cancel their active Stripe subscription?\n\nThis will:\n· Cancel Stripe sub ' . substr((string)$client['stripe_subscription_id'], 0, 16) . '… IMMEDIATELY (no period-end grace)\n· Delete the Stripe customer record too\n· Delete the client row + all client_events\n· The original lead (if any) survives\n\nCannot be undone.'
        : 'Delete this client permanently?\n\nThis removes the client row + all client_events. The original lead (if any) survives.\n\nCannot be undone.';
  ?>
  <form class="card" method="post" action="/crm/update.php"
        onsubmit="return confirm(<?= json_encode($confirmMsg) ?>)"
        style="border:1px solid #fecaca;background:#fffafa;margin-top:14px">
    <h2 style="color:#991b1b;margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.08em">Danger zone</h2>
    <p style="font-size:13px;color:#6b6877;margin:0 0 10px">
      <?php if ($hasActiveSub): ?>
        Will <strong>immediately cancel</strong> the active Stripe subscription + delete the customer + delete this client row. Use for test data cleanup. For real cancellations, use "Cancel subscription" above (period-end graceful cancel).
      <?php else: ?>
        Use for test data or off-platform mistakes. No Stripe activity to cancel.
      <?php endif; ?>
    </p>
    <input type="hidden" name="mode" value="client_delete">
    <input type="hidden" name="id" value="<?= (int)$client['id'] ?>">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
    <button type="submit" style="background:#dc2626;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer">🗑 Delete client<?= $hasActiveSub ? ' + cancel subscription' : '' ?></button>
  </form>
  <?php endif; ?>
</main>
</body></html>
