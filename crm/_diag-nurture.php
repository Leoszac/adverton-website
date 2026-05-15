<?php
// One-shot diagnostic for the nurture drip (Post-Audit + Post-Ebook).
// Token-gated. Read-only by default; ?force=1 also runs cron-sequences.php inline.
//   GET /crm/_diag-nurture.php?go=SEED_TOKEN
//   GET /crm/_diag-nurture.php?go=SEED_TOKEN&force=1

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['go'] ?? ''))) {
    http_response_code(403);
    exit("forbidden — pass ?go=<SEED_TOKEN>\n");
}

$db = crm_db();
$now = (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s T');
echo "=== Adverton nurture diagnostic ===\n";
echo "Now (ET): {$now}\n\n";

// 1) Config presence
echo "=== 1) CONFIG ===\n";
$resend = (string) crm_config('RESEND_API_KEY');
$from   = (string) crm_config('CRM_FROM_ADDRESS');
$reply  = (string) crm_config('CRM_REPLY_TO');
echo "RESEND_API_KEY:    " . ($resend ? "SET (len=" . strlen($resend) . ", prefix=" . substr($resend, 0, 6) . "…)" : "❌ NOT SET") . "\n";
echo "CRM_FROM_ADDRESS:  " . ($from ?: "(empty — fallback to leandro@adverton.net)") . "\n";
echo "CRM_REPLY_TO:      " . ($reply ?: "(empty)") . "\n\n";

// 2) Sequences + steps
echo "=== 2) SEQUENCES + STEPS ===\n";
$seqs = $db->query("SELECT * FROM sequences ORDER BY id ASC")->fetchAll();
$templateIdsUsed = [];
foreach ($seqs as $s) {
    echo "─ Sequence #{$s['id']}: \"{$s['name']}\" — trigger={$s['trigger_event']} value=" . ($s['trigger_value'] ?: '(any)') . " active=" . ($s['active'] ? 'Y' : 'N') . "\n";
    $stmt = $db->prepare("SELECT * FROM sequence_steps WHERE sequence_id = ? ORDER BY step_order ASC");
    $stmt->execute([$s['id']]);
    foreach ($stmt->fetchAll() as $step) {
        $payload = json_decode((string)$step['payload'], true) ?: [];
        $tplId = isset($payload['template_id']) ? (int)$payload['template_id'] : 0;
        if ($tplId) $templateIdsUsed[$tplId] = true;
        $payloadStr = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "    step_order={$step['step_order']}  delay_days={$step['delay_days']}  action={$step['action']}  payload={$payloadStr}\n";
    }
    echo "\n";
}

// 3) Templates referenced by those steps
echo "=== 3) TEMPLATES (subject + body for every template referenced) ===\n";
if ($templateIdsUsed) {
    $ids = array_keys($templateIdsUsed);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, name, subject, body FROM email_templates WHERE id IN ({$in}) ORDER BY id ASC");
    $stmt->execute($ids);
    $templates = $stmt->fetchAll();
    $foundIds = array_column($templates, 'id');
    foreach ($templates as $t) {
        echo "─ Template #{$t['id']}: \"{$t['name']}\"\n";
        echo "  SUBJECT: {$t['subject']}\n";
        echo "  BODY:\n";
        $body = preg_replace('/^/m', '    ', (string)$t['body']);
        echo $body . "\n\n";
    }
    $missing = array_diff($ids, array_map('intval', $foundIds));
    if ($missing) {
        echo "⚠️  Steps reference template_id(s) that DO NOT EXIST: " . implode(', ', $missing) . "\n";
        echo "    (these steps will silently skip in cron-sequences.php — line 95 \$tpl null check)\n\n";
    }
} else {
    echo "(no send_template steps in any sequence)\n\n";
}

// 4) Active enrollments — the heart of the diagnosis
echo "=== 4) ACTIVE ENROLLMENTS (next_run_at = when next step fires) ===\n";
$rows = $db->query(
    "SELECT e.id AS enr_id, e.sequence_id, s.name AS seq_name, e.lead_id, l.email AS lead_email,
            l.source AS lead_source, l.status AS lead_status, l.temperature AS lead_temp,
            e.current_step, e.next_run_at, e.completed_at, e.unenrolled_reason, e.created_at
       FROM sequence_enrollments e
       JOIN sequences s ON s.id = e.sequence_id
       JOIN leads l ON l.id = e.lead_id
      WHERE e.completed_at IS NULL
      ORDER BY e.next_run_at ASC"
)->fetchAll();
if (!$rows) {
    echo "(no active enrollments)\n";
} else {
    foreach ($rows as $r) {
        $secsToNext = strtotime($r['next_run_at']) - time();
        $when = $secsToNext <= 0
              ? "✅ DUE NOW (overdue " . abs($secsToNext) . "s)"
              : "⏳ in {$secsToNext}s (~" . round($secsToNext / 60, 1) . "m)";
        echo "─ enr #{$r['enr_id']}  seq=\"{$r['seq_name']}\"  lead #{$r['lead_id']} ({$r['lead_email']})\n";
        echo "  source={$r['lead_source']}  status={$r['lead_status']}  temp={$r['lead_temp']}\n";
        echo "  current_step={$r['current_step']}  next_run_at={$r['next_run_at']}  → {$when}\n";
        echo "  enrolled_at={$r['created_at']}\n\n";
    }
}

// 5) Recent email_sends for those leads
echo "=== 5) RECENT EMAIL_SENDS (last 20 across all leads in nurture) ===\n";
$leadIds = array_column($rows, 'lead_id');
if ($leadIds) {
    $in = implode(',', array_fill(0, count($leadIds), '?'));
    $stmt = $db->prepare(
        "SELECT s.id, s.lead_id, s.template_id, t.name AS tpl_name, s.subject,
                s.sent_at, s.open_count, s.first_opened_at, s.click_count, s.first_clicked_at
           FROM email_sends s LEFT JOIN email_templates t ON t.id = s.template_id
          WHERE s.lead_id IN ({$in}) ORDER BY s.sent_at DESC LIMIT 20"
    );
    $stmt->execute($leadIds);
    foreach ($stmt->fetchAll() as $s) {
        $opens  = $s['first_opened_at']  ? "opens={$s['open_count']} first={$s['first_opened_at']}"  : "opens=0";
        $clicks = $s['first_clicked_at'] ? "clicks={$s['click_count']} first={$s['first_clicked_at']}" : "clicks=0";
        echo "  send #{$s['id']}  lead {$s['lead_id']}  tpl=" . ($s['tpl_name'] ?? '(direct)') . "  sent={$s['sent_at']}\n";
        echo "    subj: {$s['subject']}\n";
        echo "    {$opens}  ·  {$clicks}\n";
    }
}
echo "\n";

// 6) Recent activity log for the in-nurture leads
echo "=== 6) RECENT ACTIVITIES (last 15 per in-nurture lead) ===\n";
foreach ($leadIds as $lid) {
    $stmt = $db->prepare(
        "SELECT created_at, type, disposition, body FROM lead_activities WHERE lead_id = ? ORDER BY id DESC LIMIT 15"
    );
    $stmt->execute([$lid]);
    $acts = $stmt->fetchAll();
    echo "─ Lead #{$lid}:\n";
    foreach ($acts as $a) {
        $body = mb_substr((string)($a['body'] ?? ''), 0, 120);
        echo "    {$a['created_at']}  [{$a['type']}/" . ($a['disposition'] ?? '-') . "]  {$body}\n";
    }
    echo "\n";
}

// 7) Cron log tail
echo "=== 7) cron-sequences.log (last 30 lines) ===\n";
$logFile = '/home2/advertonnet/logs/cron-sequences.log';
if (function_exists('shell_exec') && is_readable($logFile)) {
    echo (string) shell_exec("tail -30 " . escapeshellarg($logFile) . " 2>&1");
} else {
    echo "(log not readable or shell_exec disabled)\n";
}
echo "\n";

// 8) Inline cron run (only if ?force=1)
echo "=== 8) INLINE CRON RUN ===\n";
if (($_GET['force'] ?? '') === '1') {
    echo "Running cron-sequences.php inline (force=1)…\n";
    if (!defined('CRM_INPROCESS_CRON')) define('CRM_INPROCESS_CRON', 1);
    ob_start();
    try {
        require __DIR__ . '/cron-sequences.php';
    } catch (Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
    $out = ob_get_clean();
    echo "── output ──\n{$out}\n";
    echo "── re-checking enrollments after run ──\n";
    $rows2 = $db->query(
        "SELECT id, lead_id, current_step, next_run_at, completed_at, unenrolled_reason
           FROM sequence_enrollments
          WHERE id IN (" . (count($rows) ? implode(',', array_map(fn($r) => (int)$r['enr_id'], $rows)) : '0') . ")"
    )->fetchAll();
    foreach ($rows2 as $r2) {
        echo "  enr #{$r2['id']}  lead {$r2['lead_id']}  current_step={$r2['current_step']}  next_run_at={$r2['next_run_at']}  completed_at=" . ($r2['completed_at'] ?? 'NULL') . "  reason=" . ($r2['unenrolled_reason'] ?? '-') . "\n";
    }
} else {
    echo "(skipped — pass &force=1 to actually run the cron now)\n";
}
echo "\n=== END ===\n";
