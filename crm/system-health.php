<?php
// Operational health dashboard. Two sections:
//   1. Payments — clients in past_due / cancelled, recent failure events
//   2. Asset pipeline — photos pending classification, stuck, unapproved
//
// Read-only; every row links into the relevant client page for action.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']);

$db = crm_db();

// ─── PAYMENTS ─────────────────────────────────────────────────────────

// Clients currently past_due or cancelled — actionable now.
// Filter out billing_mode != 'stripe' since barter/comp clients don't go
// through Stripe and can't be past_due in the Stripe sense.
$pastDue = $db->query(
    "SELECT c.id, c.business_name, c.payment_status, c.account_manager_id,
            u.display_name AS am_name,
            (SELECT MAX(created_at) FROM client_events
             WHERE client_id = c.id AND type = 'payment_failed') AS last_fail_at
     FROM clients c
     LEFT JOIN users u ON u.id = c.account_manager_id
     WHERE c.payment_status = 'past_due'
       AND COALESCE(c.billing_mode,'stripe') = 'stripe'
     ORDER BY last_fail_at DESC"
)->fetchAll();

$cancelled = $db->query(
    "SELECT c.id, c.business_name, c.payment_status, c.updated_at,
            (SELECT MAX(created_at) FROM client_events
             WHERE client_id = c.id AND type = 'status_change'
               AND body LIKE '%cancelled%') AS cancelled_at
     FROM clients c
     WHERE c.payment_status = 'cancelled'
       AND COALESCE(c.billing_mode,'stripe') = 'stripe'
       AND c.updated_at > DATE_SUB(NOW(), INTERVAL 60 DAY)
     ORDER BY c.updated_at DESC
     LIMIT 30"
)->fetchAll();

// Recent payment-related events (last 30d) across all clients
$recentEvents = $db->query(
    "SELECT e.client_id, e.type, e.body, e.created_at,
            c.business_name
     FROM client_events e
     JOIN clients c ON c.id = e.client_id
     WHERE e.type IN ('payment_failed','status_change','subscription_changed')
       AND e.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
     ORDER BY e.created_at DESC
     LIMIT 25"
)->fetchAll();

// ─── ASSETS ───────────────────────────────────────────────────────────

// Pending classification (cron picks ai_description IS NULL)
$pendingPerClient = $db->query(
    "SELECT a.client_id, c.business_name,
            COUNT(*) AS pending,
            MIN(a.uploaded_at) AS oldest_uploaded_at
     FROM client_assets a
     JOIN clients c ON c.id = a.client_id
     WHERE a.ai_description IS NULL
     GROUP BY a.client_id, c.business_name
     ORDER BY oldest_uploaded_at ASC
     LIMIT 30"
)->fetchAll();

$pendingTotal = $db->query(
    'SELECT COUNT(*) AS n FROM client_assets WHERE ai_description IS NULL'
)->fetch();
$pendingTotal = (int)($pendingTotal['n'] ?? 0);

// Stuck — pending for over an hour (cron runs every 5 min, so >1h is anomalous)
$stuckPerClient = $db->query(
    "SELECT a.client_id, c.business_name,
            COUNT(*) AS stuck,
            MIN(a.uploaded_at) AS oldest_uploaded_at
     FROM client_assets a
     JOIN clients c ON c.id = a.client_id
     WHERE a.ai_description IS NULL
       AND a.uploaded_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
     GROUP BY a.client_id, c.business_name
     ORDER BY oldest_uploaded_at ASC
     LIMIT 30"
)->fetchAll();

// Unapproved — classified but operator hasn't approved for use on site
$unapprovedPerClient = $db->query(
    "SELECT a.client_id, c.business_name,
            COUNT(*) AS classified_unapproved,
            COUNT(CASE WHEN a.approved = 1 THEN 1 END) AS approved_count,
            COUNT(*) AS total_classified
     FROM client_assets a
     JOIN clients c ON c.id = a.client_id
     WHERE a.ai_description IS NOT NULL
       AND a.approved = 0
     GROUP BY a.client_id, c.business_name
     ORDER BY classified_unapproved DESC
     LIMIT 30"
)->fetchAll();

crm_renderHead('Health');
crm_renderHeader($user, 'health');
?>
<style>
  main{max-width:1100px}
  h1{margin:0 0 16px;font-size:22px;font-weight:700}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media(max-width:880px){.grid2{grid-template-columns:1fr}}
  .panel{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:16px}
  .panel h2{margin:0 0 4px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700;display:flex;align-items:center;gap:8px}
  .panel h2 .count{background:#f3f1f8;color:#0e0d12;font-size:12px;padding:1px 8px;border-radius:999px;font-weight:700}
  .panel h2 .count.danger{background:#fee2e2;color:#991b1b}
  .panel h2 .count.warn{background:#fef3c7;color:#92400e}
  .panel h2 .count.ok{background:#dcfce7;color:#166534}
  .panel .hint{color:#6b6877;font-size:12px;margin:0 0 10px}
  .row{display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid #f3f1f8;font-size:14px}
  .row:first-of-type{border-top:0}
  .row .name{font-weight:600;color:#0e0d12;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .row .meta{color:#6b6877;font-size:12px;text-align:right;white-space:nowrap}
  .row a{color:#6d28d9;text-decoration:none;font-weight:600;font-size:12px}
  .empty{padding:24px;text-align:center;color:#6b6877;font-size:13px;background:#faf9ff;border-radius:8px}
  .badge{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .b-pastdue{background:#fef3c7;color:#92400e}
  .b-cancelled{background:#fee2e2;color:#991b1b}
  .b-fail{background:#fee2e2;color:#991b1b}
  .b-change{background:#fae8ff;color:#6b21a8}
  .b-update{background:#dbeafe;color:#1e40af}
</style>
<main>
  <h1>System health</h1>

  <!-- ── PAYMENTS ────────────────────────────────────────────── -->
  <div class="panel">
    <h2>Payments · past due
      <span class="count <?= count($pastDue) ? 'danger' : 'ok' ?>"><?= count($pastDue) ?></span>
    </h2>
    <p class="hint">Clients with a failed payment. Stripe retries automatically; call them anyway after 48h of past_due.</p>
    <?php if (!$pastDue): ?>
      <div class="empty">No past-due clients. 🎉</div>
    <?php else: foreach ($pastDue as $r): ?>
      <div class="row">
        <span class="badge b-pastdue">past due</span>
        <div class="name"><?= crm_h($r['business_name']) ?></div>
        <div class="meta">
          <?php if (!empty($r['last_fail_at'])): ?>
            <?= crm_h(crm_fmtRelative($r['last_fail_at'])) ?>
          <?php endif; ?>
          <?php if (!empty($r['am_name'])): ?> · <?= crm_h($r['am_name']) ?><?php endif; ?>
        </div>
        <a href="/crm/client.php?id=<?= (int)$r['id'] ?>">Open →</a>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="grid2">
    <div class="panel">
      <h2>Recently cancelled · last 60d
        <span class="count <?= count($cancelled) ? 'warn' : 'ok' ?>"><?= count($cancelled) ?></span>
      </h2>
      <?php if (!$cancelled): ?>
        <div class="empty">No recent cancellations.</div>
      <?php else: foreach ($cancelled as $r): ?>
        <div class="row">
          <span class="badge b-cancelled">cancelled</span>
          <div class="name"><?= crm_h($r['business_name']) ?></div>
          <div class="meta"><?= crm_h(crm_fmtRelative($r['cancelled_at'] ?: $r['updated_at'])) ?></div>
          <a href="/crm/client.php?id=<?= (int)$r['id'] ?>">Open →</a>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="panel">
      <h2>Recent payment events · last 30d
        <span class="count"><?= count($recentEvents) ?></span>
      </h2>
      <p class="hint">Every payment-related Stripe webhook event, newest first.</p>
      <?php if (!$recentEvents): ?>
        <div class="empty">No recent payment events.</div>
      <?php else: foreach ($recentEvents as $e):
        $cls = match ($e['type']) {
            'payment_failed'        => 'b-fail',
            'status_change'         => 'b-change',
            'subscription_changed'  => 'b-update',
            default                 => 'b-update',
        };
      ?>
        <div class="row">
          <span class="badge <?= $cls ?>"><?= crm_h(str_replace('_', ' ', $e['type'])) ?></span>
          <div class="name">
            <?= crm_h($e['business_name']) ?>
            <div style="font-size:12px;font-weight:400;color:#6b6877;margin-top:2px"><?= crm_h(mb_substr((string)$e['body'], 0, 80)) ?></div>
          </div>
          <div class="meta"><?= crm_h(crm_fmtRelative($e['created_at'])) ?></div>
          <a href="/crm/client.php?id=<?= (int)$e['client_id'] ?>">→</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── ASSETS ────────────────────────────────────────────── -->
  <div class="panel">
    <h2>Asset pipeline · pending classification
      <span class="count <?= $pendingTotal > 50 ? 'warn' : '' ?>"><?= $pendingTotal ?> total</span>
    </h2>
    <p class="hint">Photos waiting for Anthropic Vision classification. Cron runs every 5 min, processes 20 per batch.</p>
    <?php if (!$pendingPerClient): ?>
      <div class="empty">Queue is empty. All uploaded photos have been classified.</div>
    <?php else: foreach ($pendingPerClient as $r): ?>
      <div class="row">
        <div class="name"><?= crm_h($r['business_name']) ?></div>
        <div class="meta">
          <?= (int)$r['pending'] ?> pending · oldest <?= crm_h(crm_fmtRelative($r['oldest_uploaded_at'])) ?>
        </div>
        <a href="/crm/client.php?id=<?= (int)$r['client_id'] ?>#assets">Open →</a>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="grid2">
    <div class="panel">
      <h2>Stuck · pending > 1h
        <span class="count <?= count($stuckPerClient) ? 'danger' : 'ok' ?>"><?= count($stuckPerClient) ?></span>
      </h2>
      <p class="hint">Cron should clear pending in &lt;5 min. Anything stuck &gt;1h means the cron is dead, the AI vision call is failing, or the image is corrupt.</p>
      <?php if (!$stuckPerClient): ?>
        <div class="empty">No stuck assets.</div>
      <?php else: foreach ($stuckPerClient as $r): ?>
        <div class="row">
          <div class="name"><?= crm_h($r['business_name']) ?></div>
          <div class="meta">
            <?= (int)$r['stuck'] ?> stuck · oldest <?= crm_h(crm_fmtRelative($r['oldest_uploaded_at'])) ?>
          </div>
          <a href="/crm/client.php?id=<?= (int)$r['client_id'] ?>#assets">Open →</a>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="panel">
      <h2>Awaiting approval
        <span class="count"><?= count($unapprovedPerClient) ?></span>
      </h2>
      <p class="hint">Photos classified but not approved for use on the website. Approve from client.php → Assets.</p>
      <?php if (!$unapprovedPerClient): ?>
        <div class="empty">No assets waiting for approval.</div>
      <?php else: foreach ($unapprovedPerClient as $r): ?>
        <div class="row">
          <div class="name"><?= crm_h($r['business_name']) ?></div>
          <div class="meta">
            <?= (int)$r['classified_unapproved'] ?> unapproved
          </div>
          <a href="/crm/client.php?id=<?= (int)$r['client_id'] ?>#assets">Open →</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</main>
</body></html>
