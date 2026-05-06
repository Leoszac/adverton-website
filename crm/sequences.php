<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/sequences.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']);

$editId  = (int)($_GET['edit'] ?? 0);
$editing = $editId > 0 ? crm_getSequence($editId) : null;
$saved   = ($_GET['saved'] ?? '') === '1';
$list    = crm_listSequences();
$tpls    = crm_listTemplates();

$enrFilter = (string)($_GET['enr'] ?? '');
$enrFilter = in_array($enrFilter, ['active','completed','unenrolled'], true) ? $enrFilter : '';
$stats = $editing ? crm_getSequenceStats((int)$editing['id']) : null;
$enrollments = $editing ? crm_listEnrollmentsForSequence((int)$editing['id'], $enrFilter ?: null, 100) : [];

// Pre-serialize templates and existing steps for the JS step builder.
$tplsForJs = array_map(fn($t) => ['id'=>(int)$t['id'], 'name'=>(string)$t['name']], $tpls);
$stepsForJs = [];
if ($editing && !empty($editing['steps'])) {
    foreach ($editing['steps'] as $st) {
        $stepsForJs[] = [
            'delay_days' => (int)$st['delay_days'],
            'action'     => (string)$st['action'],
            'payload'    => json_decode((string)$st['payload'], true) ?: [],
        ];
    }
}

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
  input[type=text],input[type=number],select,textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;font-family:inherit;box-sizing:border-box}
  button.primary{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .new-btn{background:#6d28d9;color:#fff;border:0;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .badge{display:inline-block;background:#dcfce7;color:#166534;font-size:11px;font-weight:600;padding:1px 8px;border-radius:999px;margin-left:6px}
  .badge.off{background:#fee2e2;color:#991b1b}

  /* Stats card */
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin:0}
  .stat{background:#f7f6fb;border:1px solid #ece9f3;border-radius:10px;padding:12px 14px}
  .stat .num{font-size:22px;font-weight:700;color:#0e0d12;line-height:1}
  .stat .lbl{font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;font-weight:600}
  .stat.completion .num{color:#16a34a}

  /* Step builder */
  .step{background:#f9f8fc;border:1px solid #ece9f3;border-radius:10px;padding:14px;margin-bottom:10px;position:relative}
  .step-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
  .step-head .step-num{font-size:11px;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:.08em}
  .step-head .move{display:flex;gap:4px}
  .step-head button{background:#fff;border:1px solid #e7e4ee;color:#6b6877;width:26px;height:26px;border-radius:6px;cursor:pointer;font-size:12px;line-height:1}
  .step-head button:hover{background:#f3f1f8}
  .step-head .delete{color:#dc2626}
  .step-grid{display:grid;grid-template-columns:90px 1fr 1.4fr;gap:10px}
  .step-grid label{margin:0 0 4px}
  .step-grid input,.step-grid select{padding:7px 10px;font-size:13px}
  .add-step{margin-top:6px;background:#fff;border:1.5px dashed #c7c2d6;color:#6d28d9;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;width:100%}
  .add-step:hover{background:#f3f1f8;border-color:#6d28d9}
  .empty-steps{padding:24px;text-align:center;color:#6b6877;font-size:13px;background:#f9f8fc;border-radius:10px;margin-bottom:10px}

  /* Enrollments table */
  .enr-tabs{display:flex;gap:4px;margin-bottom:10px;flex-wrap:wrap}
  .enr-tabs a{padding:5px 12px;font-size:12px;border-radius:999px;background:#f3f1f8;color:#6b6877;text-decoration:none;font-weight:600}
  .enr-tabs a.cur{background:#6d28d9;color:#fff}
  table.enr{width:100%;border-collapse:collapse;font-size:13px}
  table.enr th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b6877;padding:8px 8px;border-bottom:1px solid #ece9f3;font-weight:600}
  table.enr td{padding:8px 8px;border-bottom:1px solid #f5f3f9;vertical-align:top}
  table.enr td a{color:#0e0d12;text-decoration:none;font-weight:600}
  table.enr td a:hover{color:#6d28d9}
  table.enr .reason{font-size:11px;color:#6b6877;font-style:italic}
  .empty-enr{padding:18px;text-align:center;color:#6b6877;font-size:13px}
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
        <?php if ($editing && $stats): ?>
          <div class="card">
            <h2>Performance</h2>
            <div class="stats-grid">
              <div class="stat"><div class="num"><?= (int)$stats['total'] ?></div><div class="lbl">Total enrolled</div></div>
              <div class="stat"><div class="num"><?= (int)$stats['active'] ?></div><div class="lbl">Active now</div></div>
              <div class="stat"><div class="num"><?= (int)$stats['completed'] ?></div><div class="lbl">Finished all steps</div></div>
              <div class="stat"><div class="num"><?= (int)$stats['unenrolled'] ?></div><div class="lbl">Unenrolled early</div></div>
              <div class="stat completion"><div class="num"><?= number_format((float)$stats['completion_rate'], 1) ?>%</div><div class="lbl">Completion rate</div></div>
            </div>
          </div>
        <?php endif; ?>

        <form class="card" method="post" action="/crm/update.php" id="seqForm">
          <h2><?= $editing ? 'Edit sequence' : 'New sequence' ?></h2>
          <input type="hidden" name="mode" value="sequence_save">
          <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <input type="hidden" name="steps_json" id="steps_json" value="">

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
              <label>Trigger value <span style="text-transform:none;font-weight:400;color:#6b6877">(optional — e.g. <code>audit_auto</code>)</span></label>
              <input type="text" name="trigger_value" value="<?= $editing ? crm_h((string)($editing['trigger_value'] ?? '')) : '' ?>">
            </div>
          </div>

          <div class="row2">
            <div>
              <label>Active?</label>
              <select name="active">
                <option value="1" <?= ($editing['active'] ?? 1) ? 'selected':'' ?>>Yes</option>
                <option value="0" <?= !($editing['active'] ?? 1) ? 'selected':'' ?>>No</option>
              </select>
            </div>
            <div></div>
          </div>

          <label style="margin-top:18px">Steps <span style="text-transform:none;font-weight:400;color:#6b6877">(run in order; delay is days from previous step)</span></label>
          <div id="steps"></div>
          <button type="button" class="add-step" onclick="addStep()">+ Add step</button>

          <button type="submit" class="primary">Save sequence</button>
        </form>

        <?php if ($editing): ?>
          <div class="card">
            <h2>Leads in this sequence (<?= count($enrollments) ?><?= count($enrollments)>=100?'+':'' ?>)</h2>
            <div class="enr-tabs">
              <a href="?edit=<?= (int)$editing['id'] ?>"          class="<?= $enrFilter===''?'cur':'' ?>">All</a>
              <a href="?edit=<?= (int)$editing['id'] ?>&enr=active"     class="<?= $enrFilter==='active'?'cur':'' ?>">Active</a>
              <a href="?edit=<?= (int)$editing['id'] ?>&enr=completed"  class="<?= $enrFilter==='completed'?'cur':'' ?>">Completed</a>
              <a href="?edit=<?= (int)$editing['id'] ?>&enr=unenrolled" class="<?= $enrFilter==='unenrolled'?'cur':'' ?>">Unenrolled</a>
            </div>
            <?php if (!$enrollments): ?>
              <div class="empty-enr">No leads<?= $enrFilter ? ' in this state' : '' ?> yet.</div>
            <?php else: ?>
              <table class="enr">
                <thead><tr>
                  <th>Lead</th><th>Step</th><th>Next / Finished</th><th>State</th>
                </tr></thead>
                <tbody>
                <?php foreach ($enrollments as $e): ?>
                  <?php
                    $name = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
                    if ($name === '') $name = $e['business_name'] ?: ($e['email'] ?: ('Lead #' . (int)$e['lead_id']));
                    $isActive = empty($e['completed_at']);
                    $reason   = (string)($e['unenrolled_reason'] ?? '');
                    $stateLabel = $isActive ? 'Active'
                                : ($reason === 'completed' ? 'Completed'
                                : ('Unenrolled · ' . $reason));
                    $when = $isActive
                                ? crm_fmtRelative($e['next_run_at'])
                                : crm_fmtRelative((string)($e['completed_at'] ?? ''));
                  ?>
                  <tr>
                    <td><a href="/crm/lead.php?id=<?= (int)$e['lead_id'] ?>"><?= crm_h($name) ?></a>
                        <?php if (!empty($e['business_name']) && $name !== $e['business_name']): ?>
                          <div class="reason"><?= crm_h($e['business_name']) ?></div>
                        <?php endif; ?></td>
                    <td><?= (int)$e['current_step'] ?> / <?= count($editing['steps']) ?></td>
                    <td><?= crm_h($when) ?></td>
                    <td>
                      <?php if ($isActive): ?>
                        <span class="badge">Active</span>
                      <?php elseif ($reason === 'completed'): ?>
                        <span class="badge">Completed</span>
                      <?php else: ?>
                        <span class="badge off"><?= crm_h($reason) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
(function(){
  const TEMPLATES = <?= json_encode($tplsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const INITIAL_STEPS = <?= json_encode($stepsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const ACTIONS = [
    { v: 'send_template', label: 'Send email template' },
    { v: 'create_task',   label: 'Create task'         },
    { v: 'add_tag',       label: 'Add tag'             },
    { v: 'remove_tag',    label: 'Remove tag'          },
  ];

  const stepsEl = document.getElementById('steps');
  let steps = Array.isArray(INITIAL_STEPS) ? INITIAL_STEPS.slice() : [];

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  function payloadEditor(action, payload){
    payload = payload || {};
    if (action === 'send_template'){
      const cur = parseInt(payload.template_id || 0, 10);
      const opts = ['<option value="">— pick a template —</option>']
        .concat(TEMPLATES.map(t => `<option value="${t.id}" ${t.id===cur?'selected':''}>${escapeHtml(t.name)}</option>`))
        .join('');
      return `<select data-pkey="template_id">${opts}</select>`;
    }
    if (action === 'create_task'){
      const title = escapeHtml(payload.title || '');
      return `<input type="text" data-pkey="title" placeholder="Task title (e.g. Call {first_name})" value="${title}">`;
    }
    if (action === 'add_tag' || action === 'remove_tag'){
      const tag = escapeHtml(payload.tag || '');
      return `<input type="text" data-pkey="tag" placeholder="Tag name" value="${tag}">`;
    }
    return '';
  }

  function render(){
    if (!steps.length){
      stepsEl.innerHTML = '<div class="empty-steps">No steps yet. Click "Add step" to start.</div>';
      return;
    }
    stepsEl.innerHTML = steps.map((st, i) => {
      const actOpts = ACTIONS.map(a => `<option value="${a.v}" ${a.v===st.action?'selected':''}>${a.label}</option>`).join('');
      return `
        <div class="step" data-i="${i}">
          <div class="step-head">
            <div class="step-num">Step ${i+1}</div>
            <div class="move">
              <button type="button" title="Move up"   onclick="window.__seqMove(${i}, -1)" ${i===0?'disabled':''}>↑</button>
              <button type="button" title="Move down" onclick="window.__seqMove(${i},  1)" ${i===steps.length-1?'disabled':''}>↓</button>
              <button type="button" class="delete" title="Remove step" onclick="window.__seqDel(${i})">✕</button>
            </div>
          </div>
          <div class="step-grid">
            <div>
              <label>Delay (days)</label>
              <input type="number" min="0" max="365" data-field="delay_days" value="${parseInt(st.delay_days||0,10)}">
            </div>
            <div>
              <label>Action</label>
              <select data-field="action">${actOpts}</select>
            </div>
            <div>
              <label>Details</label>
              <div data-field="payload">${payloadEditor(st.action, st.payload)}</div>
            </div>
          </div>
        </div>`;
    }).join('');
  }

  function readDom(){
    document.querySelectorAll('#steps .step').forEach(div => {
      const i = parseInt(div.dataset.i, 10);
      const delay  = parseInt(div.querySelector('[data-field="delay_days"]').value || '0', 10);
      const action = div.querySelector('[data-field="action"]').value;
      const payloadDiv = div.querySelector('[data-field="payload"]');
      const payload = {};
      payloadDiv.querySelectorAll('[data-pkey]').forEach(el => {
        const k = el.dataset.pkey;
        const v = el.value.trim();
        if (v !== '') payload[k] = (k === 'template_id') ? parseInt(v, 10) : v;
      });
      steps[i] = { delay_days: Math.max(0, delay), action, payload };
    });
  }

  // When action changes, re-render so payload editor matches new action
  stepsEl.addEventListener('change', e => {
    if (e.target.matches('[data-field="action"]')){
      readDom();
      render();
    }
  });

  window.__seqMove = (i, dir) => {
    readDom();
    const j = i + dir;
    if (j < 0 || j >= steps.length) return;
    [steps[i], steps[j]] = [steps[j], steps[i]];
    render();
  };
  window.__seqDel = (i) => {
    readDom();
    steps.splice(i, 1);
    render();
  };
  window.addStep = () => {
    readDom();
    steps.push({ delay_days: 0, action: 'send_template', payload: {} });
    render();
  };

  // Serialize on submit
  document.getElementById('seqForm').addEventListener('submit', () => {
    readDom();
    document.getElementById('steps_json').value = JSON.stringify(steps);
  });

  render();
})();
</script>
</body></html>
