<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']); // leads role excluded

$filters = [
    'status'         => $_GET['status']         ?? '',
    'payment_status' => $_GET['payment_status'] ?? '',
    'q'              => $_GET['q']              ?? '',
    'expiring_within_days' => $_GET['expiring'] ?? '',
];
if (!empty($_GET['mine'])) $filters['account_manager_id'] = (int)$user['id'];

$rows  = crm_listClients($filters, 200, 0);
$total = count($rows);
$users = crm_listUsers();
$userMap = []; foreach ($users as $u) $userMap[(int)$u['id']] = $u['display_name'];

// Aggregate MRR
$mrrTotal = 0; $atRisk = 0;
foreach ($rows as $c) {
    $mrrTotal += crm_clientMrr($c);
    if (crm_isClientAtRisk($c)) $atRisk++;
}

crm_renderHead('Clients');
crm_renderHeader($user, 'clients');
?>
<style>
  .filters{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px}
  .filters label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:4px}
  .filters select,.filters input[type=text]{background:#fff;border:1px solid #e7e4ee;padding:7px 10px;border-radius:8px;font-size:13px;min-width:120px}
  .filters .grow{flex:1;min-width:200px}
  .filters button{background:#6d28d9;color:#fff;border:0;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
  .quick{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap}
  .quick a{background:#fff;border:1px solid #e7e4ee;border-radius:999px;padding:5px 12px;font-size:12px;color:#0e0d12;text-decoration:none;font-weight:600}
  .quick a.cur{background:#0e0d12;color:#fff;border-color:#0e0d12}

  .kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
  .kpi{background:#fff;border:1px solid #e7e4ee;border-radius:10px;padding:12px}
  .kpi .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  .kpi .value{font-size:22px;font-weight:800;letter-spacing:-.01em;color:#0e0d12;margin-top:3px}
  .kpi.warn .value{color:#dc2626}

  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e7e4ee;border-radius:12px;overflow:hidden}
  th{background:#faf9ff;text-align:left;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;padding:10px 12px;border-bottom:1px solid #e7e4ee}
  td{padding:9px 12px;border-bottom:1px solid #f0eef5;font-size:13.5px;vertical-align:top}
  tr.row{cursor:pointer}
  tr.row:hover{background:#faf9ff}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em}
  .pill.cs-onboarding{background:#fef3c7;color:#92400e}
  .pill.cs-active{background:#dcfce7;color:#166534}
  .pill.cs-past_due{background:#fee2e2;color:#991b1b}
  .pill.cs-paused{background:#e5e7eb;color:#374151}
  .pill.cs-cancelled{background:#fecaca;color:#7f1d1d}
  .pill.cs-renewed{background:#fae8ff;color:#6b21a8}
  .pill.ps-pending{background:#fef3c7;color:#92400e}
  .pill.ps-current{background:#dcfce7;color:#166534}
  .pill.ps-past_due{background:#fee2e2;color:#991b1b}
  .pill.ps-failed{background:#fee2e2;color:#991b1b}
  .pill.ps-cancelled{background:#e5e7eb;color:#374151}
  .health{font-weight:700;font-variant-numeric:tabular-nums}
  .health.ok{color:#16a34a}.health.mid{color:#f59e0b}.health.bad{color:#dc2626}
  .mrr{font-weight:600;font-variant-numeric:tabular-nums}
  .empty{padding:30px;text-align:center;color:#6b6877}
</style>
<main>
  <?php $flash = (string)($_GET['msg'] ?? ''); if ($flash): ?>
    <div style="background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px"><?= crm_h($flash) ?></div>
  <?php endif; ?>
  <div class="kpis">
    <div class="kpi"><div class="label">Active clients</div><div class="value"><?= $total ?></div></div>
    <div class="kpi"><div class="label">Total MRR</div><div class="value"><?= crm_h(crm_fmtMoney($mrrTotal)) ?>/mo</div></div>
    <div class="kpi <?= $atRisk?'warn':'' ?>"><div class="label">At risk</div><div class="value"><?= $atRisk ?></div></div>
  </div>

  <div class="quick" style="justify-content:space-between">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
    <a href="/crm/clients.php" class="<?= empty(array_filter($filters))?'cur':'' ?>">All</a>
    <a href="?mine=1">Mine</a>
    <a href="?status=onboarding">Onboarding</a>
    <a href="?status=active">Active</a>
    <a href="?payment_status=past_due">Past due</a>
    <a href="?expiring=90">Renewal &lt;90d</a>
    </div>
    <a href="/crm/client-new.php" style="background:#6d28d9;color:#fff;border-color:#6d28d9;padding:5px 14px">+ New client</a>
  </div>

  <form class="filters" method="get">
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">All</option>
        <?php foreach (CRM_CLIENT_STATUSES as $s): ?>
          <option value="<?= crm_h($s) ?>" <?= $filters['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Payment</label>
      <select name="payment_status">
        <option value="">All</option>
        <?php foreach (CRM_PAYMENT_STATUSES as $s): ?>
          <option value="<?= crm_h($s) ?>" <?= $filters['payment_status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="grow">
      <label>Search</label>
      <input type="text" name="q" value="<?= crm_h($filters['q']) ?>" placeholder="business, email, phone">
    </div>
    <button type="submit">Filter</button>
  </form>

  <table>
    <thead><tr>
      <th>Business</th><th>Trade</th><th>Status</th><th>Pay</th>
      <th>Inst.</th><th>Contract end</th><th>Health</th><th>MRR</th><th>AM</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="empty">No clients yet. Mark a lead as <code>won</code> to create one.</td></tr>
    <?php else: foreach ($rows as $c):
      $mrr = crm_clientMrr($c);
      $hs  = $c['health_score'];
      $hsCls = $hs === null ? '' : ($hs < 50 ? 'bad' : ($hs < 75 ? 'mid' : 'ok'));
      $amName = $c['account_manager_id'] ? ($userMap[(int)$c['account_manager_id']] ?? '?') : '—';
      $daysLeft = (int)((strtotime((string)$c['contract_end_at']) - time()) / 86400);
    ?>
      <tr class="row" onclick="location.href='/crm/client.php?id=<?= (int)$c['id'] ?>'">
        <td>
          <div style="font-weight:600"><?= crm_h($c['business_name'] ?? '—') ?></div>
          <div style="font-size:12px;color:#6b6877"><?= crm_h($c['primary_email'] ?? '') ?></div>
        </td>
        <td><?= crm_h($c['trade'] ?? '') ?></td>
        <td><span class="pill cs-<?= crm_h($c['status']) ?>"><?= crm_h($c['status']) ?></span></td>
        <td><span class="pill ps-<?= crm_h($c['payment_status']) ?>"><?= crm_h($c['payment_status']) ?></span></td>
        <td><?= (int)$c['installment_count'] ?>/12</td>
        <td>
          <?= crm_h(date('M j, Y', strtotime((string)$c['contract_end_at']))) ?>
          <div style="font-size:11px;color:#6b6877"><?= $daysLeft ?>d</div>
        </td>
        <td class="health <?= $hsCls ?>"><?= $hs === null ? '—' : (int)$hs ?></td>
        <td class="mrr"><?= crm_h(crm_fmtMoney($mrr)) ?></td>
        <td><?= crm_h($amName) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
</body></html>
