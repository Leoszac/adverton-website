<?php
// VA cold-calling dialer view. Default shows only DNC-clean, dialable
// prospects. Click "Call" opens a tel: link and increments call_attempts.
// Dispositions update call_status in one click. "Interested → Convert"
// creates a lead in the main pipeline and redirects to it.
//
// Compliance hard guarantee: the default WHERE clause excludes any prospect
// with dnc_status != 'clean'. The "Show blocked" tab is read-only audit.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/phone_normalize.php';
require_once __DIR__ . '/lib/dnc_scrub.php';

$user = crm_requireRole(['founder', 'sales']);
$isAdmin = (($user['role'] ?? '') === 'founder');

$view     = (string)($_GET['view']  ?? 'callable');   // 'callable' | 'blocked'
$batch    = trim((string)($_GET['batch'] ?? ''));
$city     = trim((string)($_GET['city']  ?? ''));
$stateF   = trim((string)($_GET['state'] ?? ''));
$trade    = trim((string)($_GET['trade'] ?? ''));
$attempts = (string)($_GET['attempts'] ?? '');         // '0' | '1' | '2plus' | ''
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

$where  = ['1=1'];
$params = [];

if ($view === 'blocked') {
    $where[] = "dnc_status LIKE 'blocked_%'";
} else {
    $where[] = "dnc_status = 'clean'";
    $where[] = "call_status IN ('not_called','no_answer','voicemail','busy')";
}
if ($batch !== '')  { $where[] = 'imported_batch_id = ?';   $params[] = $batch; }
if ($city !== '')   { $where[] = 'city = ?';                $params[] = $city; }
if ($stateF !== '') { $where[] = 'state = ?';               $params[] = $stateF; }
if ($trade !== '')  { $where[] = 'trade = ?';               $params[] = $trade; }
if ($attempts === '0')       { $where[] = 'call_attempts = 0'; }
elseif ($attempts === '1')   { $where[] = 'call_attempts = 1'; }
elseif ($attempts === '2plus') { $where[] = 'call_attempts >= 2'; }

$whereSql = implode(' AND ', $where);

$pdo = crm_db();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM cold_prospects WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT * FROM cold_prospects
     WHERE {$whereSql}
     ORDER BY call_attempts ASC, last_called_at IS NULL DESC, last_called_at ASC, id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

// Distinct values for filter dropdowns
$cities = $pdo->query("SELECT city, COUNT(*) c FROM cold_prospects WHERE dnc_status='clean' AND city IS NOT NULL AND city<>'' GROUP BY city ORDER BY c DESC LIMIT 80")->fetchAll();
$states = $pdo->query("SELECT state, COUNT(*) c FROM cold_prospects WHERE dnc_status='clean' AND state IS NOT NULL AND state<>'' GROUP BY state ORDER BY c DESC LIMIT 60")->fetchAll();
$trades = $pdo->query("SELECT trade, COUNT(*) c FROM cold_prospects WHERE dnc_status='clean' AND trade IS NOT NULL AND trade<>'' GROUP BY trade ORDER BY c DESC LIMIT 60")->fetchAll();

// Aggregate counts for header
$agg = $pdo->query(
    "SELECT
        SUM(CASE WHEN dnc_status='clean' AND call_status IN ('not_called','no_answer','voicemail','busy') THEN 1 ELSE 0 END) AS dialable,
        SUM(CASE WHEN dnc_status='clean' AND call_status='interested' THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN dnc_status='clean' AND call_status='converted' THEN 1 ELSE 0 END) AS converted,
        SUM(CASE WHEN dnc_status LIKE 'blocked_%' THEN 1 ELSE 0 END) AS blocked
       FROM cold_prospects"
)->fetch();

crm_renderHead('Cold calling');
crm_renderHeader($user, 'cold');
?>
<style>
  main{max-width:1400px}
  h1{margin:0 0 6px;font-size:22px;letter-spacing:-0.01em}
  .stub-banner{background:#fef3c7;border:1px solid #fbbf24;color:#78350f;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
  .tabs{display:flex;gap:6px;margin:0 0 14px}
  .tabs a{padding:7px 14px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;color:#6b6877;background:#fff;border:1px solid #e7e4ee}
  .tabs a.cur{background:#0e0d12;color:#fff;border-color:#0e0d12}
  .tabs a .n{opacity:.6;font-weight:400;margin-left:6px;font-size:11px}
  .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:14px;margin-bottom:14px}
  .toolbar select,.toolbar input{background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:7px 10px;border-radius:8px;font-size:13px}
  .toolbar .grow{flex:1}
  .toolbar a.btn,.toolbar button.btn{background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:7px 12px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer}
  .toolbar a.btn.primary{background:#6d28d9;color:#fff;border-color:#6d28d9}
  .toolbar .rescrub{background:#0e0d12;color:#fff;border-color:#0e0d12;cursor:pointer}
  table.dial{width:100%;background:#fff;border:1px solid #e7e4ee;border-radius:12px;border-collapse:separate;border-spacing:0;overflow:hidden;font-size:13px}
  table.dial th,table.dial td{padding:10px 12px;text-align:left;border-bottom:1px solid #f0eef5;vertical-align:middle}
  table.dial th{background:#faf9fc;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b6877;font-weight:600}
  table.dial tr:last-child td{border-bottom:0}
  table.dial .biz{font-weight:600;color:#0e0d12}
  table.dial .meta{font-size:12px;color:#6b6877;margin-top:2px}
  table.dial .ph{font-family:ui-monospace,monospace;font-weight:600;color:#0e0d12;white-space:nowrap}
  table.dial .att{display:inline-block;background:#f7f6fb;border:1px solid #ece9f3;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600;color:#4a4856}
  table.dial .actions{white-space:nowrap;display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
  table.dial .actions .btn{font-size:11px;padding:5px 9px;border-radius:6px;border:1px solid #e7e4ee;background:#fff;color:#4a4856;cursor:pointer;font-weight:600;text-decoration:none}
  table.dial .actions .btn:hover{border-color:#0e0d12;color:#0e0d12}
  table.dial .actions .btn.call{background:#16a34a;color:#fff;border-color:#16a34a;font-size:13px;padding:7px 14px}
  table.dial .actions .btn.call:hover{background:#15803d}
  table.dial .actions .btn.convert{background:#6d28d9;color:#fff;border-color:#6d28d9}
  table.dial .actions .btn.convert:hover{background:#5b21b6}
  table.dial .actions .btn.dnc{background:#fee2e2;color:#991b1b;border-color:#fecaca}
  table.dial .actions .btn[disabled]{opacity:.4;cursor:not-allowed}
  .pager{margin-top:14px;display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#6b6877}
  .pager a{color:#0e0d12;text-decoration:none;padding:6px 12px;border:1px solid #e7e4ee;border-radius:8px;background:#fff;font-weight:600}
  .pager a[disabled]{opacity:.4;pointer-events:none}
  .blocked-row td{opacity:.65}
  .reason{display:inline-block;background:#fee2e2;color:#991b1b;font-size:10px;padding:1px 7px;border-radius:999px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .empty{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:40px;text-align:center;color:#6b6877}
  .empty h3{margin:0 0 6px;color:#0e0d12}
  /* Modal */
  .modal-bg{position:fixed;inset:0;background:rgba(14,13,18,.6);display:none;align-items:center;justify-content:center;z-index:100}
  .modal-bg.show{display:flex}
  .modal{background:#fff;border-radius:12px;padding:24px;max-width:480px;width:92%;box-shadow:0 20px 60px rgba(0,0,0,.3)}
  .modal h3{margin:0 0 14px;font-size:18px}
  .modal label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  .modal select,.modal textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}
  .modal textarea{min-height:80px;resize:vertical}
  .modal .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
  .modal .actions button{padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid #e7e4ee;background:#fff;color:#0e0d12}
  .modal .actions button.primary{background:#6d28d9;color:#fff;border-color:#6d28d9}
</style>
<main>
  <h1>Cold calling</h1>

  <?php if (!crm_dncIsLive()): ?>
    <div class="stub-banner">
      <strong>⚠️ DNC scrub is in STUB MODE.</strong> Without a real
      <code>DNCSCRUB_API_KEY</code>, every imported number is marked
      <code>clean</code> by default. <a href="/crm/integrations.php" style="color:#78350f;text-decoration:underline">Configure DNC scrub →</a>
    </div>
  <?php endif; ?>

  <div class="tabs">
    <a href="?view=callable" class="<?= $view==='callable'?'cur':'' ?>">Callable <span class="n"><?= (int)$agg['dialable'] ?></span></a>
    <a href="?view=blocked"  class="<?= $view==='blocked'?'cur':'' ?>">Blocked (audit) <span class="n"><?= (int)$agg['blocked'] ?></span></a>
    <a href="/crm/cold-prospects-import.php" class="" style="margin-left:auto;background:#6d28d9;color:#fff;border-color:#6d28d9">+ Import CSV</a>
  </div>

  <form class="toolbar" method="get">
    <input type="hidden" name="view" value="<?= crm_h($view) ?>">
    <select name="state" onchange="this.form.submit()">
      <option value="">All states</option>
      <?php foreach ($states as $s): ?>
        <option value="<?= crm_h($s['state']) ?>" <?= $stateF===$s['state']?'selected':'' ?>><?= crm_h($s['state']) ?> (<?= (int)$s['c'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="city" onchange="this.form.submit()">
      <option value="">All cities</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?= crm_h($c['city']) ?>" <?= $city===$c['city']?'selected':'' ?>><?= crm_h($c['city']) ?> (<?= (int)$c['c'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="trade" onchange="this.form.submit()">
      <option value="">All trades</option>
      <?php foreach ($trades as $t): ?>
        <option value="<?= crm_h($t['trade']) ?>" <?= $trade===$t['trade']?'selected':'' ?>><?= crm_h($t['trade']) ?> (<?= (int)$t['c'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="attempts" onchange="this.form.submit()">
      <option value=""      <?= $attempts===''?'selected':'' ?>>Any attempts</option>
      <option value="0"     <?= $attempts==='0'?'selected':'' ?>>Never called</option>
      <option value="1"     <?= $attempts==='1'?'selected':'' ?>>1 attempt</option>
      <option value="2plus" <?= $attempts==='2plus'?'selected':'' ?>>2+ attempts</option>
    </select>
    <?php if ($batch !== ''): ?>
      <input type="hidden" name="batch" value="<?= crm_h($batch) ?>">
      <span style="font-size:12px;color:#6b6877">Batch <code><?= crm_h($batch) ?></code></span>
      <a class="btn" href="?view=<?= crm_h($view) ?>">Clear batch filter</a>
    <?php endif; ?>
    <span class="grow"></span>
    <span style="font-size:13px;color:#6b6877"><strong style="color:#0e0d12"><?= number_format($total) ?></strong> result<?= $total===1?'':'s' ?></span>
    <?php if ($view==='callable' && $isAdmin): ?>
      <button type="button" class="btn rescrub" onclick="rescrubAll()">↻ Re-scrub all callable</button>
    <?php endif; ?>
  </form>

  <?php if (empty($rows)): ?>
    <div class="empty">
      <h3>No <?= $view==='callable'?'callable prospects':'blocked numbers' ?></h3>
      <p>
        <?php if ($view==='callable'): ?>
          Import a CSV to get started.<br>
          <a href="/crm/cold-prospects-import.php" style="color:#6d28d9;font-weight:600">Import cold prospects →</a>
        <?php else: ?>
          Numbers that fail DNC scrub will show up here for audit.
        <?php endif; ?>
      </p>
    </div>
  <?php else: ?>
    <table class="dial">
      <thead><tr>
        <th>Business</th>
        <th>Phone</th>
        <th>Location</th>
        <th>Trade</th>
        <?php if ($view==='blocked'): ?><th>Block reason</th><?php endif; ?>
        <th style="text-align:right">Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $isBlocked = (strpos((string)$r['dnc_status'], 'blocked_') === 0);
          $blockReason = $isBlocked ? str_replace('blocked_', '', (string)$r['dnc_status']) : '';
        ?>
          <tr<?= $isBlocked?' class="blocked-row"':'' ?> data-id="<?= (int)$r['id'] ?>">
            <td>
              <div class="biz"><?= crm_h((string)($r['business_name'] ?: '—')) ?></div>
              <?php if (!empty($r['contact_name']) || !empty($r['website'])): ?>
                <div class="meta">
                  <?= !empty($r['contact_name']) ? crm_h((string)$r['contact_name']) : '' ?>
                  <?php if (!empty($r['website'])): ?>
                    <?= !empty($r['contact_name']) ? ' · ' : '' ?>
                    <a href="<?= crm_h((string)$r['website']) ?>" target="_blank" rel="noopener" style="color:#6b6877"><?= crm_h(parse_url((string)$r['website'], PHP_URL_HOST) ?: (string)$r['website']) ?></a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="ph"><?= crm_h(crm_phoneFormatPretty((string)$r['phone'])) ?></span>
              <?php if ((int)$r['call_attempts'] > 0): ?>
                <div class="meta"><span class="att"><?= (int)$r['call_attempts'] ?> attempt<?= (int)$r['call_attempts']===1?'':'s' ?></span> · <?= crm_h(crm_fmtRelative((string)$r['last_called_at'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= crm_h(trim(((string)$r['city']) . (((string)$r['state']) ? ', '.$r['state'] : ''), ', ')) ?: '—' ?>
            </td>
            <td><?= crm_h((string)($r['trade'] ?: '—')) ?></td>
            <?php if ($view==='blocked'): ?>
              <td><span class="reason"><?= crm_h($blockReason) ?></span></td>
            <?php endif; ?>
            <td><div class="actions">
              <?php if ($isBlocked): ?>
                <button class="btn" disabled title="Blocked by DNC scrub — cannot call">Call</button>
              <?php else: ?>
                <a class="btn call" href="tel:<?= crm_h((string)$r['phone']) ?>" onclick="markCalled(<?= (int)$r['id'] ?>)">📞 Call</a>
                <button class="btn" onclick="setDisp(<?= (int)$r['id'] ?>,'no_answer')">NA</button>
                <button class="btn" onclick="setDisp(<?= (int)$r['id'] ?>,'voicemail')">VM</button>
                <button class="btn" onclick="setDisp(<?= (int)$r['id'] ?>,'wrong_number')">Wrong#</button>
                <button class="btn" onclick="setDisp(<?= (int)$r['id'] ?>,'not_interested')">Not int.</button>
                <button class="btn convert" onclick="openConvert(<?= (int)$r['id'] ?>)">★ Interested</button>
                <button class="btn dnc" onclick="markDnc(<?= (int)$r['id'] ?>)">DNC req</button>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="pager">
      <span>Page <?= $page ?> of <?= $totalPages ?> · showing <?= count($rows) ?> of <?= number_format($total) ?></span>
      <span>
        <a href="<?= crm_h('?' . http_build_query(array_merge($_GET, ['page'=>max(1,$page-1)]))) ?>" <?= $page<=1?'disabled':'' ?>>← Prev</a>
        <a href="<?= crm_h('?' . http_build_query(array_merge($_GET, ['page'=>min($totalPages,$page+1)]))) ?>" <?= $page>=$totalPages?'disabled':'' ?>>Next →</a>
      </span>
    </div>
  <?php endif; ?>
</main>

<!-- Convert modal -->
<div class="modal-bg" id="convertModal" onclick="if(event.target===this)closeConvert()">
  <div class="modal">
    <h3>Convert to lead</h3>
    <p style="margin:0;color:#6b6877;font-size:13px">Creates a new lead in the main pipeline with <code>source=cold_call</code>.</p>
    <label>Temperature</label>
    <select id="convTemp">
      <option value="hot">🔥 Hot — wants to talk now</option>
      <option value="warm" selected>⭐ Warm — interested, follow up</option>
      <option value="cold">🧊 Cold — keep on file</option>
    </select>
    <label>Call notes (what they said)</label>
    <textarea id="convNotes" placeholder="e.g., Plumber in Phoenix, $40k/mo revenue, frustrated with current marketing. Wants quote by Friday."></textarea>
    <div class="actions">
      <button onclick="closeConvert()">Cancel</button>
      <button class="primary" id="convBtn" onclick="doConvert()">Convert &amp; open lead →</button>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(crm_csrfToken()) ?>;
let convertingId = null;

function api(action, body) {
  body = body || {};
  body.action = action;
  body.csrf = csrf;
  return fetch('/crm/cold-prospect-action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(body),
  }).then(r => r.json());
}

function markCalled(id) {
  api('mark_called', {id: id});
  // tel: link continues to fire — don't block.
}
function setDisp(id, status) {
  api('set_disposition', {id: id, status: status}).then(r => {
    if (r.ok) {
      const row = document.querySelector('tr[data-id="' + id + '"]');
      if (row) row.remove();
    } else alert(r.error || 'Failed');
  });
}
function markDnc(id) {
  if (!confirm('Mark this prospect as DNC-requested? They will be permanently hidden and added to internal DNC list.')) return;
  api('mark_dnc', {id: id}).then(r => {
    if (r.ok) {
      const row = document.querySelector('tr[data-id="' + id + '"]');
      if (row) row.remove();
    } else alert(r.error || 'Failed');
  });
}
function openConvert(id) {
  convertingId = id;
  document.getElementById('convNotes').value = '';
  document.getElementById('convTemp').value = 'warm';
  document.getElementById('convertModal').classList.add('show');
}
function closeConvert() {
  document.getElementById('convertModal').classList.remove('show');
  convertingId = null;
}
function doConvert() {
  if (!convertingId) return;
  const btn = document.getElementById('convBtn');
  btn.disabled = true;
  btn.textContent = 'Converting…';
  api('convert_to_lead', {
    id: convertingId,
    temperature: document.getElementById('convTemp').value,
    notes:       document.getElementById('convNotes').value,
  }).then(r => {
    if (r.ok && r.lead_id) {
      window.location = '/crm/lead.php?id=' + r.lead_id;
    } else {
      btn.disabled = false;
      btn.textContent = 'Convert & open lead →';
      alert(r.error || 'Conversion failed');
    }
  });
}
function rescrubAll() {
  if (!confirm('Re-scrub all clean prospects against DNC? This costs ~$0.005 per number. Pool size will be shown after.')) return;
  api('rescrub_all', {}).then(r => {
    if (r.ok) {
      alert('Re-scrubbed ' + r.scrubbed + ' numbers. ' + (r.newly_blocked || 0) + ' newly blocked. Page will reload.');
      location.reload();
    } else alert(r.error || 'Re-scrub failed');
  });
}
</script>
</body></html>
