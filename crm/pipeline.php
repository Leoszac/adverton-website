<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$mineOnly = !empty($_GET['mine']);
$filters = $mineOnly ? ['owner' => (int)$user['id']] : [];

// Pull active leads (won/lost are terminal but we still show them in their columns)
$rows = crm_listLeads($filters, 500, 0);

$columns = [];
foreach (CRM_LEAD_STATUSES as $s) $columns[$s] = [];
foreach ($rows as $r) {
    $columns[$r['status']][] = $r;
}

// Totals per column
$totals = [];
foreach ($columns as $status => $items) {
    $sum = 0.0;
    foreach ($items as $r) $sum += crm_leadMrr($r);
    $totals[$status] = ['count' => count($items), 'mrr' => $sum];
}

crm_renderHead('Pipeline');
crm_renderHeader($user, 'pipeline');
?>
<style>
  .scope{display:flex;gap:6px;margin-bottom:14px}
  .scope a{background:#fff;border:1px solid #e7e4ee;border-radius:999px;padding:5px 12px;font-size:12px;color:#0e0d12;text-decoration:none;font-weight:600}
  .scope a.cur{background:#0e0d12;color:#fff;border-color:#0e0d12}
  .board{display:grid;grid-template-columns:repeat(6, minmax(220px, 1fr));gap:10px;align-items:start}
  .col{background:#eeebf5;border-radius:12px;padding:8px;min-height:300px}
  .col h3{margin:4px 6px 8px;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;display:flex;align-items:center;justify-content:space-between}
  .col h3 .n{background:#fff;color:#0e0d12;font-size:11px;padding:2px 8px;border-radius:999px}
  .col h3 .sum{font-size:11px;color:#6b6877;font-weight:600}
  .col.s-new h3 .n   {background:#3730a3;color:#fff}
  .col.s-contacted h3 .n {background:#92400e;color:#fff}
  .col.s-qualified h3 .n {background:#166534;color:#fff}
  .col.s-proposal h3 .n  {background:#6b21a8;color:#fff}
  .col.s-won h3 .n   {background:#16a34a;color:#fff}
  .col.s-lost h3 .n  {background:#991b1b;color:#fff}
  .col.over{outline:2px dashed #6d28d9;outline-offset:-4px}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:8px;padding:10px;margin-bottom:8px;cursor:grab;font-size:13px;text-decoration:none;color:#0e0d12;display:block}
  .card.dragging{opacity:.4}
  .card .name{font-weight:700;margin-bottom:2px}
  .card .biz{color:#6b6877;font-size:12px}
  .card .row{display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:11px;color:#6b6877}
  .card .mrr{color:#0e0d12;font-weight:700;font-variant-numeric:tabular-nums}
  .card .pill{padding:1px 6px;font-size:10px}
  .empty{text-align:center;color:#a8a3b3;font-size:12px;padding:20px 4px}
  @media (max-width: 1200px){.board{grid-template-columns:repeat(3, 1fr)}}
  @media (max-width: 700px){.board{grid-template-columns:1fr}}
</style>
<main>
  <div class="scope">
    <a href="/crm/pipeline.php" class="<?= !$mineOnly?'cur':'' ?>">Everyone</a>
    <a href="/crm/pipeline.php?mine=1" class="<?= $mineOnly?'cur':'' ?>">Mine</a>
  </div>

  <div class="board">
    <?php foreach ($columns as $status => $items): ?>
      <div class="col s-<?= crm_h($status) ?>" data-status="<?= crm_h($status) ?>" ondragover="event.preventDefault();this.classList.add('over')" ondragleave="this.classList.remove('over')" ondrop="onDrop(event,this)">
        <h3>
          <span><?= crm_h(ucfirst($status)) ?> <span class="n"><?= $totals[$status]['count'] ?></span></span>
          <?php if ($totals[$status]['mrr'] > 0): ?>
            <span class="sum"><?= crm_h(crm_fmtMoney($totals[$status]['mrr'])) ?>/mo</span>
          <?php endif; ?>
        </h3>
        <?php if (!$items): ?>
          <div class="empty">—</div>
        <?php else: foreach ($items as $r):
          $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
          $mrr = crm_leadMrr($r);
        ?>
          <a class="card" href="/crm/lead.php?id=<?= (int)$r['id'] ?>" draggable="true" data-id="<?= (int)$r['id'] ?>" ondragstart="onDragStart(event)" ondragend="onDragEnd(event)">
            <div class="name"><?= crm_h($name ?: 'Unnamed') ?></div>
            <div class="biz"><?= crm_h($r['business_name'] ?? '') ?></div>
            <div class="row">
              <span>
                <?= $r['temperature'] ? '<span class="pill t-' . crm_h($r['temperature']) . '">' . crm_h($r['temperature']) . '</span>' : '' ?>
                <?= $r['audit_score'] !== null ? ' · ' . (int)$r['audit_score'] . '/100' : '' ?>
              </span>
              <span class="mrr"><?= $mrr > 0 ? crm_h(crm_fmtMoney($mrr)) : '' ?></span>
            </div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</main>
<script>
const CSRF = <?= json_encode(crm_csrfToken()) ?>;
let dragId = null;

function onDragStart(e){
  dragId = e.currentTarget.dataset.id;
  e.currentTarget.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', dragId);
}
function onDragEnd(e){
  e.currentTarget.classList.remove('dragging');
  document.querySelectorAll('.col.over').forEach(c => c.classList.remove('over'));
}
async function onDrop(e, col){
  e.preventDefault();
  col.classList.remove('over');
  const status = col.dataset.status;
  const id = dragId;
  if (!id || !status) return;

  const fd = new FormData();
  fd.set('mode', 'pipeline_status');
  fd.set('id', id);
  fd.set('status', status);
  fd.set('csrf', CSRF);

  const res = await fetch('/crm/update.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  if (res.ok) location.reload();
  else alert('Update failed.');
}
</script>
</body></html>
