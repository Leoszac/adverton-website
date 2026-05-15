<?php
// Preview + test-send tool for the nurture sequences. Renders every step's
// subject+body inline using mock lead data, and has a "fire all to my inbox"
// button so you can QA delivery + formatting end-to-end.
//
// Token-gated.
//   GET  /crm/_preview-nurture.php?go=SEED_TOKEN
//   POST /crm/_preview-nurture.php?go=SEED_TOKEN
//        action=send_one  step_id=<id>  to=<email>
//        action=send_all  to=<email>

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/templates.php';

$token = (string) crm_config('SEED_TOKEN');
$got   = (string) ($_REQUEST['go'] ?? '');
if (!hash_equals($token, $got)) {
    http_response_code(403);
    exit("forbidden — pass ?go=<SEED_TOKEN>\n");
}

// Mock lead data. Mirrors the template-var list in lib/templates.php plus
// the {company} alias we wired into the renderer.
$mockLead = [
    'first_name'    => 'Leo',
    'last_name'     => 'Szachtman',
    'business_name' => 'Test Plumbing Co',
    'trade'         => 'Plumbing',
    'city_state'    => 'Brooklyn, NY',
    'audit_score'   => '50',
    'website'       => 'testplumbing.example',
];

// ─────────────────────────────────────────────────────────────
// POST: send action(s) via Resend, then redirect with a flash message.
// Uses Resend directly (no email_sends rows, no DB pollution from QA sends).
// ─────────────────────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $to     = trim((string) ($_POST['to'] ?? ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $flash = ['type' => 'err', 'msg' => 'Need a valid recipient email.'];
    } else {
        $stepIdsToSend = [];
        if ($action === 'send_one') {
            $stepIdsToSend = [(int) ($_POST['step_id'] ?? 0)];
        } elseif ($action === 'send_all') {
            $rows = crm_db()->query(
                "SELECT id FROM sequence_steps WHERE action = 'send_template' ORDER BY sequence_id, step_order"
            )->fetchAll();
            $stepIdsToSend = array_map(fn($r) => (int)$r['id'], $rows);
        }

        if (!$stepIdsToSend) {
            $flash = ['type' => 'err', 'msg' => 'No steps matched.'];
        } else {
            $apiKey  = (string) crm_config('RESEND_API_KEY');
            $fromRaw = (string) crm_config('CRM_FROM_ADDRESS') ?: 'Adverton <leo@adverton.net>';
            $reply   = (string) crm_config('CRM_REPLY_TO')     ?: 'leo@adverton.net';

            if (!$apiKey) {
                $flash = ['type' => 'err', 'msg' => 'RESEND_API_KEY not set in crm-config.php'];
            } else {
                $ok = 0; $fail = 0; $errs = [];
                foreach ($stepIdsToSend as $sid) {
                    $stmt = crm_db()->prepare(
                        "SELECT s.*, t.subject AS tpl_subject, t.body AS tpl_body, t.name AS tpl_name
                           FROM sequence_steps s
                           JOIN email_templates t ON t.id = JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.template_id'))
                          WHERE s.id = ?"
                    );
                    $stmt->execute([$sid]);
                    $row = $stmt->fetch();
                    if (!$row) { $fail++; $errs[] = "step #{$sid}: not found"; continue; }

                    $subject = '[TEST] ' . crm_renderTemplate((string)$row['tpl_subject'], $mockLead);
                    $bodyTxt = crm_renderTemplate((string)$row['tpl_body'], $mockLead);
                    // If template body is plain text, wrap with <br> so it renders in HTML clients.
                    $isHtml = (bool) preg_match('/<[a-z][^>]*>/i', $bodyTxt);
                    $bodyHtml = $isHtml ? $bodyTxt : nl2br(htmlspecialchars($bodyTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

                    $payload = [
                        'from'     => $fromRaw,
                        'to'       => [$to],
                        'subject'  => $subject,
                        'html'     => '<!doctype html><html><body style="font-family:-apple-system,Segoe UI,sans-serif;color:#0e0d12;line-height:1.55">' . $bodyHtml . '</body></html>',
                        'text'     => $bodyTxt,
                        'reply_to' => $reply,
                    ];
                    $ch = curl_init('https://api.resend.com/emails');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($payload),
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                        CURLOPT_TIMEOUT        => 8,
                    ]);
                    $resp = curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($resp !== false && $code < 400) {
                        $ok++;
                    } else {
                        $fail++;
                        $errs[] = "step #{$sid} ({$row['tpl_name']}): HTTP {$code} — " . substr((string)$resp, 0, 120);
                    }
                    // Light throttle for send_all — Resend's free tier is 2/sec.
                    if (count($stepIdsToSend) > 1) usleep(600000);
                }
                if ($fail === 0) {
                    $flash = ['type' => 'ok', 'msg' => "Sent {$ok} email" . ($ok === 1 ? '' : 's') . " to {$to}. Check the inbox (and spam) in ~30s."];
                } else {
                    $flash = ['type' => 'err', 'msg' => "Sent OK: {$ok} · Failed: {$fail}. " . implode(' | ', array_slice($errs, 0, 3))];
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// GET: render preview page.
// ─────────────────────────────────────────────────────────────
$sequences = crm_db()->query(
    "SELECT * FROM sequences WHERE active = TRUE ORDER BY id ASC"
)->fetchAll();

$stepsBySeq = [];
$stepsStmt = crm_db()->prepare(
    "SELECT s.*, t.id AS tpl_id, t.name AS tpl_name, t.subject AS tpl_subject, t.body AS tpl_body
       FROM sequence_steps s
       LEFT JOIN email_templates t ON t.id = JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.template_id'))
      WHERE s.sequence_id = ? AND s.action = 'send_template'
      ORDER BY s.step_order ASC"
);
foreach ($sequences as $seq) {
    $stepsStmt->execute([(int)$seq['id']]);
    $stepsBySeq[$seq['id']] = $stepsStmt->fetchAll();
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Nurture preview · Adverton CRM</title>
<style>
body { font-family: -apple-system, Segoe UI, sans-serif; color: #1a1a1a; background: #f5f5f7; max-width: 880px; margin: 0 auto; padding: 24px; line-height: 1.55; }
h1 { font-size: 22px; margin: 0 0 4px; }
.sub { color: #666; font-size: 14px; margin-bottom: 24px; }
.send-box { background: #fff; border: 1px solid #d8d8db; border-radius: 10px; padding: 16px; margin-bottom: 24px; }
.send-box label { display: block; font-size: 12px; color: #555; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.send-box input[type=email] { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 15px; box-sizing: border-box; }
.send-box .row { display: flex; gap: 10px; margin-top: 12px; align-items: center; flex-wrap: wrap; }
button { padding: 9px 14px; border: 0; border-radius: 6px; background: #6c2fff; color: #fff; font-size: 14px; font-weight: 500; cursor: pointer; }
button.ghost { background: #fff; border: 1px solid #d8d8db; color: #333; }
button:hover { opacity: 0.9; }
.flash { padding: 12px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.flash.ok { background: #e6f7ec; color: #1e6638; border: 1px solid #bbe2c8; }
.flash.err { background: #fcecec; color: #8a1f1f; border: 1px solid #f0bcbc; }
.seq { background: #fff; border: 1px solid #e2e2e6; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
.seq h2 { margin: 0 0 4px; font-size: 18px; }
.seq .tag { display: inline-block; background: #eef; color: #444; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-right: 6px; }
.step { border-top: 1px dashed #ddd; padding-top: 16px; margin-top: 16px; }
.step:first-of-type { border-top: 0; padding-top: 8px; margin-top: 8px; }
.step-meta { color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.subj { font-size: 16px; font-weight: 600; color: #111; margin-bottom: 8px; }
.body-pre { background: #fafafb; border: 1px solid #ececef; border-radius: 6px; padding: 12px 14px; white-space: pre-wrap; font-family: -apple-system, Segoe UI, sans-serif; font-size: 14px; color: #222; }
.send-one { margin-top: 10px; }
.send-one button { padding: 6px 10px; font-size: 13px; background: #fff; border: 1px solid #d8d8db; color: #333; }
.send-one button:hover { background: #f0f0f3; }
small.mono { font-family: ui-monospace, Menlo, monospace; color: #777; }
</style>
</head>
<body>
<h1>Nurture preview · Post-Audit + Post-Ebook</h1>
<div class="sub">Mock data: <small class="mono"><?= $h(json_encode($mockLead, JSON_UNESCAPED_SLASHES)) ?></small></div>

<?php if ($flash): ?>
<div class="flash <?= $h($flash['type']) ?>"><?= $h($flash['msg']) ?></div>
<?php endif; ?>

<div class="send-box">
  <form method="post">
    <input type="hidden" name="go" value="<?= $h($got) ?>">
    <label for="to">Send test emails to</label>
    <input type="email" id="to" name="to" placeholder="leo@adverton.net" value="<?= $h($_POST['to'] ?? '') ?>" required>
    <div class="row">
      <button type="submit" name="action" value="send_all">Send all 10 to this inbox</button>
      <span style="font-size:13px; color:#666;">Subjects get prefixed with <code>[TEST]</code>. Roughly 6s total (throttled to dodge Resend rate-limit).</span>
    </div>
  </form>
</div>

<?php foreach ($sequences as $seq): ?>
<div class="seq">
  <h2><?= $h($seq['name']) ?></h2>
  <div style="font-size:13px; color:#666; margin-bottom:8px;">
    <span class="tag">trigger=<?= $h($seq['trigger_event']) ?></span>
    <?php if ($seq['trigger_value']): ?><span class="tag">value=<?= $h($seq['trigger_value']) ?></span><?php endif; ?>
  </div>
  <?php foreach ($stepsBySeq[$seq['id']] ?? [] as $step):
    $renderedSubj = crm_renderTemplate((string)$step['tpl_subject'], $mockLead);
    $renderedBody = crm_renderTemplate((string)$step['tpl_body'], $mockLead);
  ?>
  <div class="step">
    <div class="step-meta">
      Step <?= (int)$step['step_order'] ?> · delay <?= (int)$step['delay_days'] ?> day<?= $step['delay_days']==1?'':'s' ?> · template <?= $h($step['tpl_name'] ?: '?') ?>
      <?php if (!$step['tpl_id']): ?><span style="color:#c00;"> · ⚠ template missing</span><?php endif; ?>
    </div>
    <div class="subj"><?= $h($renderedSubj) ?: '<em>(no subject)</em>' ?></div>
    <div class="body-pre"><?= $h($renderedBody) ?></div>
    <form method="post" class="send-one">
      <input type="hidden" name="go" value="<?= $h($got) ?>">
      <input type="hidden" name="step_id" value="<?= (int)$step['id'] ?>">
      <input type="hidden" name="to" value="" data-mirror-from="#to">
      <button type="submit" name="action" value="send_one">Send only this one →</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<script>
// Mirror the top "to" field into every per-step form right before submit.
document.querySelectorAll('form.send-one').forEach(f => {
  f.addEventListener('submit', () => {
    const top = document.getElementById('to');
    f.querySelector('input[name=to]').value = top.value;
  });
});
</script>
</body>
</html>
