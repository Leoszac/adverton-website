<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$db   = crm_db();

// ---- Counts: this week / this month / total ----
$counts = [];
foreach ([
    ['key'=>'leads_today',    'sql'=>"SELECT COUNT(*) AS n FROM leads WHERE DATE(created_at) = CURDATE()"],
    ['key'=>'leads_week',     'sql'=>"SELECT COUNT(*) AS n FROM leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"],
    ['key'=>'leads_month',    'sql'=>"SELECT COUNT(*) AS n FROM leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"],
    ['key'=>'won_month',      'sql'=>"SELECT COUNT(*) AS n FROM leads WHERE status='won' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"],
    ['key'=>'lost_month',     'sql'=>"SELECT COUNT(*) AS n FROM leads WHERE status='lost' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"],
    ['key'=>'open_pipeline',  'sql'=>"SELECT COUNT(*) AS n FROM leads WHERE status NOT IN ('won','lost')"],
] as $c) {
    $counts[$c['key']] = (int) $db->query($c['sql'])->fetch()['n'];
}

// ---- MRR by stage ----
$mrrByStage = [];
$stageRows = $db->query(
    "SELECT status, COUNT(*) AS n,
            SUM(IFNULL(monthly_fee,0) + IFNULL(ad_budget,0)*IFNULL(mgmt_fee_pct,0)/100) AS mrr
     FROM leads GROUP BY status"
)->fetchAll();
foreach (CRM_LEAD_STATUSES as $s) $mrrByStage[$s] = ['n'=>0,'mrr'=>0];
foreach ($stageRows as $r) {
    $mrrByStage[$r['status']] = ['n' => (int)$r['n'], 'mrr' => (float)($r['mrr'] ?? 0)];
}
$openMrr      = 0;
foreach (['new','contacted','qualified','proposal'] as $s) $openMrr += $mrrByStage[$s]['mrr'];
$committedMrr = $mrrByStage['won']['mrr'];

// ---- Conversion rates by stage ----
// Defined as: of all leads that EVER passed through stage X (currently in X or any later stage),
// what fraction reached "won". Approximated using current status distribution.
$totalLeads = array_sum(array_column($mrrByStage, 'n'));
$wonCount = $mrrByStage['won']['n'];
$convOverall = $totalLeads > 0 ? ($wonCount / $totalLeads) : 0;

$stageOrder = ['new','contacted','qualified','proposal','won'];
$convByStage = [];
$cumulativeAfter = $totalLeads;
foreach ($stageOrder as $s) {
    if ($cumulativeAfter > 0) {
        $convByStage[$s] = $wonCount / $cumulativeAfter;
    } else {
        $convByStage[$s] = 0;
    }
    $cumulativeAfter -= $mrrByStage[$s]['n'];
}

// ---- Activity per user this week ----
$actRows = $db->query(
    "SELECT u.id, u.display_name,
            SUM(CASE WHEN a.type='call'  THEN 1 ELSE 0 END) AS calls,
            SUM(CASE WHEN a.type='email' THEN 1 ELSE 0 END) AS emails,
            SUM(CASE WHEN a.type='sms'   THEN 1 ELSE 0 END) AS smses,
            SUM(CASE WHEN a.type='note'  THEN 1 ELSE 0 END) AS notes,
            COUNT(*) AS total
     FROM users u
     LEFT JOIN lead_activities a ON a.user_id = u.id
       AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
       AND a.type IN ('call','email','sms','note')
     GROUP BY u.id, u.display_name
     ORDER BY total DESC"
)->fetchAll();

// ---- Source breakdown ----
$sourceRows = $db->query(
    "SELECT source, COUNT(*) AS n,
            SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) AS won
     FROM leads GROUP BY source"
)->fetchAll();

// ---- New leads per day, last 14 ----
$dayRows = $db->query(
    "SELECT DATE(created_at) AS d, COUNT(*) AS n
     FROM leads
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(created_at) ORDER BY d ASC"
)->fetchAll();
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $days[$d] = 0;
}
foreach ($dayRows as $r) $days[$r['d']] = (int)$r['n'];
$maxDay = max(1, max($days));

// ---- Pipeline funnel (counts at each stage) ----
$funnel = [];
foreach ($stageOrder as $s) {
    $funnel[$s] = $mrrByStage[$s]['n'];
}
$funnelMax = max(1, max($funnel));

crm_renderHead('Reports');
crm_renderHeader($user, 'reports');
?>
<style>
  .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
  @media (max-width:880px){ .kpis{grid-template-columns:repeat(2,1fr)} }
  .kpi{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:16px}
  .kpi .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700;margin-bottom:6px}
  .kpi .value{font-size:24px;font-weight:800;letter-spacing:-.01em;color:#0e0d12}
  .kpi .sub{font-size:12px;color:#6b6877;margin-top:2px}
  .kpi.ok .value{color:#16a34a}
  .kpi.warn .value{color:#dc2626}

  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:880px){ .grid2{grid-template-columns:1fr} }
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  .card h2{margin:0 0 14px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}

  .bar{display:grid;grid-template-columns:140px 1fr 80px;gap:10px;align-items:center;margin-bottom:8px}
  .bar .lbl{font-size:13px;color:#0e0d12;font-weight:600}
  .bar .track{background:#f0eef5;border-radius:6px;height:10px;overflow:hidden}
  .bar .fill{height:100%;border-radius:6px}
  .bar .v{font-size:13px;color:#6b6877;text-align:right;font-variant-numeric:tabular-nums}

  .funnel .bar .fill{background:#6d28d9}
  .stage-mrr .bar .fill.s-new{background:#3730a3}
  .stage-mrr .bar .fill.s-contacted{background:#92400e}
  .stage-mrr .bar .fill.s-qualified{background:#166534}
  .stage-mrr .bar .fill.s-proposal{background:#6b21a8}
  .stage-mrr .bar .fill.s-won{background:#16a34a}
  .stage-mrr .bar .fill.s-lost{background:#991b1b}

  table{width:100%;border-collapse:collapse}
  th,td{padding:8px 0;text-align:left;font-size:13px;border-bottom:1px solid #f0eef5}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
  tr:last-child td{border-bottom:0}

  .spark{display:grid;grid-template-columns:repeat(14,1fr);gap:3px;align-items:end;height:80px;margin-top:8px}
  .spark .b{background:#6d28d9;border-radius:3px 3px 0 0;min-height:2px;display:block}
  .spark-x{display:grid;grid-template-columns:repeat(14,1fr);gap:3px;font-size:10px;color:#6b6877;margin-top:4px;text-align:center}
</style>
<main>
  <div class="kpis">
    <div class="kpi"><div class="label">Open pipeline value</div>
      <div class="value"><?= crm_h(crm_fmtMoney($openMrr)) ?>/mo</div>
      <div class="sub"><?= $counts['open_pipeline'] ?> active leads</div></div>
    <div class="kpi ok"><div class="label">Committed (won)</div>
      <div class="value"><?= crm_h(crm_fmtMoney($committedMrr)) ?>/mo</div>
      <div class="sub"><?= $mrrByStage['won']['n'] ?> total clients</div></div>
    <div class="kpi"><div class="label">Leads · 30 days</div>
      <div class="value"><?= $counts['leads_month'] ?></div>
      <div class="sub"><?= $counts['leads_week'] ?> last 7d · <?= $counts['leads_today'] ?> today</div></div>
    <div class="kpi <?= $convOverall>=0.1?'ok':'' ?>"><div class="label">Conversion</div>
      <div class="value"><?= $totalLeads ? number_format($convOverall*100,1) : '0' ?>%</div>
      <div class="sub"><?= $wonCount ?> won / <?= $totalLeads ?> total</div></div>
  </div>

  <div class="grid2">
    <div class="card stage-mrr">
      <h2>Pipeline value by stage</h2>
      <?php
        $maxStageMrr = 0;
        foreach (['new','contacted','qualified','proposal'] as $s) $maxStageMrr = max($maxStageMrr, $mrrByStage[$s]['mrr']);
        $maxStageMrr = max(1, $maxStageMrr);
        foreach (['new','contacted','qualified','proposal','won'] as $s):
            $w = $mrrByStage[$s]['mrr'] / $maxStageMrr * 100;
      ?>
        <div class="bar">
          <span class="lbl"><?= ucfirst($s) ?> <span style="color:#6b6877;font-weight:500">(<?= $mrrByStage[$s]['n'] ?>)</span></span>
          <div class="track"><div class="fill s-<?= $s ?>" style="width:<?= number_format($w,1) ?>%"></div></div>
          <span class="v"><?= crm_h(crm_fmtMoney($mrrByStage[$s]['mrr'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card funnel">
      <h2>Funnel · counts</h2>
      <?php foreach ($stageOrder as $s):
        $w = $funnel[$s] / $funnelMax * 100;
      ?>
        <div class="bar">
          <span class="lbl"><?= ucfirst($s) ?></span>
          <div class="track"><div class="fill" style="width:<?= number_format($w,1) ?>%"></div></div>
          <span class="v"><?= $funnel[$s] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>New leads · last 14 days</h2>
      <div class="spark">
        <?php foreach ($days as $d => $n): $h = ($n / $maxDay) * 80; ?>
          <span class="b" style="height:<?= max(2, (int)$h) ?>px" title="<?= $d ?>: <?= $n ?>"></span>
        <?php endforeach; ?>
      </div>
      <div class="spark-x">
        <?php $i=0; foreach ($days as $d => $n): $i++; ?>
          <span><?= $i % 2 === 1 ? date('j', strtotime($d)) : '' ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2>Source · last 30 days</h2>
      <table>
        <thead><tr><th>Source</th><th class="num">Leads</th><th class="num">Won</th><th class="num">Conv</th></tr></thead>
        <tbody>
          <?php foreach ($sourceRows as $r):
            $cv = (int)$r['n'] > 0 ? ((int)$r['won'] / (int)$r['n']) : 0;
          ?>
            <tr>
              <td><?= crm_h($r['source']) ?></td>
              <td class="num"><?= (int)$r['n'] ?></td>
              <td class="num"><?= (int)$r['won'] ?></td>
              <td class="num"><?= number_format($cv*100,1) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php
    // Lost reason breakdown (last 90 days, lost only)
    $lostRows = $db->query(
      "SELECT lost_reason, COUNT(*) AS n
       FROM leads
       WHERE status='lost' AND updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
       GROUP BY lost_reason ORDER BY n DESC"
    )->fetchAll();
    $totalLost = array_sum(array_column($lostRows, 'n'));

    // Email engagement (last 30 days)
    $emailStats = $db->query(
      "SELECT COUNT(*) AS sent,
              SUM(CASE WHEN first_opened_at  IS NOT NULL THEN 1 ELSE 0 END) AS opened,
              SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
       FROM email_sends
       WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->fetch();
  ?>

  <div class="grid2">
    <div class="card">
      <h2>Why we lose · last 90 days</h2>
      <?php if (!$totalLost): ?>
        <div style="color:#6b6877;font-size:13px">No lost deals in the last 90 days.</div>
      <?php else: foreach ($lostRows as $r):
        $w = ((int)$r['n'] / max(1, $totalLost)) * 100;
        $label = $r['lost_reason'] ?? 'unspecified';
      ?>
        <div class="bar">
          <span class="lbl"><?= crm_h(ucfirst(str_replace('_', ' ', $label))) ?></span>
          <div class="track"><div class="fill" style="width:<?= number_format($w,1) ?>%;background:#dc2626"></div></div>
          <span class="v"><?= (int)$r['n'] ?> · <?= number_format($w,0) ?>%</span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card">
      <h2>Email engagement · last 30 days</h2>
      <?php $sent = (int)($emailStats['sent'] ?? 0);
            $opened = (int)($emailStats['opened'] ?? 0);
            $clicked = (int)($emailStats['clicked'] ?? 0);
            $openRate = $sent > 0 ? ($opened / $sent * 100) : 0;
            $ctr      = $sent > 0 ? ($clicked / $sent * 100) : 0;
      ?>
      <div class="kpis" style="grid-template-columns:repeat(3,1fr);margin:0">
        <div class="kpi"><div class="label">Sent</div><div class="value"><?= $sent ?></div></div>
        <div class="kpi <?= $openRate>=40?'ok':'' ?>"><div class="label">Open rate</div><div class="value"><?= number_format($openRate,0) ?>%</div><div class="sub"><?= $opened ?> opens</div></div>
        <div class="kpi <?= $ctr>=10?'ok':'' ?>"><div class="label">Click rate</div><div class="value"><?= number_format($ctr,0) ?>%</div><div class="sub"><?= $clicked ?> clicks</div></div>
      </div>
      <p style="font-size:11px;color:#6b6877;margin:10px 0 0">Open rate is approximate (Apple Mail Privacy Protection inflates opens). Clicks are reliable.</p>
    </div>
  </div>

  <?php
    // Weighted forecast for current month
    $stageProb = ['new'=>0.05,'contacted'=>0.15,'qualified'=>0.35,'proposal'=>0.65,'won'=>1.0,'lost'=>0];
    $forecastSql = "SELECT id, status, monthly_fee, ad_budget, mgmt_fee_pct, expected_close_at
                    FROM leads
                    WHERE expected_close_at IS NOT NULL
                      AND expected_close_at >= CURDATE()
                      AND expected_close_at < DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                      AND status NOT IN ('lost')";
    $forecastRows = $db->query($forecastSql)->fetchAll();
    $forecastByMonth = [];
    foreach ($forecastRows as $f) {
        $m = date('Y-m', strtotime((string)$f['expected_close_at']));
        $mrr = crm_leadMrr($f);
        $weighted = $mrr * ($stageProb[$f['status']] ?? 0);
        $forecastByMonth[$m] = ($forecastByMonth[$m] ?? 0) + $weighted;
    }

    // Committed MRR from active clients
    $committedSql = "SELECT SUM(IFNULL(monthly_fee,0) + IFNULL(ad_budget,0)*IFNULL(mgmt_fee_pct,0)/100) AS mrr
                     FROM clients WHERE status IN ('onboarding','active','renewed','past_due')";
    $committed = (float) ($db->query($committedSql)->fetch()['mrr'] ?? 0);

  ?>

  <div class="card">
    <h2>Weighted forecast · next 90 days</h2>
    <?php if (!$forecastByMonth): ?>
      <div style="color:#6b6877;font-size:13px">Set <code>expected_close_at</code> on leads to see forecast.</div>
    <?php else:
      $maxF = max(1, max($forecastByMonth));
      ksort($forecastByMonth);
      foreach ($forecastByMonth as $m => $v):
        $w = $v / $maxF * 100;
    ?>
      <div class="bar">
        <span class="lbl"><?= crm_h(date('M Y', strtotime($m . '-01'))) ?></span>
        <div class="track"><div class="fill" style="width:<?= number_format($w,1) ?>%;background:#16a34a"></div></div>
        <span class="v"><?= crm_h(crm_fmtMoney($v)) ?>/mo</span>
      </div>
    <?php endforeach; endif; ?>
    <p style="font-size:11px;color:#6b6877;margin-top:10px">Probabilities: new 5% · contacted 15% · qualified 35% · proposal 65% · won 100%.</p>
    <p style="font-size:13px;margin-top:14px"><strong>Committed MRR (active clients):</strong> <?= crm_h(crm_fmtMoney($committed)) ?>/mo</p>
  </div>

  <div class="card">
    <h2>Activity by user · last 7 days</h2>
    <table>
      <thead><tr><th>User</th><th class="num">Calls</th><th class="num">Emails</th><th class="num">SMS</th><th class="num">Notes</th><th class="num">Total</th></tr></thead>
      <tbody>
        <?php foreach ($actRows as $r): ?>
          <tr>
            <td><?= crm_h($r['display_name']) ?></td>
            <td class="num"><?= (int)$r['calls']  ?></td>
            <td class="num"><?= (int)$r['emails'] ?></td>
            <td class="num"><?= (int)$r['smses']  ?></td>
            <td class="num"><?= (int)$r['notes']  ?></td>
            <td class="num"><?= (int)$r['total']  ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body></html>
