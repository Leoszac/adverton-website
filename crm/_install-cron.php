<?php
// One-shot crontab repair — replaces the 5 managed cron lines (sequences,
// calendly, client-triggers, lost-reengagement, health-score) with the
// canonical PHP CLI invocations. Preserves any unrelated lines.
//
// Token-gated. Self-destructs after a clean apply.
// Call: GET /crm/_install-cron.php?go=SEED_TOKEN

declare(strict_types=1);
define('CRM_ENTRY', 1);

require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

$want = (string) crm_config('SEED_TOKEN');
$got  = (string)($_GET['go'] ?? '');
if ($want === '' || !hash_equals($want, $got)) {
    http_response_code(403);
    exit("forbidden — pass ?go=<SEED_TOKEN>\n");
}

if (!function_exists('shell_exec')) {
    http_response_code(500);
    exit("shell_exec is disabled — cannot manage crontab from PHP\n");
}

// ── Canonical cron lines (PHP CLI direct, never curl HTTPS) ─────────────
// Times: client-triggers and lost-reengagement run at ET-equivalent UTC.
//   07:05 ET → 11:05 UTC (EDT) ; 08:20 ET → 12:20 UTC ; Mon 06:00 ET → Mon 10:00 UTC
$canonical = [
    '*/15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-sequences.php >> /home2/advertonnet/logs/cron-sequences.log 2>&1',
    '17 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-calendly.php >> /home2/advertonnet/logs/cron-calendly.log 2>&1',
    '5 11 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-client-triggers.php >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1',
    '20 12 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-lost-reengagement.php >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1',
    '0 10 * * 1 /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-health-score.php >> /home2/advertonnet/logs/cron-health-score.log 2>&1',
];

$managedScripts = [
    'cron-sequences.php',
    'cron-calendly.php',
    'cron-client-triggers.php',
    'cron-lost-reengagement.php',
    'cron-health-score.php',
];

// ── Read current crontab ────────────────────────────────────────────────
$current = (string) shell_exec('crontab -l 2>/dev/null');
$lines = preg_split('/\R/', trim($current), -1, PREG_SPLIT_NO_EMPTY) ?: [];

echo "=== BEFORE ===\n";
echo $current === '' ? "(empty crontab)\n" : $current . "\n";
echo "\n";

// ── Partition: preserve unmanaged, drop managed ─────────────────────────
$preserved = [];
$dropped   = [];
foreach ($lines as $line) {
    $isManaged = false;
    foreach ($managedScripts as $s) {
        if (strpos($line, $s) !== false) { $isManaged = true; break; }
    }
    if ($isManaged) $dropped[] = $line;
    else            $preserved[] = $line;
}

// ── Ensure log dir exists ───────────────────────────────────────────────
$logDir = '/home2/advertonnet/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
    echo "[log dir] created {$logDir}\n";
}

// ── Build new crontab ───────────────────────────────────────────────────
$newLines = array_merge($preserved, $canonical);
$newCron  = implode("\n", $newLines) . "\n";

// ── Apply via `crontab <file>` ──────────────────────────────────────────
$tmp = tempnam(sys_get_temp_dir(), 'cron');
if ($tmp === false) {
    http_response_code(500);
    exit("tempnam() failed\n");
}
file_put_contents($tmp, $newCron);
$applyOut = (string) shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);

if (trim($applyOut) !== '') {
    echo "[crontab apply] {$applyOut}\n";
}

// ── Verify ──────────────────────────────────────────────────────────────
$verify = (string) shell_exec('crontab -l 2>/dev/null');
echo "=== AFTER ===\n";
echo $verify;
echo "\n";

// ── Summary ─────────────────────────────────────────────────────────────
echo "=== SUMMARY ===\n";
echo "Preserved (unmanaged): " . count($preserved) . " line(s)\n";
echo "Dropped (replaced):    " . count($dropped) . " line(s)\n";
foreach ($dropped as $d) echo "  - " . $d . "\n";
echo "Added (canonical):     " . count($canonical) . " line(s)\n";
foreach ($canonical as $c) echo "  + " . $c . "\n";
echo "\n";

// Sanity: did all 5 managed scripts make it in?
$missing = [];
foreach ($managedScripts as $s) {
    if (strpos($verify, $s) === false) $missing[] = $s;
}
if ($missing) {
    echo "[fail] Some scripts not in crontab after apply: " . implode(', ', $missing) . "\n";
    echo "[warn] keeping _install-cron.php for retry\n";
} else {
    echo "[ok] All 5 managed crons present\n";
    @unlink(__FILE__);
    echo "[ok] _install-cron.php self-destructed\n";
}
