<?php
// One-shot: rewrite the user's crontab to run cron jobs via PHP CLI directly
// instead of curl https://. Imunify360 blocks the HTTPS endpoints with 403,
// even from server-local cron, so curl-based crons silently log error HTML
// without actually executing the script logic.
//
// Run via:  curl -sS https://adverton.net/crm/_fix-cron.php?go=1
// Self-deletes when done.

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

// Soft gate: any non-empty `go` param. Endpoint isn't dangerous (writes user-
// owned crontab, no secrets exposed) but we don't want it to run on a probe.
if (empty($_GET['go'])) {
    echo "Usage: append ?go=1 to run.\n";
    exit;
}

if (!function_exists('shell_exec')) {
    echo "FATAL: shell_exec disabled.\n";
    exit;
}

// All five jobs, switched from curl-based to PHP CLI invocation. The cron
// scripts themselves already detect CLI mode via php_sapi_name() and skip
// the token check.
$jobs = [
    [
        'name' => 'cron-sequences',
        'line' => '*/15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-sequences.php >> /home2/advertonnet/logs/cron-sequences.log 2>&1',
    ],
    [
        'name' => 'cron-calendly',
        'line' => '17 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-calendly.php >> /home2/advertonnet/logs/cron-calendly.log 2>&1',
    ],
    [
        'name' => 'cron-client-triggers',
        'line' => '5 7 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-client-triggers.php >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1',
    ],
    [
        'name' => 'cron-lost-reengagement',
        'line' => '20 8 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-lost-reengagement.php >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1',
    ],
    [
        'name' => 'cron-health-score',
        'line' => '0 6 * * 1 /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-health-score.php >> /home2/advertonnet/logs/cron-health-score.log 2>&1',
    ],
];

// Read existing crontab, drop any line referencing one of our cron-*.php scripts,
// then append the CLI versions.
$current = (string) (@shell_exec('crontab -l 2>/dev/null') ?? '');
echo "=== Existing crontab ===\n";
echo $current === '' ? "  (empty)\n" : $current;

$existing = explode("\n", rtrim($current, "\n"));
$kept = [];
foreach ($existing as $line) {
    $isOurs = false;
    foreach ($jobs as $j) {
        if (strpos($line, $j['name'] . '.php') !== false) {
            $isOurs = true;
            break;
        }
    }
    if (!$isOurs) $kept[] = $line;
}

echo "\n=== Replacing with PHP CLI versions ===\n";
foreach ($jobs as $j) {
    $kept[] = $j['line'];
    echo "  ✓ {$j['name']}\n";
}

// Truncate any leftover log files that contain only HTML 403 error garbage,
// so the next cron run starts with a clean slate.
echo "\n=== Truncating stale logs ===\n";
foreach ($jobs as $j) {
    $log = "/home2/advertonnet/logs/{$j['name']}.log";
    if (file_exists($log)) {
        $head = (string) @file_get_contents($log, false, null, 0, 200);
        if (strpos($head, '403 Forbidden') !== false || strpos($head, '<!DOCTYPE html>') !== false) {
            @file_put_contents($log, '');
            echo "  cleared {$log}\n";
        } else {
            echo "  kept    {$log} (not garbage)\n";
        }
    }
}

$newCrontab = implode("\n", array_filter($kept, fn($l) => trim($l) !== '')) . "\n";
$tmp = tempnam(sys_get_temp_dir(), 'cron');
file_put_contents($tmp, $newCrontab);
$result = shell_exec("crontab " . escapeshellarg($tmp) . " 2>&1");
unlink($tmp);

if ($result !== null && trim($result) !== '') {
    echo "\nERROR installing crontab:\n  {$result}\n";
    exit;
}

echo "\n=== After install ===\n";
echo (string) (@shell_exec('crontab -l 2>/dev/null') ?? '(read failed)');

// Self-destruct: this endpoint should not stay reachable.
if (@unlink(__FILE__)) {
    echo "\n✓ Endpoint self-deleted.\n";
}
echo "\nDONE. Next cron-sequences run is within 15 min.\n";
