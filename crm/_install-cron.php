<?php
// One-shot crontab repair v2 — adds CRON_TZ=America/New_York at the top
// so scheduled times are interpreted in ET (auto-DST). All cron times are
// the actual ET wall-clock values matching the labels in _health.php.
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

// ── Canonical cron lines, schedules expressed in ET wall-clock ──────────
// Vixie cron + Cronie both honor `CRON_TZ` at the top of the crontab,
// applying it to every following entry. Anything written *before* a
// CRON_TZ line still runs in server TZ.
$canonical = [
    '*/15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-sequences.php >> /home2/advertonnet/logs/cron-sequences.log 2>&1',
    '17 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-calendly.php >> /home2/advertonnet/logs/cron-calendly.log 2>&1',
    '5 7 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-client-triggers.php >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1',
    '20 8 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-lost-reengagement.php >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1',
    '0 6 * * 1 /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-health-score.php >> /home2/advertonnet/logs/cron-health-score.log 2>&1',
    '0 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-instantly-health.php >> /home2/advertonnet/logs/cron-instantly-health.log 2>&1',
    '*/15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-watchdog.php >> /home2/advertonnet/logs/cron-watchdog.log 2>&1',
];

$managedScripts = [
    'cron-sequences.php',
    'cron-calendly.php',
    'cron-client-triggers.php',
    'cron-lost-reengagement.php',
    'cron-health-score.php',
    'cron-instantly-health.php',
    'cron-watchdog.php',
];

// ── Read current crontab ────────────────────────────────────────────────
$current = (string) shell_exec('crontab -l 2>/dev/null');
$lines = preg_split('/\R/', trim($current), -1, PREG_SPLIT_NO_EMPTY) ?: [];

echo "=== BEFORE ===\n";
echo $current === '' ? "(empty crontab)\n" : $current . "\n";
echo "\n";

// ── Partition: drop managed lines + any prior CRON_TZ/TZ env line ───────
$preserved = [];
$dropped   = [];
foreach ($lines as $line) {
    $trim = ltrim($line);
    // Drop any prior timezone declarations — we own the only one
    if (preg_match('/^(CRON_TZ|TZ)\s*=/i', $trim)) {
        $dropped[] = $line;
        continue;
    }
    $isManaged = false;
    foreach ($managedScripts as $s) {
        if (strpos($line, $s) !== false) { $isManaged = true; break; }
    }
    if ($isManaged) $dropped[] = $line;
    else            $preserved[] = $line;
}

$logDir = '/home2/advertonnet/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
    echo "[log dir] created {$logDir}\n";
}

// ── Build new crontab: CRON_TZ first, then preserved, then canonical ────
$header = ['CRON_TZ=America/New_York'];
$newLines = array_merge($header, $preserved, $canonical);
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
echo "Header set:            CRON_TZ=America/New_York\n";
echo "Added (canonical):     " . count($canonical) . " line(s) — times are ET wall-clock\n";
foreach ($canonical as $c) echo "  + " . $c . "\n";
echo "\n";

// Sanity: did all managed scripts make it in + the CRON_TZ line?
$missing = [];
foreach ($managedScripts as $s) {
    if (strpos($verify, $s) === false) $missing[] = $s;
}
$tzOk = (bool) preg_match('/^CRON_TZ\s*=\s*America\/New_York/m', $verify);
if ($missing || !$tzOk) {
    echo "[fail] Missing scripts: " . implode(', ', $missing) . "\n";
    if (!$tzOk) echo "[fail] CRON_TZ header not present in final crontab\n";
    echo "[warn] keeping _install-cron.php for retry\n";
} else {
    echo "[ok] All " . count($managedScripts) . " managed crons present\n";
    echo "[ok] CRON_TZ=America/New_York header in place\n";
    @unlink(__FILE__);
    echo "[ok] _install-cron.php self-destructed\n";
}

echo "\n";
echo "NOTE: If the cron daemon does NOT honor CRON_TZ on this host, the\n";
echo "managed crons will fire at the ET wall-clock TIMES interpreted as\n";
echo "UTC — i.e. 4h early during EDT. Watch /crm/_health.php for the next\n";
echo "*/15 cron-sequences fire to confirm the daemon honored CRON_TZ.\n";
