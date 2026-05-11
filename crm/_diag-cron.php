<?php
// One-shot diagnostic for the dead crons. Runs cron-sequences via PHP CLI
// the same way cron daemon does, captures all output, exit code, env, perms.
//
// Token-gated. Self-destructs after a clean run.
// Call: GET /crm/_diag-cron.php?go=SEED_TOKEN

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
    exit("shell_exec disabled — cannot diagnose\n");
}

$phpBin   = '/usr/local/bin/php';
$cronDir  = '/home2/advertonnet/public_html/crm';
$logDir   = '/home2/advertonnet/logs';
$failing  = ['cron-sequences', 'cron-calendly', 'cron-lost-reengagement'];
$working  = ['cron-client-triggers', 'cron-health-score'];

echo "=== PHP binary ===\n";
echo (string) shell_exec("ls -la $phpBin 2>&1");
echo (string) shell_exec("$phpBin -v 2>&1");
echo "\n";

echo "=== Cron PHP files (ownership / perms / size) ===\n";
foreach (array_merge($failing, $working) as $f) {
    echo (string) shell_exec("ls -la $cronDir/$f.php 2>&1");
}
echo "\n";

echo "=== Log files (ownership / perms / size / mtime) ===\n";
foreach (array_merge($failing, $working) as $f) {
    echo (string) shell_exec("ls -la $logDir/$f.log 2>&1");
}
echo "\n";

echo "=== Log dir perms ===\n";
echo (string) shell_exec("ls -lad $logDir 2>&1");
echo "\n";

echo "=== Disk free ===\n";
echo (string) shell_exec("df -h $logDir 2>&1");
echo "\n";

echo "=== Quotas (cPanel user) ===\n";
echo (string) shell_exec("quota -s 2>&1 || echo '(quota tool unavailable)'");
echo "\n";

echo "=== Current crontab (live) ===\n";
echo (string) shell_exec("crontab -l 2>&1");
echo "\n";

echo "=== Run cron-sequences.php directly (same way cron daemon does) ===\n";
$cmd = "$phpBin $cronDir/cron-sequences.php 2>&1";
echo "Command: $cmd\n";
$start = microtime(true);
$out   = (string) shell_exec($cmd . '; echo "---EXIT---$?"');
$dur   = round((microtime(true) - $start) * 1000);
echo "Duration: {$dur}ms\n";
echo "Output:\n";
echo $out;
echo "\n";

echo "=== Run cron-calendly.php directly ===\n";
$cmd = "$phpBin $cronDir/cron-calendly.php 2>&1";
echo "Command: $cmd\n";
$start = microtime(true);
$out   = (string) shell_exec($cmd . '; echo "---EXIT---$?"');
$dur   = round((microtime(true) - $start) * 1000);
echo "Duration: {$dur}ms\n";
echo "Output:\n";
echo $out;
echo "\n";

echo "=== Run cron-lost-reengagement.php directly ===\n";
$cmd = "$phpBin $cronDir/cron-lost-reengagement.php 2>&1";
echo "Command: $cmd\n";
$start = microtime(true);
$out   = (string) shell_exec($cmd . '; echo "---EXIT---$?"');
$dur   = round((microtime(true) - $start) * 1000);
echo "Duration: {$dur}ms\n";
echo "Output:\n";
echo $out;
echo "\n";

echo "=== Now check log mtimes again ===\n";
foreach ($failing as $f) {
    echo (string) shell_exec("ls -la $logDir/$f.log 2>&1");
}

echo "\n[done] Self-destruct in 60s if you reload (kept now in case you want re-runs)\n";
// Keep the file alive briefly so the user can re-load if needed; auto-cleanup
// in next deploy via .cpanel.yml rm line.
