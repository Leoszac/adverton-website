<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/instantly.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$action = (string)($_POST['action'] ?? '');
$flash = (string)($_GET['msg'] ?? '');
$flashErr = (string)($_GET['err'] ?? '');

// Activate / Pause campaign actions
if ($action && in_array($action, ['activate','pause'], true) && !empty($_POST['campaign_id'])) {
    $cid = (string)$_POST['campaign_id'];
    $r = crm_instantlyRequest('POST', '/campaigns/' . urlencode($cid) . '/' . $action);
    if ($r['ok']) {
        header('Location: /crm/cold-outbound.php?msg=' . urlencode("Campaign $action ok"));
    } else {
        header('Location: /crm/cold-outbound.php?err=' . urlencode($r['error'] ?? 'API error'));
    }
    exit;
}

// --- Pull data ---

// Campaigns + analytics
$campaignsResp = crm_instantlyListCampaigns(50);
$campaigns = $campaignsResp['items'] ?? [];
$campaignAnalytics = [];
foreach ($campaigns as $c) {
    if (empty($c['id'])) continue;
    $a = crm_instantlyRequest('GET', '/campaigns/analytics', ['id' => $c['id']]);
    if ($a['ok']) {
        $first = $a['data'][0] ?? $a['data'] ?? [];
        $campaignAnalytics[$c['id']] = $first;
    }
}

// Mailbox health snapshot (cached by hourly cron — fast read)
$health = crm_instantlyLoadHealthSnapshot();
$mailboxes = $health['accounts'] ?? [];

// Hot leads from CRM (source = cold_email_instantly + status indicates engagement)
$db = crm_db();
$hotLeads = [];
try {
    $stmt = $db->prepare(
        "SELECT id,
                TRIM(CONCAT_WS(' ', first_name, last_name)) AS contact_name,
                email,
                business_name,
                status,
                updated_at,
                created_at
         FROM leads
         WHERE source = 'cold_email_instantly'
           AND status IN ('new','contacted','qualified','proposal')
         ORDER BY updated_at DESC
         LIMIT 25"
    );
    $stmt->execute();
    $hotLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Schema not yet allowing 'cold_email_instantly' — graceful empty
    $hotLeads = [];
}

// Funnel: monthly aggregates
$monthStart = date('Y-m-01 00:00:00');
$funnel = ['sent'=>0, 'replies'=>0, 'demos'=>0, 'closes'=>0, 'mrr'=>0.0];

// Sent + replies from Instantly analytics (sum across campaigns)
foreach ($campaignAnalytics as $a) {
    $funnel['sent']    += (int)($a['emails_sent_count'] ?? 0);
    $funnel['replies'] += (int)($a['reply_count'] ?? 0);
}
// Demos booked: leads from cold email that got tagged proposal/qualified this month
try {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM leads
         WHERE source='cold_email_instantly'
           AND status IN ('proposal','qualified')
           AND created_at >= ?"
    );
    $stmt->execute([$monthStart]);
    $funnel['demos'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// Closes + MRR: clients with originating lead from cold email this month.
// MRR is computed per-client via crm_clientMrr() since there's no flat column.
try {
    $stmt = $db->prepare(
        "SELECT c.*
         FROM clients c
         JOIN leads l ON l.id = c.lead_id
         WHERE l.source = 'cold_email_instantly'
           AND c.created_at >= ?"
    );
    $stmt->execute([$monthStart]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $client) {
        $funnel['closes']++;
        $funnel['mrr'] += crm_clientMrr($client);
    }
} catch (Throwable $e) {}

// Total bounces + unsubs across active campaigns
$totalBounced = $totalUnsub = $totalLeads = 0;
foreach ($campaignAnalytics as $a) {
    $totalBounced += (int)($a['bounced_count'] ?? 0);
    $totalUnsub   += (int)($a['unsubscribed_count'] ?? 0);
    $totalLeads   += (int)($a['leads_count'] ?? 0);
}
$bounceRate = $funnel['sent'] > 0 ? ($totalBounced / $funnel['sent'] * 100) : 0;
$replyRate  = $funnel['sent'] > 0 ? ($funnel['replies'] / $funnel['sent'] * 100) : 0;

crm_renderHead('Cold Outbound');
crm_renderHeader($user, 'cold-outbound');
?>
<style>
  .co-flash{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .co-flash.ok{background:#dcfce7;color:#166534}
  .co-flash.err{background:#fee2e2;color:#991b1b}

  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
  @media (max-width:720px){.kpis{grid-template-columns:repeat(2,1fr)}}
  .kpi{background:#fff;border:1px solid #e7e4ee;border-radius:10px;padding:12px}
  .kpi .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  .kpi .value{font-size:22px;font-weight:800;letter-spacing:-.01em;color:#0e0d12;margin-top:3px;font-variant-numeric:tabular-nums}
  .kpi .sub{font-size:11px;color:#6b6877;margin-top:2px}
  .kpi.warn .value{color:#dc2626}
  .kpi.ok .value{color:#16a34a}

  .section-title{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:#6b6877;font-weight:700;margin:22px 0 8px}

  .camp{background:#fff;border:1px solid #e7e4ee;border-radius:10px;padding:14px;margin-bottom:10px}
  .camp-head{display:flex;justify-content:space-between;align-items:start;gap:10px;flex-wrap:wrap}
  .camp-name{font-weight:700;font-size:15px}
  .camp-meta{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:13px;color:#3a3744}
  .camp-meta b{font-variant-numeric:tabular-nums}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
  .pill.s-active{background:#dcfce7;color:#166534}
  .pill.s-draft{background:#e5e7eb;color:#374151}
  .pill.s-paused{background:#fef3c7;color:#92400e}
  .pill.s-other{background:#fce7f3;color:#9d174d}
  .pill.br-ok{background:#dcfce7;color:#166534}
  .pill.br-mid{background:#fef3c7;color:#92400e}
  .pill.br-bad{background:#fee2e2;color:#991b1b}
  .camp-actions{display:flex;gap:6px;flex-wrap:wrap}
  .btn{background:#6d28d9;color:#fff;border:0;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
  .btn.gray{background:#e5e7eb;color:#374151}
  .btn.warn{background:#dc2626}
  .progress{height:6px;background:#f0eef5;border-radius:99px;overflow:hidden;margin-top:10px}
  .progress > div{height:100%;background:linear-gradient(90deg,#6d28d9,#9333ea)}

  .leads-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e7e4ee;border-radius:10px;overflow:hidden}
  .leads-table th{background:#faf9ff;text-align:left;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;padding:9px 12px;border-bottom:1px solid #e7e4ee}
  .leads-table td{padding:9px 12px;border-bottom:1px solid #f0eef5;font-size:13px;vertical-align:top}
  .leads-table tr:last-child td{border-bottom:0}
  .leads-table a{color:#6d28d9;text-decoration:none;font-weight:600}
  .empty{padding:24px;text-align:center;color:#6b6877;background:#fff;border:1px solid #e7e4ee;border-radius:10px;font-size:13px}

  .funnel{background:#fff;border:1px solid #e7e4ee;border-radius:10px;padding:16px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
  @media (max-width:720px){.funnel{grid-template-columns:repeat(2,1fr)}}
  .funnel .step{text-align:center;padding:8px}
  .funnel .step .v{font-size:24px;font-weight:800;color:#0e0d12;font-variant-numeric:tabular-nums}
  .funnel .step .l{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:600;margin-top:4px}
  .funnel .step .rate{font-size:11px;color:#6d28d9;font-weight:700;margin-top:2px}

  .mbx-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e7e4ee;border-radius:10px;overflow:hidden;font-size:13px}
  .mbx-table th{background:#faf9ff;text-align:left;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;padding:8px 12px}
  .mbx-table td{padding:8px 12px;border-top:1px solid #f0eef5;font-variant-numeric:tabular-nums}
  .mbx-table .ok{color:#16a34a;font-weight:700}
  .mbx-table .bad{color:#dc2626;font-weight:700}
</style>

<main>
  <?php if ($flash): ?><div class="co-flash ok"><?= crm_h($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="co-flash err"><?= crm_h($flashErr) ?></div><?php endif; ?>

  <!-- TOP KPIs -->
  <div class="kpis">
    <div class="kpi">
      <div class="label">Total prospects</div>
      <div class="value"><?= number_format($totalLeads) ?></div>
      <div class="sub"><?= count($campaigns) ?> campañas</div>
    </div>
    <div class="kpi">
      <div class="label">Emails enviados</div>
      <div class="value"><?= number_format($funnel['sent']) ?></div>
      <div class="sub">mes en curso</div>
    </div>
    <div class="kpi <?= $bounceRate > 10 ? 'warn' : ($bounceRate < 3 ? 'ok' : '') ?>">
      <div class="label">Bounce rate</div>
      <div class="value"><?= number_format($bounceRate, 1) ?>%</div>
      <div class="sub"><?= $totalBounced ?> bounces · meta &lt;5%</div>
    </div>
    <div class="kpi <?= $replyRate >= 2 ? 'ok' : '' ?>">
      <div class="label">Reply rate</div>
      <div class="value"><?= number_format($replyRate, 1) ?>%</div>
      <div class="sub"><?= $funnel['replies'] ?> replies · meta &gt;2%</div>
    </div>
  </div>

  <!-- FUNNEL -->
  <div class="section-title">Funnel del mes</div>
  <div class="funnel">
    <div class="step">
      <div class="v"><?= number_format($funnel['sent']) ?></div>
      <div class="l">Sent</div>
    </div>
    <div class="step">
      <div class="v"><?= number_format($funnel['replies']) ?></div>
      <div class="l">Replies</div>
      <div class="rate"><?= number_format($replyRate, 1) ?>%</div>
    </div>
    <div class="step">
      <div class="v"><?= number_format($funnel['demos']) ?></div>
      <div class="l">Demos</div>
      <?php $r1 = $funnel['replies'] > 0 ? $funnel['demos']/$funnel['replies']*100 : 0; ?>
      <div class="rate"><?= number_format($r1, 0) ?>% from replies</div>
    </div>
    <div class="step">
      <div class="v"><?= number_format($funnel['closes']) ?></div>
      <div class="l">Closes · <?= crm_h(crm_fmtMoney($funnel['mrr'])) ?>/mo</div>
      <?php $r2 = $funnel['demos'] > 0 ? $funnel['closes']/$funnel['demos']*100 : 0; ?>
      <div class="rate"><?= number_format($r2, 0) ?>% from demos</div>
    </div>
  </div>

  <!-- CAMPAIGNS -->
  <div class="section-title">Campañas</div>
  <?php if (!$campaigns): ?>
    <div class="empty">No hay campañas en Instantly. Si configuraste la API key, debería aparecer acá.</div>
  <?php else: ?>
    <?php foreach ($campaigns as $c):
      $cid = $c['id'] ?? '';
      $st  = (int)($c['status'] ?? 0);
      $a   = $campaignAnalytics[$cid] ?? [];
      $sent = (int)($a['emails_sent_count'] ?? 0);
      $bounced = (int)($a['bounced_count'] ?? 0);
      $replies = (int)($a['reply_count'] ?? 0);
      $unsubs  = (int)($a['unsubscribed_count'] ?? 0);
      $leads   = (int)($a['leads_count'] ?? 0);
      $contacted = (int)($a['contacted_count'] ?? 0);
      $br = $sent > 0 ? ($bounced / $sent * 100) : 0;
      $rr = $sent > 0 ? ($replies / $sent * 100) : 0;
      $progress = $leads > 0 ? min(100, $contacted / $leads * 100) : 0;
      $stClass = ['s-draft','s-active','s-paused'][$st] ?? 's-other';
      $stLabel = ['Draft','Active','Paused'][$st] ?? 'Status '.$st;
      $brClass = $br > 10 ? 'br-bad' : ($br > 5 ? 'br-mid' : 'br-ok');
    ?>
    <div class="camp">
      <div class="camp-head">
        <div>
          <div class="camp-name"><?= crm_h($c['name'] ?? 'Untitled') ?></div>
          <div style="margin-top:4px"><span class="pill <?= $stClass ?>"><?= $stLabel ?></span></div>
        </div>
        <div class="camp-actions">
          <?php if ($st === 0 || $st === 2): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Activar campaign? Va a empezar a enviar emails en la próxima ventana del schedule.')">
              <input type="hidden" name="campaign_id" value="<?= crm_h($cid) ?>">
              <input type="hidden" name="action" value="activate">
              <button class="btn" type="submit">Activate</button>
            </form>
          <?php endif; ?>
          <?php if ($st === 1): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Pausar campaign? Deja de enviar pero no borra leads ni progreso.')">
              <input type="hidden" name="campaign_id" value="<?= crm_h($cid) ?>">
              <input type="hidden" name="action" value="pause">
              <button class="btn warn" type="submit">Pause</button>
            </form>
          <?php endif; ?>
          <a class="btn gray" target="_blank" rel="noopener" href="https://app.instantly.ai/app/campaign/<?= crm_h($cid) ?>/leads">Open in Instantly →</a>
        </div>
      </div>
      <div class="camp-meta">
        <span><b><?= number_format($leads) ?></b> leads</span>
        <span><b><?= number_format($sent) ?></b> sent</span>
        <span><b><?= number_format($replies) ?></b> replies (<?= number_format($rr, 1) ?>%)</span>
        <span class="pill <?= $brClass ?>"><?= number_format($br, 1) ?>% bounce</span>
        <?php if ($unsubs): ?><span><b><?= $unsubs ?></b> unsub</span><?php endif; ?>
      </div>
      <?php if ($progress > 0): ?>
        <div class="progress"><div style="width:<?= $progress ?>%"></div></div>
        <div style="font-size:11px;color:#6b6877;margin-top:4px"><?= number_format($progress, 0) ?>% contacted</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- HOT LEADS -->
  <div class="section-title">Hot leads pendientes (<?= count($hotLeads) ?>)</div>
  <?php if (!$hotLeads): ?>
    <div class="empty">Sin replies positivos todavía. Cuando un prospect responde, aparece acá automáticamente vía webhook.</div>
  <?php else: ?>
    <table class="leads-table">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Empresa</th>
          <th>Email</th>
          <th>Status</th>
          <th>Última actividad</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hotLeads as $l):
          $last = $l['updated_at'] ?: $l['created_at'];
          $name = $l['contact_name'] !== '' ? $l['contact_name'] : '—';
        ?>
        <tr>
          <td><?= crm_h($name) ?></td>
          <td><?= crm_h($l['business_name'] ?? '') ?></td>
          <td><?= crm_h($l['email'] ?? '') ?></td>
          <td><span class="pill s-other"><?= crm_h($l['status']) ?></span></td>
          <td><?= crm_h(crm_fmtRelative($last)) ?></td>
          <td><a href="/crm/lead.php?id=<?= (int)$l['id'] ?>">Ver →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- MAILBOX HEALTH -->
  <div class="section-title">Mailbox health</div>
  <?php if (!$mailboxes): ?>
    <div class="empty">Snapshot horario aún no corrió. cron-instantly-health.php se ejecuta cada hora.</div>
  <?php else: ?>
    <table class="mbx-table">
      <thead>
        <tr><th>Email</th><th>Status</th><th>Warmup score</th><th>Warmup label</th><th>Setup</th></tr>
      </thead>
      <tbody>
        <?php foreach ($mailboxes as $m):
          $ws  = (int)($m['warmup_score'] ?? 0);
          $st  = $m['status_label'] ?? '?';
          $wl  = $m['warmup_label'] ?? '?';
          $cls = $ws >= 85 ? 'ok' : 'bad';
          $stcls = $st === 'active' ? 'ok' : ($st === 'soft_bounce_error' ? 'bad' : '');
        ?>
        <tr>
          <td><?= crm_h($m['email'] ?? '') ?></td>
          <td class="<?= $stcls ?>"><?= crm_h($st) ?></td>
          <td class="<?= $cls ?>"><?= $ws ?>%</td>
          <td><?= crm_h($wl) ?></td>
          <td><?= !empty($m['setup_pending']) ? '<span class="bad">PENDING</span>' : 'ok' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!empty($health['snapshot_at'])): ?>
      <div style="font-size:11px;color:#6b6877;margin-top:6px;text-align:right">Snapshot: <?= crm_h(crm_fmtRelative($health['snapshot_at'])) ?></div>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body></html>
