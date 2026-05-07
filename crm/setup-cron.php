<?php
// One-shot: register cron jobs by editing the user's crontab directly via shell.
// Skips cPanel API entirely (Imunify360 blocks it from off-server IPs and the
// CPANEL class crashes silently outside its expected request context).
//
// Run via:  curl -sS 'https://adverton.net/crm/setup-cron.php?token=SEED_TOKEN'
// DELETE THIS FILE after running.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = crm_config('SEED_TOKEN');
$got      = $_GET['token'] ?? '';
if (!$expected || !hash_equals((string)$expected, (string)$got)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$seedToken = (string)$expected;

if (!function_exists('shell_exec')) {
    echo "FATAL: shell_exec disabled. Configure cron manually in cPanel.\n";
    exit;
}

// Each job: pattern (substring match for dedupe) + cron line to add
$jobs = [
    [
        'name' => 'cron-sequences',
        'line' => "*/15 * * * * /usr/bin/curl -sS 'https://adverton.net/crm/cron-sequences.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-sequences.log 2>&1",
    ],
    [
        'name' => 'cron-calendly',
        'line' => "17 * * * * /usr/bin/curl -sS 'https://adverton.net/crm/cron-calendly.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-calendly.log 2>&1",
    ],
    [
        'name' => 'cron-client-triggers',
        'line' => "5 7 * * * /usr/bin/curl -sS 'https://adverton.net/crm/cron-client-triggers.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1",
    ],
    [
        'name' => 'cron-lost-reengagement',
        'line' => "20 8 * * * /usr/bin/curl -sS 'https://adverton.net/crm/cron-lost-reengagement.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1",
    ],
    [
        'name' => 'cron-health-score',
        'line' => "0 6 * * 1 /usr/bin/curl -sS 'https://adverton.net/crm/cron-health-score.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-health-score.log 2>&1",
    ],
];

// Read existing crontab. `crontab -l` exits non-zero if the user has no crontab,
// so suppress stderr and accept empty as "no jobs yet".
$current = (string) (@shell_exec('crontab -l 2>/dev/null') ?? '');
echo "=== Existing crontab ===\n";
echo $current === '' ? "  (empty)\n" : $current;

// Build the new crontab: keep existing lines, append missing jobs
$newLines = explode("\n", rtrim($current, "\n"));
$added = 0;
foreach ($jobs as $job) {
    $exists = false;
    foreach ($newLines as $line) {
        if (strpos($line, $job['name'] . '.php') !== false) {
            $exists = true;
            break;
        }
    }
    if ($exists) {
        echo "\n  SKIP {$job['name']} — already in crontab\n";
        continue;
    }
    $newLines[] = $job['line'];
    $added++;
    echo "\n  ✓ queued {$job['name']}\n";
}

if ($added === 0) {
    echo "\nNothing to add — all jobs already configured.\n";
    exit;
}

// Write the new crontab back. Pipe content via stdin.
$newCrontab = implode("\n", array_filter($newLines, fn($l) => trim($l) !== '')) . "\n";

$tmp = tempnam(sys_get_temp_dir(), 'cron');
file_put_contents($tmp, $newCrontab);
$result = shell_exec("crontab " . escapeshellarg($tmp) . " 2>&1");
unlink($tmp);

if ($result !== null && trim($result) !== '') {
    echo "\nERROR installing crontab:\n  {$result}\n";
    echo "Crontab content was:\n{$newCrontab}\n";
    exit;
}

echo "\n=== After install ===\n";
echo (string) (@shell_exec('crontab -l 2>/dev/null') ?? '(read failed)');
echo "\n✓ {$added} new cron job(s) installed.\n";
echo "\nDONE. DELETE THIS FILE NOW.\n";
