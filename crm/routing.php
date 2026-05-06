<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/routing.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder']);

$rules = crm_listRoutingRules();
$users = crm_listUsers();
$saved = ($_GET['saved'] ?? '') === '1';

crm_renderHead('Routing rules');
crm_renderHeader($user, '');
?>
<style>
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  h2{margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{padding:8px 6px;text-align:left;border-bottom:1px solid #f0eef5}
  input,select{width:100%;background:#fff;border:1px solid #e7e4ee;padding:6px 8px;border-radius:6px;font-size:13px;box-sizing:border-box}
  button.primary{background:#6d28d9;color:#fff;border:0;padding:7px 14px;border-radius:6px;font-size:13px;cursor:pointer}
  button.danger{background:#fee2e2;color:#991b1b;border:0;padding:6px 10px;border-radius:6px;font-size:12px;cursor:pointer}
  .badge{display:inline-block;font-size:11px;font-weight:600;padding:1px 8px;border-radius:999px}
  .badge.on{background:#dcfce7;color:#166534}
  .badge.off{background:#fee2e2;color:#991b1b}
</style>
<main>
  <?php if ($saved): ?><div class="saved">Saved.</div><?php endif; ?>

  <div class="card">
    <h2>Routing rules — first match wins (ordered by priority asc)</h2>
    <p style="font-size:13px;color:#6b6877;margin:0 0 12px">When a new lead arrives via audit/contact form/inbound call, the first active rule that matches will set <code>owner_user_id</code> automatically.</p>
    <table>
      <thead><tr>
        <th>Pri</th><th>Trade</th><th>Source</th><th>State</th><th>Temp</th>
        <th>Assign to</th><th>Active</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rules as $r): ?>
        <tr>
          <form method="post" action="/crm/update.php">
            <input type="hidden" name="mode" value="routing_save">
            <input type="hidden" name="id"   value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <td><input type="number" name="priority" value="<?= (int)$r['priority'] ?>" style="width:60px"></td>
            <td><input type="text"   name="match_trade"  value="<?= crm_h($r['match_trade']  ?? '') ?>" placeholder="HVAC"></td>
            <td><input type="text"   name="match_source" value="<?= crm_h($r['match_source'] ?? '') ?>" placeholder="audit_auto"></td>
            <td><input type="text"   name="match_state"  value="<?= crm_h($r['match_state']  ?? '') ?>" placeholder="AZ" maxlength="2"></td>
            <td><input type="text"   name="match_temp"   value="<?= crm_h($r['match_temp']   ?? '') ?>" placeholder="hot"></td>
            <td>
              <select name="assign_to">
                <?php foreach ($users as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" <?= (int)$r['assign_to']===(int)$u['id']?'selected':'' ?>><?= crm_h($u['display_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><select name="active"><option value="1" <?= $r['active']?'selected':'' ?>>On</option><option value="0" <?= !$r['active']?'selected':'' ?>>Off</option></select></td>
            <td style="white-space:nowrap"><button type="submit" class="primary">Save</button></td>
          </form>
        </tr>
        <tr><td colspan="8" style="border:0;padding:0">
          <form method="post" action="/crm/update.php" onsubmit="return confirm('Delete rule?')" style="margin:0">
            <input type="hidden" name="mode" value="routing_delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <button type="submit" class="danger" style="margin:4px 0 12px">Delete rule #<?= (int)$r['id'] ?></button>
          </form>
        </td></tr>
      <?php endforeach; ?>
      <tr>
        <form method="post" action="/crm/update.php">
          <input type="hidden" name="mode" value="routing_save">
          <input type="hidden" name="id"   value="0">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <td><input type="number" name="priority" value="100" style="width:60px"></td>
          <td><input type="text" name="match_trade"  placeholder="(any)"></td>
          <td><input type="text" name="match_source" placeholder="(any)"></td>
          <td><input type="text" name="match_state"  placeholder="AZ" maxlength="2"></td>
          <td><input type="text" name="match_temp"   placeholder="(any)"></td>
          <td>
            <select name="assign_to">
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= crm_h($u['display_name']) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td><select name="active"><option value="1">On</option></select></td>
          <td><button type="submit" class="primary">+ Add</button></td>
        </form>
      </tr>
      </tbody>
    </table>
  </div>
</main>
</body></html>
