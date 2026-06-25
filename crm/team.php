<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';

// Founder only — this page creates users and assigns roles.
$user = crm_requireRole(['founder']);
$db = crm_db();

$msg = (string)($_GET['msg'] ?? '');
$err = (string)($_GET['err'] ?? '');

$roleLabels = [
    'founder'         => 'Founder (full access)',
    'sales'           => 'Sales (full access, no settings)',
    CRM_ROLE_LEADS    => 'Leads only',
];

$users = $db->query('SELECT id, username, display_name, role FROM users ORDER BY id')->fetchAll();

crm_renderHead('Team');
crm_renderHeader($user, 'team');
?>
<style>
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;margin-bottom:14px;max-width:760px}
  h1{margin:0 0 6px;font-size:22px}
  h2{margin:0 0 6px;font-size:18px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .err{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=password],input[type=text],select{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:10px 12px;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}
  button{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:11px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button.small{margin-top:0;padding:8px 12px;font-size:13px}
  button.ghost{background:#f3f1fb;color:#6d28d9}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #efedf5;vertical-align:middle}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b6877}
  .rolepill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
  .rolepill.founder{background:#ede9fe;color:#5b21b6}
  .rolepill.sales{background:#dbeafe;color:#1e40af}
  .rolepill.leads{background:#dcfce7;color:#166534}
  .row-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
  .row-actions form{display:flex;gap:6px;align-items:flex-end}
  .mini label{margin:0 0 3px}
  .mini input,.mini select{padding:7px 9px;font-size:13px;width:auto}
  @media (max-width:880px){ .row-actions{flex-direction:column;align-items:stretch} }
</style>
<main>
  <h1>Team</h1>
  <div class="sub">Create CRM logins and set what each person can access. A <strong>Leads only</strong> user sees just the Leads list + pipeline — no clients, cold email, reports, or settings.</div>

  <?php if ($msg): ?><div class="saved"><?= crm_h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= crm_h($err) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add a user</h2>
    <div class="sub">They log in at <code>/crm/</code> with the username and password you set here. They can change their own password later under Account.</div>
    <form method="post" action="/crm/update.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
      <input type="hidden" name="mode" value="user_create">

      <label>Username (lowercase, 3-60 chars; letters/numbers/. _ -)</label>
      <input type="text" name="username" pattern="[a-z0-9._-]{3,60}" required placeholder="ralph">

      <label>Display name</label>
      <input type="text" name="display_name" required maxlength="120" placeholder="Ralph">

      <label>Temporary password (10+ chars)</label>
      <input type="text" name="password" minlength="10" required placeholder="give them a strong one">

      <label>Role / access level</label>
      <select name="role">
        <option value="<?= crm_h(CRM_ROLE_LEADS) ?>" selected>Leads only — Leads list + pipeline, nothing else</option>
        <option value="sales">Sales — full access except settings</option>
        <option value="founder">Founder — full access including settings</option>
      </select>

      <button type="submit">Create user</button>
    </form>
  </div>

  <div class="card">
    <h2>Existing users</h2>
    <table>
      <thead><tr><th>User</th><th>Role</th><th>Change role</th><th>Reset password</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u):
        $r = (string)$u['role'];
        $pillClass = $r === 'founder' ? 'founder' : ($r === 'sales' ? 'sales' : 'leads');
        $isSelf = ((int)$u['id'] === (int)$user['id']);
      ?>
        <tr>
          <td>
            <strong><?= crm_h($u['display_name']) ?></strong><br>
            <span style="color:#6b6877;font-size:12px">@<?= crm_h($u['username']) ?><?= $isSelf ? ' (you)' : '' ?></span>
          </td>
          <td><span class="rolepill <?= $pillClass ?>"><?= crm_h($roleLabels[$r] ?? $r) ?></span></td>
          <td>
            <?php if ($isSelf): ?>
              <span style="color:#9b97a8;font-size:12px">—</span>
            <?php else: ?>
              <form method="post" action="/crm/update.php" class="mini">
                <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
                <input type="hidden" name="mode" value="user_role_set">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <select name="role">
                  <option value="<?= crm_h(CRM_ROLE_LEADS) ?>" <?= $r===CRM_ROLE_LEADS?'selected':'' ?>>Leads only</option>
                  <option value="sales" <?= $r==='sales'?'selected':'' ?>>Sales</option>
                  <option value="founder" <?= $r==='founder'?'selected':'' ?>>Founder</option>
                </select>
                <button type="submit" class="small ghost">Save</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="/crm/update.php" class="mini" autocomplete="off"
                  onsubmit="return confirm('Reset password for <?= crm_h($u['username']) ?>?')">
              <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
              <input type="hidden" name="mode" value="user_password_reset">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="text" name="password" minlength="10" required placeholder="new password">
              <button type="submit" class="small">Reset</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body></html>
