<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/ui.php';

crm_sessionStart();

// --- Login flow ---
$loginError = '';
$totpError  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!crm_loginRateOk($ip)) {
        $loginError = 'Too many attempts. Wait 10 minutes.';
    } else {
        if (crm_attemptLogin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            header('Location: /crm/');
            exit;
        }
        $loginError = 'Invalid username or password.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp'])) {
    if (crm_verifyTotpStep((string)($_POST['code'] ?? ''))) {
        header('Location: /crm/'); exit;
    }
    $totpError = 'Invalid code. Try again (codes refresh every 30s).';
}

if (crm_isTotpPending()) { crm_render_totp($totpError); exit; }

$user = crm_currentUser();
if (!$user) { crm_render_login($loginError); exit; }

// --- CSV export ---
if (($_GET['export'] ?? '') === 'csv') {
    $filters = crm_filtersFromQuery($_GET);
    if ($filters['tag_name']) {
        $filters['tag'] = crm_findTagId($filters['tag_name']) ?? -1;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="adverton-leads-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    crm_exportCsv($filters);
    exit;
}

$filters = crm_filtersFromQuery($_GET);
if (!empty($_GET['mine'])) $filters['owner'] = (int)$user['id'];
if ($filters['tag_name']) {
    $tagId = crm_findTagId($filters['tag_name']);
    $filters['tag'] = $tagId ?? -1;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$total   = crm_countLeads($filters);
$rows    = crm_listLeads($filters, $perPage, $offset);
crm_attachTagsToLeads($rows);

$users   = crm_listUsers();
$userMap = []; foreach ($users as $u) $userMap[(int)$u['id']] = $u['display_name'];
$allTags = crm_listAllTags();

// Mark this user as "saw up to the latest lead id" so the badge resets
crm_markLeadsSeen((int)$user['id']);

crm_render_list($user, $users, $rows, $filters, $page, $perPage, $total, $userMap, $allTags);

// ====================================================================

function crm_filtersFromQuery(array $q): array {
    return [
        'source'      => $q['source']      ?? '',
        'status'      => $q['status']      ?? '',
        'temperature' => $q['temperature'] ?? '',
        'owner'       => $q['owner']       ?? '',
        'stale_days'  => $q['stale_days']  ?? '',
        'tag_name'    => $q['tag']         ?? '',
        'q'           => $q['q']           ?? '',
    ];
}

function crm_render_totp(string $error): void {
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>2FA — Adverton CRM</title>
<style>
  body{margin:0;font-family:-apple-system,Segoe UI,sans-serif;background:#0e0d12;color:#fff;display:grid;place-items:center;min-height:100vh}
  .card{background:#1a1820;padding:32px;border-radius:14px;width:320px;box-shadow:0 8px 24px rgba(0,0,0,.4);text-align:center}
  h1{margin:0 0 4px;font-size:20px}
  .sub{color:#8b8696;font-size:13px;margin-bottom:24px}
  input{width:100%;background:#0e0d12;border:1px solid #2d2a36;color:#fff;padding:12px;border-radius:8px;font-size:22px;letter-spacing:.3em;text-align:center;font-family:ui-monospace,monospace;box-sizing:border-box}
  input:focus{outline:none;border-color:#6d28d9}
  button{margin-top:14px;width:100%;background:#6d28d9;color:#fff;border:0;padding:11px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .err{background:#2a1518;color:#ff8b8b;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:8px}
  a.cancel{display:block;margin-top:14px;color:#8b8696;font-size:12px;text-decoration:none}
</style>
</head><body>
  <form class="card" method="post">
    <h1>Verification</h1>
    <div class="sub">Enter the 6-digit code from your authenticator app.</div>
    <?php if ($error): ?><div class="err"><?= crm_h($error) ?></div><?php endif; ?>
    <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus required>
    <button type="submit" name="totp" value="1">Verify</button>
    <a class="cancel" href="/crm/logout.php">Cancel</a>
  </form>
</body></html><?php
}

function crm_render_login(string $error): void {
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Adverton CRM — Sign in</title>
<style>
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0e0d12;color:#fff;display:grid;place-items:center;min-height:100vh}
  .card{background:#1a1820;padding:32px;border-radius:14px;width:320px;box-shadow:0 8px 24px rgba(0,0,0,.4)}
  h1{margin:0 0 4px;font-size:22px;letter-spacing:-.01em}
  .sub{color:#8b8696;font-size:13px;margin-bottom:24px}
  label{display:block;font-size:12px;color:#bcb6ca;text-transform:uppercase;letter-spacing:.08em;margin:14px 0 6px;font-weight:600}
  input{width:100%;background:#0e0d12;border:1px solid #2d2a36;color:#fff;padding:10px 12px;border-radius:8px;font-size:14px;box-sizing:border-box}
  input:focus{outline:none;border-color:#6d28d9}
  button{margin-top:20px;width:100%;background:#6d28d9;color:#fff;border:0;padding:11px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button:hover{background:#5b21b6}
  .err{background:#2a1518;color:#ff8b8b;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:8px}
</style>
</head><body>
  <form class="card" method="post">
    <h1>Adverton CRM</h1>
    <div class="sub">Sign in to continue</div>
    <?php if ($error): ?><div class="err"><?= crm_h($error) ?></div><?php endif; ?>
    <label>Username</label>
    <input type="text" name="username" autocomplete="username" autofocus required>
    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit" name="login" value="1">Sign in</button>
  </form>
</body></html><?php
}

function crm_render_list(array $user, array $users, array $rows, array $filters, int $page, int $perPage, int $total, array $userMap, array $allTags): void {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $qs = function(array $overrides) use ($filters, $page) {
        $params = array_merge($filters, ['page'=>$page], $overrides);
        unset($params['tag']); // internal id, don't expose
        $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
        return '?' . http_build_query($params);
    };

    crm_renderHead('Leads');
    crm_renderHeader($user, 'leads');
    ?>
<style>
  .filters{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px}
  .filters label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:4px}
  .filters select,.filters input[type=text]{background:#fff;border:1px solid #e7e4ee;padding:7px 10px;border-radius:8px;font-size:13px;min-width:120px}
  .filters .grow{flex:1;min-width:200px}
  .filters button{background:#6d28d9;color:#fff;border:0;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
  .filters .csv,.filters .clear{background:#fff;border:1px solid #e7e4ee;color:#0e0d12;text-decoration:none;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center}
  .quick{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap}
  .quick a{background:#fff;border:1px solid #e7e4ee;border-radius:999px;padding:5px 12px;font-size:12px;color:#0e0d12;text-decoration:none;font-weight:600}
  .quick a.cur{background:#0e0d12;color:#fff;border-color:#0e0d12}
  .meta{font-size:12px;color:#6b6877;margin:6px 4px 10px}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e7e4ee;border-radius:12px;overflow:hidden}
  th{background:#faf9ff;text-align:left;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;padding:10px 12px;border-bottom:1px solid #e7e4ee}
  td{padding:9px 12px;border-bottom:1px solid #f0eef5;font-size:13.5px;vertical-align:top}
  tr:last-child td{border-bottom:0}
  tr.row{cursor:pointer}
  tr.row:hover{background:#faf9ff}
  td.chk{width:30px;cursor:default}
  td.chk:hover{background:#fff}
  .src{font-size:11px;color:#6b6877}
  .empty{padding:30px;text-align:center;color:#6b6877}
  .pager{margin-top:14px;display:flex;justify-content:center;gap:6px}
  .pager a,.pager span{padding:6px 10px;border-radius:6px;font-size:13px;text-decoration:none;color:#0e0d12;background:#fff;border:1px solid #e7e4ee}
  .pager .cur{background:#6d28d9;color:#fff;border-color:#6d28d9}
  .score{font-weight:700}
  .score.lo{color:#dc2626}.score.md{color:#f59e0b}.score.hi{color:#16a34a}
  .mrr{font-weight:600;color:#0e0d12;font-variant-numeric:tabular-nums}
  .tg{display:inline-flex;gap:4px;flex-wrap:wrap}
  .tg .t{font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;letter-spacing:.02em}

  .bulk-bar{position:sticky;top:54px;z-index:5;background:#0e0d12;color:#fff;padding:10px 14px;border-radius:10px;display:none;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
  .bulk-bar.show{display:flex}
  .bulk-bar .count{background:#6d28d9;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:700}
  .bulk-bar select{background:#1a1820;border:1px solid #2d2a36;color:#fff;padding:6px 10px;border-radius:6px;font-size:13px}
  .bulk-bar button{background:#6d28d9;color:#fff;border:0;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}
  .bulk-bar button.cancel{background:transparent;color:#bcb6ca}
  .bulk-bar input[type=text]{background:#1a1820;border:1px solid #2d2a36;color:#fff;padding:6px 10px;border-radius:6px;font-size:13px}
</style>
<main>
  <div class="quick" style="justify-content:space-between">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
    <a href="/crm/" class="<?= empty(array_filter($filters)) ? 'cur':'' ?>">All</a>
    <a href="<?= crm_h($qs(['mine'=>1,'page'=>null])) ?>">My leads</a>
    <a href="<?= crm_h($qs(['status'=>'new','page'=>null])) ?>">New</a>
    <a href="<?= crm_h($qs(['status'=>'contacted','page'=>null])) ?>">Contacted</a>
    <a href="<?= crm_h($qs(['status'=>'qualified','page'=>null])) ?>">Qualified</a>
    <a href="<?= crm_h($qs(['status'=>'proposal','page'=>null])) ?>">Proposal</a>
    <a href="<?= crm_h($qs(['temperature'=>'hot','page'=>null])) ?>">🔥 Hot</a>
    <a href="<?= crm_h($qs(['stale_days'=>'7','page'=>null])) ?>">Stale 7d+</a>
    </div>
    <a href="/crm/lead-new.php" style="background:#6d28d9;color:#fff;border-color:#6d28d9;padding:5px 14px">+ New lead</a>
  </div>

  <form class="filters" method="get">
    <div>
      <label>Source</label>
      <select name="source">
        <option value="">All</option>
        <option value="audit_auto"   <?= $filters['source']==='audit_auto'?'selected':'' ?>>Audit (auto)</option>
        <option value="audit_manual" <?= $filters['source']==='audit_manual'?'selected':'' ?>>Audit (manual)</option>
        <option value="contact_form" <?= $filters['source']==='contact_form'?'selected':'' ?>>Contact form</option>
      </select>
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">All</option>
        <?php foreach (CRM_LEAD_STATUSES as $s): ?>
          <option value="<?= crm_h($s) ?>" <?= $filters['status']===$s?'selected':'' ?>><?= crm_h(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Temp</label>
      <select name="temperature">
        <option value="">All</option>
        <option value="hot"  <?= $filters['temperature']==='hot'?'selected':''  ?>>Hot</option>
        <option value="warm" <?= $filters['temperature']==='warm'?'selected':'' ?>>Warm</option>
        <option value="cold" <?= $filters['temperature']==='cold'?'selected':'' ?>>Cold</option>
      </select>
    </div>
    <div>
      <label>Owner</label>
      <select name="owner">
        <option value="">All</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)$filters['owner']===(int)$u['id']?'selected':'' ?>><?= crm_h($u['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Tag</label>
      <select name="tag">
        <option value="">All</option>
        <?php foreach ($allTags as $tg): ?>
          <option value="<?= crm_h($tg['name']) ?>" <?= $filters['tag_name']===$tg['name']?'selected':'' ?>><?= crm_h($tg['name']) ?> (<?= (int)$tg['lead_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="grow">
      <label>Search</label>
      <input type="text" name="q" value="<?= crm_h($filters['q']) ?>" placeholder="email, name, business, phone">
    </div>
    <button type="submit">Filter</button>
    <a class="csv" href="<?= crm_h($qs(['export'=>'csv'])) ?>">Export CSV</a>
  </form>

  <div class="meta"><?= (int)$total ?> lead<?= $total===1?'':'s' ?> · page <?= (int)$page ?> of <?= $totalPages ?></div>

  <form id="bulk-form" method="post" action="/crm/update.php">
    <input type="hidden" name="mode" value="bulk">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <div id="bulk-bar" class="bulk-bar">
      <span class="count" id="bulk-count">0 selected</span>

      <select name="bulk_action" id="bulk-action" required>
        <option value="">Action…</option>
        <option value="status">Change status to…</option>
        <option value="owner">Reassign to…</option>
        <option value="tag_add">Add tag…</option>
      </select>

      <select name="bulk_value_status" style="display:none">
        <?php foreach (CRM_LEAD_STATUSES as $s): ?><option value="<?= crm_h($s) ?>"><?= crm_h(ucfirst($s)) ?></option><?php endforeach; ?>
      </select>

      <select name="bulk_value_owner" style="display:none">
        <option value="">Unassigned</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= crm_h($u['display_name']) ?></option><?php endforeach; ?>
      </select>

      <input type="text" name="bulk_value_tag" list="all-tags-bulk" placeholder="tag" style="display:none">
      <datalist id="all-tags-bulk">
        <?php foreach ($allTags as $tg): ?><option value="<?= crm_h($tg['name']) ?>"><?php endforeach; ?>
      </datalist>

      <button type="submit">Apply</button>
      <button type="button" class="cancel" onclick="clearBulk()">Cancel</button>
    </div>

    <table>
      <thead><tr>
        <th><input type="checkbox" id="chk-all" onclick="toggleAll(this)"></th>
        <th>Date</th><th>Name / Business</th><th>Email</th><th>Phone</th>
        <th>Trade</th><th>Source</th><th>Tags</th><th>Score</th><th>Temp</th>
        <th>MRR</th><th>Owner</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="13" class="empty">No leads match these filters.</td></tr>
      <?php else: foreach ($rows as $r):
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $score = $r['audit_score'];
        $scoreCls = $score === null ? '' : ($score < 50 ? 'lo' : ($score < 75 ? 'md' : 'hi'));
        $owner = $r['owner_user_id'] !== null ? ($userMap[(int)$r['owner_user_id']] ?? '?') : '—';
        $mrr = crm_leadMrr($r);
      ?>
        <tr class="row">
          <td class="chk" onclick="event.stopPropagation()"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" onclick="onCheck()"></td>
          <td onclick="location.href='/crm/lead.php?id=<?= (int)$r['id'] ?>'"><?= crm_h(substr((string)$r['created_at'], 0, 10)) ?></td>
          <td onclick="location.href='/crm/lead.php?id=<?= (int)$r['id'] ?>'">
            <div style="font-weight:600"><?= crm_h($name ?: '—') ?></div>
            <div style="font-size:12px;color:#6b6877"><?= crm_h($r['business_name'] ?? '') ?></div>
          </td>
          <td><?= crm_h($r['email'] ?? '') ?></td>
          <td><?= crm_h($r['phone'] ?? '') ?></td>
          <td><?= crm_h($r['trade'] ?? '') ?></td>
          <td><span class="src"><?= crm_h($r['source']) ?></span></td>
          <td>
            <span class="tg">
              <?php foreach (($r['tags'] ?? []) as $tg): ?>
                <span class="t" style="background:<?= crm_h($tg['color']) ?>20;color:<?= crm_h($tg['color']) ?>"><?= crm_h($tg['name']) ?></span>
              <?php endforeach; ?>
            </span>
          </td>
          <td class="score <?= $scoreCls ?>"><?= $score === null ? '—' : (int)$score ?></td>
          <td><?= $r['temperature'] ? '<span class="pill t-' . crm_h($r['temperature']) . '">' . crm_h($r['temperature']) . '</span>' : '—' ?></td>
          <td class="mrr"><?= crm_h(crm_fmtMoney($mrr ?: null)) ?></td>
          <td><?= crm_h($owner) ?></td>
          <td><span class="pill s-<?= crm_h($r['status']) ?>"><?= crm_h($r['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </form>

  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a href="<?= crm_h($qs(['page'=>$page-1])) ?>">‹ Prev</a><?php endif; ?>
      <span class="cur">Page <?= $page ?> / <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?><a href="<?= crm_h($qs(['page'=>$page+1])) ?>">Next ›</a><?php endif; ?>
    </div>
  <?php endif; ?>
</main>
<script>
function onCheck(){
  const checks = document.querySelectorAll('input[name="ids[]"]');
  const sel = Array.from(checks).filter(c => c.checked).length;
  document.getElementById('bulk-count').textContent = sel + ' selected';
  document.getElementById('bulk-bar').classList.toggle('show', sel > 0);
}
function toggleAll(master){
  document.querySelectorAll('input[name="ids[]"]').forEach(c => c.checked = master.checked);
  onCheck();
}
function clearBulk(){
  document.querySelectorAll('input[name="ids[]"]').forEach(c => c.checked = false);
  document.getElementById('chk-all').checked = false;
  onCheck();
}
document.getElementById('bulk-action').addEventListener('change', e => {
  document.querySelectorAll('[name^="bulk_value_"]').forEach(el => el.style.display = 'none');
  const v = e.target.value;
  const map = {status:'bulk_value_status', owner:'bulk_value_owner', tag_add:'bulk_value_tag'};
  if (map[v]) document.querySelector('[name="' + map[v] + '"]').style.display = '';
});
</script>
</body></html><?php
}
