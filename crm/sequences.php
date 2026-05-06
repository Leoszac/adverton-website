<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/sequences.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']);

$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? crm_getSequence($editId) : null;
$saved   = ($_GET['saved'] ?? '') === '1';
$list    = crm_listSequences();
$tpls    = crm_listTemplates();

crm_renderHead('Sequences');
crm_renderHeader($user, '');
?>
<style>
  .grid2{display:grid;grid-template-columns:1fr 1.6fr;gap:18px}
  @media (max-width: 880px){ .grid2{grid-template-columns:1fr} }
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  h2{margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  ul.li{list-style:none;margin:0;padding:0}
  ul.li li{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0eef5;font-size:14px}
  ul.li a{color:#0e0d12;text-decoration:none;font-weight:600}
  ul.li .meta{font-size:12px;color:#6b6877}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=text],select,textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
  textarea{font-family:ui-monospace,monospace;min-height:160px}
  button.primary{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .new-btn{background:#6d28d9;color:#fff;border:0;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .badge{display:inline-block;background:#dcfce7;color:#166534;font-size:11px;font-weight:600;padding:1px 8px;border-radius:999px;margin-left:6px}
  .badge.off{background:#fee2e2;color:#991b1b}
</style>
<main>
  <?php if ($saved): ?><div class="saved">Saved.</div><?php endif; ?>
  <div class="grid2">
    <div>
      <div class="card">
        <h2>Sequences · <?= count($list) ?></h2>
        <ul class="li">
          <?php foreach ($list as $s): ?>
            <li>
              <div>
                <a href="?edit=<?= (int)$s['id'] ?>"><?= crm_h($s['name']) ?></a>
                <span class="badge <?= $s['active']?'':'off' ?>"><?= $s['active']?'active':'off' ?></span>
                <div class="meta"><?= crm_h($s['trigger_event']) ?><?= $s['trigger_value']?(' · ' . crm_h($s['trigger_value'])):'' ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <p style="margin-top:12px"><a class="new-btn" href="?edit=new">+ New sequence</a></p>
      </div>
    </div>
    <div>
      <?php if (!$editId && !$editing): ?>
        <div class="card"><h2>Pick a sequence on the left or create a new one</h2></div>
      <?php else: ?>
        <form class="card" method="post" action="/crm/update.php">
          <h2><?= $editing ? 'Edit sequence' : 'New sequence' ?></h2>
          <input type="hidden" name="mode" value="sequence_save">
          <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <label>Name</label>
          <input type="text" name="name" required value="<?= $editing ? crm_h($editing['name']) : '' ?>">
          <div class="row2">
            <div>
              <label>Trigger event</label>
              <select name="trigger_event">
                <?php foreach (CRM_SEQ_TRIGGERS as $t): ?>
                  <option value="<?= crm_h($t) ?>" <?= ($editing['trigger_event'] ?? '')===$t?'selected':'' ?>><?= crm_h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Active?</label>
              <select name="active">
                <option value="1" <?= ($editing['active'] ?? 1) ? 'selected':'' ?>>Yes</option>
                <option value="0" <?= !($editing['active'] ?? 1) ? 'selected':'' ?>>No</option>
              </select>
            </div>
          </div>

          <label>Steps (JSON array — example below)</label>
          <textarea name="steps_json" placeholder='[{"delay_days":0,"action":"send_template","payload":{"template_id":1}},{"delay_days":3,"action":"send_template","payload":{"template_id":2}}]'><?php
            if ($editing && !empty($editing['steps'])) {
                $exp = [];
                foreach ($editing['steps'] as $st) {
                    $exp[] = ['delay_days' => (int)$st['delay_days'], 'action' => $st['action'], 'payload' => json_decode((string)$st['payload'], true) ?: []];
                }
                echo crm_h(json_encode($exp, JSON_PRETTY_PRINT));
            }
          ?></textarea>
          <p style="font-size:12px;color:#6b6877;margin:8px 0 0">
            Actions: <code>send_template</code> (payload: <code>{template_id}</code>),
            <code>create_task</code> (payload: <code>{title}</code>),
            <code>add_tag</code>/<code>remove_tag</code> (payload: <code>{tag}</code>).<br>
            Available templates: <?php foreach ($tpls as $t): ?><code><?= (int)$t['id'] ?>=<?= crm_h($t['name']) ?></code> <?php endforeach; ?>
          </p>

          <button type="submit" class="primary">Save</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</main>
</body></html>
