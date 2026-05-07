<?php
// One-shot: register the nurture cron via the local `uapi` binary.
// Imunify360 blocks the cPanel API from off-server IPs, so we run uapi
// directly on the box.
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

// All the cron jobs the system needs. Keyed by uniqueness — if a similar
// command already exists we skip it (idempotent).
$jobs = [
    [
        'name'    => 'cron-sequences',
        'minute'  => '*/15',
        'hour'    => '*',
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-sequences.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-sequences.log 2>&1",
    ],
    [
        'name'    => 'cron-calendly',
        'minute'  => '17',
        'hour'    => '*',  // every hour at :17
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-calendly.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-calendly.log 2>&1",
    ],
    [
        'name'    => 'cron-client-triggers',
        'minute'  => '5',
        'hour'    => '7',  // once a day at 07:05 ET
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-client-triggers.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1",
    ],
    [
        'name'    => 'cron-lost-reengagement',
        'minute'  => '20',
        'hour'    => '8',  // once a day at 08:20 ET
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-lost-reengagement.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1",
    ],
    [
        'name'    => 'cron-health-score',
        'minute'  => '0',
        'hour'    => '6',
        'weekday' => '1',  // every Monday 06:00 ET
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-health-score.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-health-score.log 2>&1",
    ],
];

// Helper: run uapi command via shell. Returns parsed JSON or null.
function runUapi(string $module, string $func, array $args): ?array {
    $cmd = '/usr/local/bin/uapi --output=json ' . escapeshellarg($module) . ' ' . escapeshellarg($func);
    foreach ($args as $k => $v) {
        $cmd .= ' ' . escapeshellarg("{$k}={$v}");
    }
    $output = @shell_exec($cmd . ' 2>&1');
    if (!$output) return null;
    $j = json_decode((string)$output, true);
    return is_array($j) ? $j : null;
}

if (!function_exists('shell_exec') || !@shell_exec('which uapi')) {
    echo "shell_exec or uapi not available; cannot configure cron from PHP.\n";
    echo "Fall back: configure manually in cPanel → Cron Jobs.\n";
    exit;
}

// 1. List existing jobs to dedupe
echo "=== Existing cron jobs ===\n";
$existing = runUapi('Cron', 'list_jobs', []);
$existingCmds = [];
if ($existing && isset($existing['result']['data']) && is_array($existing['result']['data'])) {
    foreach ($existing['result']['data'] as $j) {
        $existingCmds[] = (string)($j['command'] ?? '');
        $sched = sprintf('%s %s %s %s %s',
            $j['minute']  ?? '*',
            $j['hour']    ?? '*',
            $j['day']     ?? '*',
            $j['month']   ?? '*',
            $j['weekday'] ?? '*');
        echo "  {$sched}  " . substr((string)($j['command'] ?? ''), 0, 100) . "\n";
    }
} else {
    echo "  (none)\n";
}

echo "\n=== Adding cron jobs ===\n";
foreach ($jobs as $job) {
    // Skip if a similar command (matching script name) is already registered
    $alreadyHas = false;
    foreach ($existingCmds as $cmd) {
        if (strpos($cmd, $job['name'] . '.php') !== false) {
            $alreadyHas = true;
            break;
        }
    }
    if ($alreadyHas) {
        echo "  SKIP {$job['name']} — already configured\n";
        continue;
    }

    $args = [
        'command' => $job['command'],
        'minute'  => $job['minute']  ?? '*',
        'hour'    => $job['hour']    ?? '*',
        'day'     => $job['day']     ?? '*',
        'month'   => $job['month']   ?? '*',
        'weekday' => $job['weekday'] ?? '*',
    ];
    $resp = runUapi('Cron', 'add_line', $args);
    $ok = $resp && (int)($resp['result']['status'] ?? 0) === 1;
    if ($ok) {
        echo "  ✓ added {$job['name']}\n";
    } else {
        $err = $resp['result']['errors'][0] ?? json_encode($resp);
        echo "  ✗ failed {$job['name']}: {$err}\n";
    }
}

echo "\nDONE. DELETE THIS FILE NOW.\n";
