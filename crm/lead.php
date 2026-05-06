<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/email_track.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$id = (int)($_GET['id'] ?? 0);
$lead = $id > 0 ? crm_getLead($id) : null;
if (!$lead) { http_response_code(404); header('Location: /crm/'); exit; }

$users      = crm_listUsers();
$activities = crm_listActivities((int)$lead['id']);
$leadTasks  = crm_listTasksForLead((int)$lead['id']);
$leadTags   = crm_listTagsForLead((int)$lead['id']);
$allTags    = crm_listAllTags();
$templates  = crm_listTemplates();
$leadFiles  = crm_listFiles((int)$lead['id']);
$emailSends = crm_listSendsForLead((int)$lead['id']);
$saved      = ($_GET['saved'] ?? '') === '1';
$sendError  = (string)($_GET['sendError']  ?? '');
$fileError  = (string)($_GET['fileerror']  ?? '');
$mrr        = crm_leadMrr($lead);

function crm_field(?string $v): string {
    if ($v === null || $v === '') return '<span style="color:#bcb6ca">—</span>';
    return crm_h($v);
}

$emailHref = $lead['email'] ? 'mailto:' . rawurlencode($lead['email']) : null;
$telHref   = $lead['phone'] ? 'tel:' . preg_replace('/[^0-9+]/', '', $lead['phone']) : null;

crm_renderHead('Lead #' . (int)$lead['id']);
crm_renderHeader($user, '');
?>
<style>
  .back{font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
  .grid2{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}
  @media (max-width: 980px){ .grid2{grid-template-columns:1fr} }

  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:20px;margin-bottom:14px}
  .card h2{margin:0 0 14px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}
  h1{margin:0 0 4px;font-size:22px;letter-spacing:-.01em}
  .sub{color:#6b6877;font-size:13px;margin-bottom:16px}

  .quick-actions{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
  .qa{background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
  .qa:hover{border-color:#6d28d9;color:#6d28d9}
  .qa.primary{background:#0e0d12;color:#fff;border-color:#0e0d12}
  .qa.primary:hover{background:#1a1820;color:#fff}

  .grid{display:grid;grid-template-columns:repeat(2, minmax(0,1fr));gap:12px 22px}
  .grid.full{grid-template-columns:1fr}
  .k{font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:3px}
  .v{font-size:14px;color:#0e0d12;word-break:break-word}
  .v a{color:#6d28d9}

  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  select,textarea,input[type=text],input[type=number],input[type=date],input[type=datetime-local]{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit}
  textarea{min-height:90px;resize:vertical;line-height:1.5}
  button.primary{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button.primary:hover{background:#5b21b6}
  pre.message{white-space:pre-wrap;background:#faf9ff;border:1px solid #e7e4ee;border-radius:8px;padding:12px;font:14px/1.5 inherit;margin:0}

  .ql-form{background:#faf9ff;border:1px dashed #d8d4e2;border-radius:10px;padding:12px;margin-bottom:14px;display:none}
  .ql-form.show{display:block}
  .ql-form .row{display:grid;grid-template-columns:160px 1fr;gap:8px;margin-bottom:8px}

  .timeline{margin-top:6px}
  .ev{display:grid;grid-template-columns:32px 1fr;gap:10px;padding:12px 0;border-bottom:1px solid #f0eef5}
  .ev:last-child{border-bottom:0}
  .ev .ico{font-size:18px;line-height:1.2}
  .ev .head{font-size:13px;color:#0e0d12;font-weight:600}
  .ev .head .who{color:#6b6877;font-weight:500}
  .ev .meta{font-size:12px;color:#6b6877;margin-top:1px}
  .ev .body{font-size:14px;color:#0e0d12;margin-top:4px;white-space:pre-wrap;line-height:1.5}

  .task-list .t{display:grid;grid-template-columns:24px 1fr auto;gap:10px;align-items:start;padding:8px 0;border-bottom:1px solid #f0eef5}
  .task-list .t:last-child{border-bottom:0}
  .task-list .t.done{opacity:.5}
  .task-list .t.done .title{text-decoration:line-through}
  .task-list button.tick{width:20px;height:20px;border-radius:50%;border:1.5px solid #c9c4d4;background:#fff;cursor:pointer;color:transparent;font-size:11px;font-weight:700;padding:0}
  .task-list .t.done button.tick{background:#16a34a;border-color:#16a34a;color:#fff}
  .task-list .title{font-size:14px;font-weight:600}
  .task-list .meta{font-size:12px;color:#6b6877}
  .task-list .when{font-size:12px;color:#6b6877;font-variant-numeric:tabular-nums}
  .task-list .when.over{color:#dc2626;font-weight:700}

  .deal-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  @media (max-width: 700px){ .deal-row{grid-template-columns:1fr} .grid{grid-template-columns:1fr} }
  .mrr-tag{display:inline-block;background:#0e0d12;color:#fff;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;margin-left:8px}

  .tags-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:8px 0 16px}
  .tag-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;letter-spacing:.01em}
  .tag-pill .tag-x{background:transparent;border:0;cursor:pointer;color:inherit;font-size:14px;line-height:1;padding:0 0 0 4px;opacity:.6}
  .tag-pill .tag-x:hover{opacity:1}
  .tag-input{background:#fff;border:1px dashed #c9c4d4;color:#6b6877;padding:3px 10px;border-radius:999px;font-size:12px;font-family:inherit;width:80px}
  .tag-input:focus{outline:none;border-color:#6d28d9;color:#0e0d12}

  .templ-menu{position:relative;display:inline-block}
  .templ-menu .panel{display:none;position:absolute;top:calc(100% + 4px);left:0;background:#fff;border:1px solid #e7e4ee;border-radius:10px;min-width:280px;box-shadow:0 8px 24px rgba(13,11,30,.1);z-index:5;padding:6px}
  .templ-menu.open .panel{display:block}
  .templ-menu .panel a,.templ-menu .panel button.tpl-row{display:block;width:100%;text-align:left;padding:8px 12px;border-radius:6px;font-size:13px;color:#0e0d12;text-decoration:none;background:transparent;border:0;cursor:pointer;font-family:inherit}
  .templ-menu .panel button.tpl-row:hover,.templ-menu .panel a:hover{background:#faf9ff;color:#6d28d9}
  .templ-menu .panel .empty{padding:8px 12px;color:#6b6877;font-size:12px}
  .templ-menu .panel .divider{border-top:1px solid #f0eef5;margin:4px 0}
  .templ-menu .panel .hint{font-size:10px;color:#6b6877;margin-left:4px}
  .templ-menu .panel .blank{color:#6b6877}

  .files-list{display:grid;gap:6px;margin-top:6px}
  .files-list .f{display:grid;grid-template-columns:24px 1fr auto auto;gap:8px;align-items:center;padding:8px 10px;background:#faf9ff;border:1px solid #f0eef5;border-radius:8px;font-size:13px}
  .files-list .f .name{font-weight:600;color:#0e0d12;text-decoration:none}
  .files-list .f .name:hover{color:#6d28d9}
  .files-list .f .meta{font-size:11px;color:#6b6877}
  .files-list .f button.del{background:transparent;border:0;color:#dc2626;cursor:pointer;font-size:14px;opacity:.6;padding:0}
  .files-list .f button.del:hover{opacity:1}
  .upload-form{display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap}
  .upload-form input[type=file]{font-size:13px}
  .upload-form button{background:#0e0d12;color:#fff;border:0;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}

  .lost-reason-card{background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:14px;margin-top:14px}
  .lost-reason-card h3{margin:0 0 8px;font-size:13px;color:#991b1b;text-transform:uppercase;letter-spacing:.08em}

  .bant-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
  @media (max-width:700px){ .bant-row{grid-template-columns:repeat(2,1fr)} }
  .bant-cell{background:#faf9ff;border:1px solid #f0eef5;border-radius:8px;padding:8px}
  .bant-cell label{margin:0 0 4px;font-size:10px}
  .bant-cell select{padding:6px 8px;font-size:12px}

  .sends-list{margin-top:6px}
  .sends-list .s{display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid #f0eef5;font-size:13px}
  .sends-list .s:last-child{border-bottom:0}
  .sends-list .s .subj{font-weight:600;color:#0e0d12}
  .sends-list .s .meta{font-size:11px;color:#6b6877;margin-top:2px}
  .sends-list .s .stat{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px}
  .sends-list .s .stat.opened{background:#dcfce7;color:#166534}
  .sends-list .s .stat.clicked{background:#fae8ff;color:#6b21a8}
  .sends-list .s .stat.sent{background:#e0e7ff;color:#3730a3}
</style>

<main>
  <a class="back" href="/crm/">‹ Back to leads</a>
  <?php if ($saved): ?><div class="saved">Saved.</div><?php endif; ?>
  <?php if ($sendError): ?><div class="saved" style="background:#fee2e2;color:#991b1b">Email send failed: <?= crm_h($sendError) ?></div><?php endif; ?>
  <?php if ($fileError): ?><div class="saved" style="background:#fee2e2;color:#991b1b">Upload failed: <?= crm_h($fileError) ?></div><?php endif; ?>

  <div class="card">
    <h1>
      <?= crm_h(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))) ?: 'Unnamed lead' ?>
      <span class="pill s-<?= crm_h($lead['status']) ?>"><?= crm_h($lead['status']) ?></span>
      <?php if ($lead['temperature']): ?><span class="pill t-<?= crm_h($lead['temperature']) ?>"><?= crm_h($lead['temperature']) ?></span><?php endif; ?>
      <?php if ($mrr > 0): ?><span class="mrr-tag"><?= crm_h(crm_fmtMoney($mrr)) ?>/mo</span><?php endif; ?>
    </h1>

    <div class="tags-row">
      <?php foreach ($leadTags as $tg): ?>
        <span class="tag-pill" style="background:<?= crm_h($tg['color']) ?>20;color:<?= crm_h($tg['color']) ?>">
          <?= crm_h($tg['name']) ?>
          <form method="post" action="/crm/update.php" style="display:inline">
            <input type="hidden" name="mode" value="tag_remove">
            <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
            <input type="hidden" name="tag_id"  value="<?= (int)$tg['id'] ?>">
            <input type="hidden" name="csrf"    value="<?= crm_h(crm_csrfToken()) ?>">
            <button type="submit" title="Remove" class="tag-x">×</button>
          </form>
        </span>
      <?php endforeach; ?>
      <form method="post" action="/crm/update.php" class="tag-add" style="display:inline-flex;gap:4px;align-items:center">
        <input type="hidden" name="mode" value="tag_add">
        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <input type="text" name="tag" list="all-tags" placeholder="+ tag" class="tag-input" autocomplete="off">
        <datalist id="all-tags">
          <?php foreach ($allTags as $tg): ?><option value="<?= crm_h($tg['name']) ?>"><?php endforeach; ?>
        </datalist>
      </form>
    </div>
    <div class="sub">
      <span style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;color:#6b6877;font-weight:700"><?= crm_h($lead['source']) ?></span>
      · received <?= crm_h(substr((string)$lead['created_at'], 0, 16)) ?>
      <?php if ($lead['source_page']): ?>· from <?= crm_h($lead['source_page']) ?><?php endif; ?>
    </div>

    <div class="quick-actions">
      <?php if ($telHref):   ?><a class="qa primary" href="<?= crm_h($telHref) ?>">📞 Call <?= crm_h($lead['phone']) ?></a><?php endif; ?>
      <?php if ($emailHref): ?>
        <div class="templ-menu" id="tm">
          <button class="qa" onclick="document.getElementById('tm').classList.toggle('open')">✉️ Send template ▾</button>
          <div class="panel">
            <?php if (!$templates): ?>
              <div class="empty">No templates yet. <a href="/crm/templates.php" style="color:#6d28d9">Create one →</a></div>
            <?php else: foreach ($templates as $tpl): ?>
              <form method="post" action="/crm/update.php" style="margin:0">
                <input type="hidden" name="mode" value="template_send">
                <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
                <button type="submit" class="tpl-row" title="Send via Adverton (tracked)"><?= crm_h($tpl['name']) ?> <span class="hint">→ tracked send</span></button>
              </form>
            <?php endforeach; endif; ?>
            <div class="divider"></div>
            <a href="<?= crm_h($emailHref) ?>" class="blank">Blank email (mail client)</a>
          </div>
        </div>
      <?php endif; ?>
      <button class="qa" onclick="toggleQL('call')">Log call</button>
      <button class="qa" onclick="toggleQL('email')">Log email</button>
      <button class="qa" onclick="toggleQL('sms')">Log SMS</button>
      <button class="qa" onclick="toggleQL('note')">Add note</button>
      <button class="qa" onclick="toggleQL('task')">+ Task</button>
    </div>

    <?php foreach (['call','email','sms','note'] as $qt): ?>
      <form id="ql-<?= $qt ?>" class="ql-form" method="post" action="/crm/update.php">
        <input type="hidden" name="mode" value="activity">
        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
        <input type="hidden" name="type" value="<?= $qt ?>">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <?php if (isset(CRM_DISPOSITIONS[$qt])): ?>
        <div class="row">
          <select name="disposition">
            <option value="">— Disposition —</option>
            <?php foreach (CRM_DISPOSITIONS[$qt] as $d): ?>
              <option value="<?= crm_h($d) ?>"><?= crm_h(str_replace('_',' ',$d)) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="body" placeholder="What happened? (optional)">
        </div>
        <?php else: ?>
          <textarea name="body" placeholder="<?= $qt==='note'?'Note...':'What happened?' ?>" required></textarea>
        <?php endif; ?>
        <button type="submit" class="primary">Save</button>
      </form>
    <?php endforeach; ?>

    <form id="ql-task" class="ql-form" method="post" action="/crm/update.php">
      <input type="hidden" name="mode" value="task_create">
      <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
      <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
      <div class="row"><input type="text" name="title" placeholder="What needs to happen?" required>
                       <input type="datetime-local" name="due_at" value="<?= date('Y-m-d') ?>T17:00" required></div>
      <select name="assigned_to">
        <option value="<?= (int)$user['id'] ?>">Assign to me</option>
        <?php foreach ($users as $u): if ((int)$u['id']===(int)$user['id']) continue; ?>
          <option value="<?= (int)$u['id'] ?>">Assign to <?= crm_h($u['display_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="primary">Add task</button>
    </form>

    <div class="grid">
      <div><div class="k">Email</div><div class="v"><?= crm_field($lead['email']) ?></div></div>
      <div><div class="k">Phone</div><div class="v"><?= crm_field($lead['phone']) ?></div></div>
      <div><div class="k">Business</div><div class="v"><?= crm_field($lead['business_name']) ?></div></div>
      <div><div class="k">Trade</div><div class="v"><?= crm_field($lead['trade']) ?></div></div>
      <div><div class="k">City / State</div><div class="v"><?= crm_field($lead['city_state']) ?></div></div>
      <div><div class="k">Website</div><div class="v"><?= $lead['website'] ? '<a href="' . crm_h($lead['website']) . '" target="_blank" rel="noopener">' . crm_h($lead['website']) . '</a>' : '<span style="color:#bcb6ca">—</span>' ?></div></div>
      <?php if ($lead['gbp_url']): ?>
        <div style="grid-column:1/-1"><div class="k">Google Business URL</div><div class="v"><a href="<?= crm_h($lead['gbp_url']) ?>" target="_blank" rel="noopener"><?= crm_h($lead['gbp_url']) ?></a></div></div>
      <?php endif; ?>
      <?php if ($lead['audit_score'] !== null): ?>
        <div><div class="k">Audit score</div><div class="v"><?= (int)$lead['audit_score'] ?>/100</div></div>
        <div><div class="k">Audit ID</div><div class="v"><?= crm_field($lead['audit_id']) ?></div></div>
      <?php endif; ?>
      <?php if ($lead['revenue']): ?>
        <div><div class="k">Revenue</div><div class="v"><?= crm_field($lead['revenue']) ?></div></div>
      <?php endif; ?>
      <?php if ($lead['utm_source'] || $lead['utm_medium'] || $lead['utm_campaign']): ?>
        <div><div class="k">UTM source / medium</div><div class="v"><?= crm_field($lead['utm_source']) ?> / <?= crm_field($lead['utm_medium']) ?></div></div>
        <div><div class="k">UTM campaign</div><div class="v"><?= crm_field($lead['utm_campaign']) ?></div></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($lead['message'])): ?>
      <label>Message from form</label>
      <pre class="message"><?= crm_h($lead['message']) ?></pre>
    <?php endif; ?>
  </div>

  <div class="grid2">
    <div>
      <div class="card">
        <h2>Activity timeline</h2>
        <div class="timeline">
          <?php if (!$activities): ?>
            <div style="color:#6b6877;font-size:13px">No activity yet. Use the buttons above to log calls, emails, or notes.</div>
          <?php else: foreach ($activities as $a): ?>
            <div class="ev">
              <div class="ico"><?= crm_activityIcon($a['type']) ?></div>
              <div>
                <div class="head">
                  <?= crm_h(crm_activityLabel($a['type'], $a['disposition'])) ?>
                  <span class="who">— <?= crm_h($a['user_name'] ?? 'system') ?></span>
                </div>
                <div class="meta"><?= crm_h(crm_fmtRelative($a['created_at'])) ?> · <?= crm_h(substr((string)$a['created_at'], 0, 16)) ?></div>
                <?php if (!empty($a['body'])): ?>
                  <div class="body"><?= crm_h($a['body']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <div>
      <form class="card" method="post" action="/crm/update.php">
        <h2>Pipeline</h2>
        <input type="hidden" name="mode" value="pipeline">
        <input type="hidden" name="id" value="<?= (int)$lead['id'] ?>">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

        <div class="grid">
          <div>
            <label>Status</label>
            <select name="status">
              <?php foreach (CRM_LEAD_STATUSES as $s): ?>
                <option value="<?= crm_h($s) ?>" <?= $lead['status']===$s?'selected':'' ?>><?= crm_h(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Owner</label>
            <select name="owner_user_id">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$lead['owner_user_id']===(int)$u['id']?'selected':'' ?>><?= crm_h($u['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Temperature</label>
            <select name="temperature">
              <option value="">— Auto —</option>
              <?php foreach (['hot','warm','cold'] as $t): ?>
                <option value="<?= $t ?>" <?= $lead['temperature']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Expected close</label>
            <input type="date" name="expected_close_at" value="<?= crm_h($lead['expected_close_at'] ?? '') ?>">
          </div>
        </div>

        <label style="margin-top:18px">Deal — Adverton MRR = monthly fee + (ad budget × mgmt %)</label>
        <div class="deal-row">
          <div>
            <label style="margin:6px 0 4px;text-transform:none;font-size:12px;color:#6b6877;font-weight:500">Monthly fee ($)</label>
            <input type="number" step="0.01" min="0" name="monthly_fee" value="<?= crm_h($lead['monthly_fee'] !== null ? (string)$lead['monthly_fee'] : '799') ?>">
          </div>
          <div>
            <label style="margin:6px 0 4px;text-transform:none;font-size:12px;color:#6b6877;font-weight:500">Ad budget / mo ($)</label>
            <input type="number" step="0.01" min="0" name="ad_budget" value="<?= crm_h($lead['ad_budget'] !== null ? (string)$lead['ad_budget'] : '') ?>" placeholder="optional">
          </div>
          <div>
            <label style="margin:6px 0 4px;text-transform:none;font-size:12px;color:#6b6877;font-weight:500">Mgmt fee (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="mgmt_fee_pct" value="<?= crm_h($lead['mgmt_fee_pct'] !== null ? (string)$lead['mgmt_fee_pct'] : '0') ?>">
          </div>
        </div>

        <label>Internal notes</label>
        <textarea name="notes" placeholder="Stable notes about this lead (use timeline for events)..."><?= crm_h($lead['notes'] ?? '') ?></textarea>

        <?php if ($lead['status'] === 'lost' || ($lead['lost_reason'] ?? null)): ?>
        <div class="lost-reason-card">
          <h3>Lost — why?</h3>
          <select name="lost_reason">
            <option value="">— Pick a reason —</option>
            <?php foreach (['price'=>'Price','not_a_fit'=>'Not the right fit','competitor'=>'Went with competitor','no_response'=>'No response','timing'=>'Bad timing','other'=>'Other'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($lead['lost_reason'] ?? '')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="lost_reason_note" placeholder="Optional details (objection, competitor name, etc)" value="<?= crm_h($lead['lost_reason_note'] ?? '') ?>" style="margin-top:6px;width:100%;background:#fff;border:1px solid #fca5a5;color:#0e0d12;padding:8px 10px;border-radius:6px;font-size:13px;box-sizing:border-box">
        </div>
        <?php endif; ?>

        <?php if ($lead['status'] === 'won'): ?>
        <label style="margin-top:14px">Won — what closed it?</label>
        <input type="text" name="won_reason_note" placeholder="What did the trick? (use case, demo, price, etc)" value="<?= crm_h($lead['won_reason_note'] ?? '') ?>">
        <?php endif; ?>

        <label style="margin-top:14px">BANT — qualification</label>
        <div class="bant-row">
          <div class="bant-cell">
            <label>Budget</label>
            <select name="bant_budget">
              <option value="">?</option>
              <option value="yes"    <?= ($lead['bant_budget']    ?? '')==='yes'?'selected':''    ?>>Yes</option>
              <option value="no"     <?= ($lead['bant_budget']    ?? '')==='no'?'selected':''     ?>>No</option>
              <option value="unsure" <?= ($lead['bant_budget']    ?? '')==='unsure'?'selected':'' ?>>Unsure</option>
            </select>
          </div>
          <div class="bant-cell">
            <label>Authority</label>
            <select name="bant_authority">
              <option value="">?</option>
              <option value="yes"    <?= ($lead['bant_authority'] ?? '')==='yes'?'selected':''    ?>>Yes (decider)</option>
              <option value="no"     <?= ($lead['bant_authority'] ?? '')==='no'?'selected':''     ?>>No</option>
              <option value="unsure" <?= ($lead['bant_authority'] ?? '')==='unsure'?'selected':'' ?>>Unsure</option>
            </select>
          </div>
          <div class="bant-cell">
            <label>Need</label>
            <select name="bant_need">
              <option value="">?</option>
              <option value="yes"    <?= ($lead['bant_need']      ?? '')==='yes'?'selected':''    ?>>Clear pain</option>
              <option value="no"     <?= ($lead['bant_need']      ?? '')==='no'?'selected':''     ?>>No</option>
              <option value="unsure" <?= ($lead['bant_need']      ?? '')==='unsure'?'selected':'' ?>>Unsure</option>
            </select>
          </div>
          <div class="bant-cell">
            <label>Timeline</label>
            <select name="bant_timeline">
              <option value="">?</option>
              <option value="asap"  <?= ($lead['bant_timeline'] ?? '')==='asap'?'selected':''  ?>>ASAP</option>
              <option value="30d"   <?= ($lead['bant_timeline'] ?? '')==='30d'?'selected':''   ?>>30 days</option>
              <option value="90d"   <?= ($lead['bant_timeline'] ?? '')==='90d'?'selected':''   ?>>90 days</option>
              <option value="later" <?= ($lead['bant_timeline'] ?? '')==='later'?'selected':'' ?>>Later</option>
              <option value="none"  <?= ($lead['bant_timeline'] ?? '')==='none'?'selected':''  ?>>No timeline</option>
            </select>
          </div>
        </div>
        <textarea name="bant_notes" placeholder="Pain points, decision criteria, competition mentioned..." style="margin-top:8px;min-height:60px"><?= crm_h($lead['bant_notes'] ?? '') ?></textarea>

        <button type="submit" class="primary">Save</button>
      </form>

      <form class="card" method="post" action="/crm/update.php"
            onsubmit="return confirm('Delete this lead permanently?\n\nAll activities, tasks, files, tags, and email tracking will be removed. Any linked client survives but loses the lead history reference.\n\nThis cannot be undone.')"
            style="border-color:#fecaca;background:#fffafa">
        <h2 style="color:#991b1b">Danger zone</h2>
        <input type="hidden" name="mode" value="lead_delete">
        <input type="hidden" name="id" value="<?= (int)$lead['id'] ?>">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <p style="font-size:13px;color:#6b6877;margin:0 0 10px">Hard-deletes the lead and all its data (timeline, tasks, files, tags, email sends).</p>
        <button type="submit" style="background:#dc2626;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer">🗑 Delete lead</button>
      </form>

      <div class="card">
        <h2>Tasks for this lead</h2>
        <div class="task-list">
          <?php if (!$leadTasks): ?>
            <div style="color:#6b6877;font-size:13px">No tasks. Click "+ Task" above to add a follow-up.</div>
          <?php else: foreach ($leadTasks as $t):
            $done = $t['done_at'] !== null;
            $isOver = !$done && strtotime((string)$t['due_at']) < time();
          ?>
            <div class="t <?= $done?'done':'' ?>">
              <form method="post" action="/crm/update.php" style="margin:0">
                <input type="hidden" name="mode" value="<?= $done?'task_uncomplete':'task_complete' ?>">
                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
                <button type="submit" class="tick">✓</button>
              </form>
              <div>
                <div class="title"><?= crm_h($t['title']) ?></div>
                <div class="meta"><?= crm_h($t['assignee_name'] ?? 'unassigned') ?></div>
              </div>
              <div class="when <?= $isOver?'over':'' ?>"><?= crm_h(date('M j · g:ia', strtotime((string)$t['due_at']))) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="card">
        <h2>Files</h2>
        <?php if (!$leadFiles): ?>
          <div style="color:#6b6877;font-size:13px">No files yet. Upload contracts, signed proposals, screenshots…</div>
        <?php else: ?>
          <div class="files-list">
            <?php foreach ($leadFiles as $f):
              $isImage = str_starts_with((string)$f['mime'], 'image/');
              $icon = $isImage ? '🖼️' : (in_array($f['mime'],['application/pdf'],true) ? '📄' : '📎');
            ?>
              <div class="f">
                <span><?= $icon ?></span>
                <div>
                  <a class="name" href="/crm/file.php?id=<?= (int)$f['id'] ?>"><?= crm_h($f['original_name']) ?></a>
                  <div class="meta"><?= crm_h(crm_fmtFileSize((int)$f['size_bytes'])) ?> · <?= crm_h(crm_fmtRelative($f['uploaded_at'])) ?> · <?= crm_h($f['uploader'] ?? 'system') ?></div>
                </div>
                <a href="/crm/file.php?id=<?= (int)$f['id'] ?>&inline=1" target="_blank" rel="noopener" style="font-size:12px;color:#6d28d9;text-decoration:none">View</a>
                <form method="post" action="/crm/update.php" onsubmit="return confirm('Delete this file?')" style="margin:0">
                  <input type="hidden" name="mode" value="file_delete">
                  <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                  <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
                  <button type="submit" class="del" title="Delete">🗑</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form class="upload-form" method="post" action="/crm/update.php" enctype="multipart/form-data">
          <input type="hidden" name="mode" value="file_upload">
          <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <input type="file" name="file" required>
          <button type="submit">Upload</button>
        </form>
      </div>

      <?php if ($emailSends): ?>
      <div class="card">
        <h2>Sent emails (tracked)</h2>
        <div class="sends-list">
          <?php foreach ($emailSends as $s):
            $stat = $s['first_clicked_at'] ? 'clicked' : ($s['first_opened_at'] ? 'opened' : 'sent');
            $statLabel = $stat === 'clicked' ? "Clicked × {$s['click_count']}"
                       : ($stat === 'opened' ? "Opened × {$s['open_count']}" : 'Sent');
          ?>
            <div class="s">
              <div>
                <div class="subj"><?= crm_h($s['subject']) ?></div>
                <div class="meta">
                  <?= crm_h($s['template_name'] ?? 'custom') ?>
                  · <?= crm_h(crm_fmtRelative($s['sent_at'])) ?>
                  <?php if ($s['first_opened_at']): ?> · opened <?= crm_h(crm_fmtRelative($s['first_opened_at'])) ?><?php endif; ?>
                  <?php if ($s['first_clicked_at']): ?> · clicked <?= crm_h(crm_fmtRelative($s['first_clicked_at'])) ?><?php endif; ?>
                </div>
              </div>
              <span class="stat <?= $stat ?>"><?= crm_h($statLabel) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
const CSRF_TOKEN = <?= json_encode(crm_csrfToken()) ?>;
function toggleQL(name){
  document.querySelectorAll('.ql-form').forEach(f => {
    if (f.id === 'ql-' + name) f.classList.toggle('show');
    else f.classList.remove('show');
  });
}
async function logTemplateUse(el, leadId, templateId, name){
  // Fire async POST to log activity, then let the mailto: open normally
  const fd = new FormData();
  fd.set('mode', 'activity');
  fd.set('lead_id', leadId);
  fd.set('type', 'email');
  fd.set('disposition', 'sent');
  fd.set('body', 'Sent template: ' + name);
  fd.set('csrf', CSRF_TOKEN);
  try { fetch('/crm/update.php', { method: 'POST', body: fd, credentials: 'same-origin' }); } catch (e) {}
  // Don't preventDefault — mailto: opens in default email client
  document.getElementById('tm')?.classList.remove('open');
}
document.addEventListener('click', e => {
  const tm = document.getElementById('tm');
  if (tm && !tm.contains(e.target)) tm.classList.remove('open');
});
</script>
</body></html>
