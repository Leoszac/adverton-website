<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$scope = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
$forUser = $scope === 'all' ? null : (int)$user['id'];

$overdue  = crm_listDueTasks($forUser, 'overdue');
$today    = crm_listDueTasks($forUser, 'today');
$upcoming = crm_listDueTasks($forUser, 'upcoming');

$users = crm_listUsers();

crm_renderHead('Today');
crm_renderHeader($user, 'today');
?>
<style>
  .scope{display:flex;gap:6px;margin-bottom:14px}
  .scope a{background:#fff;border:1px solid #e7e4ee;border-radius:999px;padding:5px 12px;font-size:12px;color:#0e0d12;text-decoration:none;font-weight:600}
  .scope a.cur{background:#0e0d12;color:#fff;border-color:#0e0d12}
  .section{margin-bottom:24px}
  .section h2{margin:0 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}
  .section h2 .count{color:#0e0d12;font-weight:800}
  .section.overdue h2 .count{color:#dc2626}
  .tasks{background:#fff;border:1px solid #e7e4ee;border-radius:12px;overflow:hidden}
  .tk{display:grid;grid-template-columns:36px 110px 1fr auto;gap:10px;align-items:center;padding:11px 14px;border-bottom:1px solid #f0eef5}
  .tk:last-child{border-bottom:0}
  .tk.done{opacity:.45}
  .tk.done .title{text-decoration:line-through}
  .tk form.tick{margin:0}
  .tk button.tick{width:22px;height:22px;border-radius:50%;border:1.5px solid #c9c4d4;background:#fff;cursor:pointer;display:grid;place-items:center;color:transparent;font-weight:700;font-size:13px;padding:0}
  .tk.done button.tick{background:#16a34a;border-color:#16a34a;color:#fff}
  .tk button.tick:hover{border-color:#6d28d9}
  .tk .when{font-size:12px;color:#6b6877;font-variant-numeric:tabular-nums}
  .tk.overdue .when{color:#dc2626;font-weight:700}
  .tk .title{font-size:14px;font-weight:600;color:#0e0d12}
  .tk .meta{font-size:12px;color:#6b6877;margin-top:2px}
  .tk a{color:#6d28d9;text-decoration:none}
  .empty{padding:18px;text-align:center;color:#6b6877;font-size:13px}
  .new-task{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:14px;margin-bottom:24px}
  .new-task h2{margin:0 0 10px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}
  .new-task .row{display:grid;grid-template-columns:1fr 180px 160px 110px;gap:8px}
  .new-task input,.new-task select{background:#fff;border:1px solid #e7e4ee;padding:8px 10px;border-radius:8px;font-size:13px;width:100%;font-family:inherit}
  .new-task button{background:#6d28d9;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
  .new-task button:hover{background:#5b21b6}
  @media (max-width: 700px){
    .new-task .row{grid-template-columns:1fr}
    .tk{grid-template-columns:28px 1fr;grid-template-rows:auto auto}
    .tk .when{grid-column:2;grid-row:2}
  }
</style>
<main>
  <div class="scope">
    <a href="?scope=mine" class="<?= $scope==='mine'?'cur':'' ?>">Mine</a>
    <a href="?scope=all" class="<?= $scope==='all'?'cur':'' ?>">Everyone</a>
  </div>

  <form class="new-task" method="post" action="/crm/update.php">
    <h2>New task</h2>
    <input type="hidden" name="mode" value="task_create">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
    <div class="row">
      <input type="text" name="title" placeholder="What needs to happen? (e.g. Call Mike Tampa Plumbing)" required>
      <input type="datetime-local" name="due_at" value="<?= date('Y-m-d') ?>T17:00" required>
      <select name="assigned_to">
        <option value="<?= (int)$user['id'] ?>">Assign to me</option>
        <?php foreach ($users as $u): if ((int)$u['id']===(int)$user['id']) continue; ?>
          <option value="<?= (int)$u['id'] ?>">Assign to <?= crm_h($u['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Add task</button>
    </div>
  </form>

  <div class="section overdue">
    <h2>Overdue <span class="count"><?= count($overdue) ?></span></h2>
    <div class="tasks">
      <?php if (!$overdue): ?>
        <div class="empty">Nothing overdue. 👍</div>
      <?php else: foreach ($overdue as $t) crm_renderTask($t, true); endif; ?>
    </div>
  </div>

  <div class="section">
    <h2>Today <span class="count"><?= count($today) ?></span></h2>
    <div class="tasks">
      <?php if (!$today): ?>
        <div class="empty">No tasks due today.</div>
      <?php else: foreach ($today as $t) crm_renderTask($t, false); endif; ?>
    </div>
  </div>

  <div class="section">
    <h2>Upcoming (next 7 days) <span class="count"><?= count($upcoming) ?></span></h2>
    <div class="tasks">
      <?php if (!$upcoming): ?>
        <div class="empty">Nothing scheduled.</div>
      <?php else: foreach ($upcoming as $t) crm_renderTask($t, false); endif; ?>
    </div>
  </div>
</main>
</body></html>
<?php

function crm_renderTask(array $t, bool $isOverdue): void {
    $name = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
    $contact = $t['business_name'] ?: $name;
    $done = $t['done_at'] !== null;
    $cls = ($done ? 'done' : '') . ' ' . ($isOverdue ? 'overdue' : '');
    ?>
    <div class="tk <?= $cls ?>">
      <form class="tick" method="post" action="/crm/update.php">
        <input type="hidden" name="mode" value="<?= $done ? 'task_uncomplete' : 'task_complete' ?>">
        <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <button type="submit" class="tick" title="Mark <?= $done?'incomplete':'done' ?>">✓</button>
      </form>
      <span class="when"><?= crm_h(date('D · g:ia', strtotime((string)$t['due_at']))) ?></span>
      <div>
        <div class="title"><?= crm_h($t['title']) ?></div>
        <div class="meta">
          <?php if ($t['lead_id']): ?>
            <a href="/crm/lead.php?id=<?= (int)$t['lead_id'] ?>"><?= crm_h($contact ?: ('Lead #' . $t['lead_id'])) ?></a>
            <?php if ($t['phone']): ?> · <?= crm_h($t['phone']) ?><?php endif; ?>
          <?php else: ?>
            (no lead)
          <?php endif; ?>
          <?php if ($t['assignee_name']): ?> · <?= crm_h($t['assignee_name']) ?><?php endif; ?>
          <?php if ($t['notes']): ?> · <?= crm_h(mb_substr($t['notes'], 0, 80)) ?><?php endif; ?>
        </div>
      </div>
      <div></div>
    </div>
    <?php
}
