<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();

$leadId = (int)($_GET['lead_id'] ?? 0);
$tplId  = (int)($_GET['template_id'] ?? 0);

$lead = $leadId > 0 ? crm_getLead($leadId) : null;
$tpl  = $tplId  > 0 ? crm_getTemplate($tplId) : null;
if (!$lead) { http_response_code(404); header('Location: /crm/'); exit; }

// If a template is given, pre-render. Otherwise blank (free-form email).
$subject = $tpl ? crm_renderTemplate($tpl['subject'], $lead) : '';
$body    = $tpl ? crm_renderTemplate($tpl['body'],    $lead) : '';

$err = (string)($_GET['err'] ?? '');

// Detect unfilled placeholders so we can warn the user
$missing = [];
foreach (CRM_TEMPLATE_VARS as $v) {
    if (empty($lead[$v])) $missing[] = '{' . $v . '}';
}
$missing = array_values(array_intersect($missing, array_filter(array_map(
    fn($v) => '{' . $v . '}',
    CRM_TEMPLATE_VARS
), fn($p) => str_contains((string)$subject . (string)$body, $p))));

crm_renderHead('Compose email');
crm_renderHeader($user, '');
?>
<style>
  .wrap{max-width:780px;margin:0 auto}
  .back{font-size:13px;color:#6b6877;text-decoration:none;display:inline-block;margin-bottom:14px}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px}
  h1{margin:0 0 4px;font-size:22px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:14px}
  .err{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .warn{background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=text],input[type=email],textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:10px 12px;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
  textarea{min-height:340px;line-height:1.5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif}
  .meta-line{font-size:13px;color:#6b6877;background:#faf9ff;border:1px solid #e7e4ee;padding:8px 12px;border-radius:8px}
  .meta-line strong{color:#0e0d12}
  .actions{display:flex;gap:10px;margin-top:18px;align-items:center}
  button.primary{background:#6d28d9;color:#fff;border:0;padding:11px 22px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button.primary:hover{background:#5b21b6}
  a.cancel{color:#6b6877;text-decoration:none;font-size:13px;font-weight:600;padding:11px 14px}
  .vars{font-size:12px;color:#6b6877;margin-top:8px}
  .vars code{background:#faf9ff;border:1px solid #e7e4ee;padding:1px 6px;border-radius:4px;color:#6d28d9;font-size:11px;cursor:pointer}
  .charcount{font-size:11px;color:#a8a3b3;margin-top:4px;text-align:right}
</style>
<main class="wrap">
  <a class="back" href="/crm/lead.php?id=<?= (int)$lead['id'] ?>">‹ Back to lead</a>

  <form class="card" method="post" action="/crm/update.php">
    <h1>Compose email</h1>
    <?php if ($tpl): ?>
      <div class="sub">Using template: <strong><?= crm_h($tpl['name']) ?></strong> · edit anything below before sending.</div>
    <?php else: ?>
      <div class="sub">Free-form email · tracked + logged on the lead timeline.</div>
    <?php endif; ?>

    <?php if ($err): ?><div class="err">Send failed: <?= crm_h($err) ?></div><?php endif; ?>
    <?php if ($missing): ?>
      <div class="warn">Heads up — these template placeholders are empty for this lead, you'll see blanks: <?= crm_h(implode(' · ', $missing)) ?></div>
    <?php endif; ?>

    <input type="hidden" name="mode" value="template_send">
    <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
    <?php if ($tpl): ?><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <label>To</label>
    <div class="meta-line">
      <strong><?= crm_h(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?: 'Lead #' . $lead['id']) ?></strong>
      &lt;<?= crm_h($lead['email'] ?? '—') ?>&gt;
    </div>

    <label>Subject</label>
    <input type="text" name="subject" value="<?= crm_h($subject) ?>" required maxlength="255" autocomplete="off">

    <label>Body</label>
    <textarea name="body" required><?= crm_h($body) ?></textarea>
    <div class="vars">
      Variables (click to insert at cursor):
      <?php foreach (CRM_TEMPLATE_VARS as $v): ?><code onclick="insertVar('{<?= $v ?>}')">{<?= $v ?>}</code> <?php endforeach; ?>
    </div>

    <div class="actions">
      <button type="submit" class="primary">Send via Adverton (tracked)</button>
      <a class="cancel" href="/crm/lead.php?id=<?= (int)$lead['id'] ?>">Cancel</a>
    </div>
  </form>
</main>
<script>
function insertVar(v){
  const ta = document.querySelector('textarea[name="body"]');
  const start = ta.selectionStart, end = ta.selectionEnd;
  ta.value = ta.value.slice(0, start) + v + ta.value.slice(end);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = start + v.length;
}
</script>
</body></html>
