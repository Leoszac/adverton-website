<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$editingId = (int)($_GET['edit'] ?? 0);
$editing = $editingId > 0 ? crm_getTemplate($editingId) : null;
$saved = ($_GET['saved'] ?? '') === '1';

$templates = crm_listTemplates();

crm_renderHead('Email templates');
crm_renderHeader($user, '');
?>
<style>
  .grid2{display:grid;grid-template-columns:1fr 1.4fr;gap:18px}
  @media (max-width: 880px){ .grid2{grid-template-columns:1fr} }
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  h2{margin:0 0 14px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}

  ul.tpl{list-style:none;margin:0;padding:0}
  ul.tpl li{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f0eef5;font-size:14px}
  ul.tpl li:last-child{border-bottom:0}
  ul.tpl a{color:#0e0d12;text-decoration:none;font-weight:600}
  ul.tpl .meta{color:#6b6877;font-size:12px;margin-top:2px}
  .new-btn{background:#6d28d9;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}

  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=text],textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit}
  textarea{min-height:240px;resize:vertical;line-height:1.5;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
  button.primary{background:#6d28d9;color:#fff;border:0;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-right:8px}
  button.danger{background:#fee2e2;color:#991b1b;border:0;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .vars{font-size:12px;color:#6b6877;margin-top:6px}
  .vars code{background:#faf9ff;border:1px solid #e7e4ee;padding:1px 6px;border-radius:4px;color:#6d28d9;font-size:11px}
</style>
<main>
  <?php if ($saved): ?><div class="saved">Saved.</div><?php endif; ?>

  <div class="grid2">
    <div>
      <div class="card">
        <h2>Templates · <?= count($templates) ?></h2>
        <ul class="tpl">
          <?php if (!$templates): ?>
            <li><span style="color:#6b6877">No templates yet — create one →</span></li>
          <?php else: foreach ($templates as $t): ?>
            <li>
              <div>
                <a href="?edit=<?= (int)$t['id'] ?>"><?= crm_h($t['name']) ?></a>
                <div class="meta"><?= crm_h(mb_substr($t['subject'], 0, 80)) ?></div>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
        <p style="margin-top:12px"><a class="new-btn" href="?edit=new">+ New template</a></p>
      </div>
    </div>

    <div>
      <?php if ($editingId === 0 && !$editing): ?>
        <div class="card"><h2>Pick a template on the left, or click "+ New template"</h2></div>
      <?php else: ?>
        <form class="card" method="post" action="/crm/update.php">
          <h2><?= $editing ? 'Edit template' : 'New template' ?></h2>
          <input type="hidden" name="mode" value="template_save">
          <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

          <label>Name (internal — only you see this)</label>
          <input type="text" name="name" value="<?= $editing ? crm_h($editing['name']) : '' ?>" placeholder="e.g. Hot audit follow-up" required>

          <label>Subject</label>
          <input type="text" name="subject" value="<?= $editing ? crm_h($editing['subject']) : '' ?>" placeholder="Hi {first_name} — your GBP audit results" required>

          <label>Body</label>
          <textarea name="body" placeholder="Hi {first_name},&#10;&#10;Saw your audit for {business_name} came back at {audit_score}/100..."><?= $editing ? crm_h($editing['body']) : '' ?></textarea>

          <div class="vars">
            Variables: <code>{first_name}</code> <code>{last_name}</code> <code>{business_name}</code> <code>{trade}</code> <code>{city_state}</code> <code>{audit_score}</code> <code>{website}</code>
          </div>

          <div style="margin-top:18px">
            <button type="submit" class="primary">Save</button>
            <?php if ($editing): ?>
              <button type="submit" class="danger" name="delete" value="1" onclick="return confirm('Delete this template?')">Delete</button>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</main>
</body></html>
