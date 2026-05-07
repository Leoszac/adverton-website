<?php
// One-shot: register cron jobs via cPanel's PHP API (no shell_exec required).
// Imunify360 blocks the cPanel API from off-server IPs; the in-process CPANEL
// class lets us call UAPI without HTTP and without shell.
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
        'hour'    => '*',
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-calendly.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-calendly.log 2>&1",
    ],
    [
        'name'    => 'cron-client-triggers',
        'minute'  => '5',
        'hour'    => '7',
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-client-triggers.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1",
    ],
    [
        'name'    => 'cron-lost-reengagement',
        'minute'  => '20',
        'hour'    => '8',
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-lost-reengagement.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1",
    ],
    [
        'name'    => 'cron-health-score',
        'minute'  => '0',
        'hour'    => '6',
        'weekday' => '1',
        'command' => "/usr/bin/curl -sS 'https://adverton.net/crm/cron-health-score.php?token={$seedToken}' >> /home2/advertonnet/logs/cron-health-score.log 2>&1",
    ],
];

echo "=== Environment ===\n";
echo "  shell_exec: " . (function_exists('shell_exec') ? 'available' : 'disabled') . "\n";

$cpanelPhpPath = '/usr/local/cpanel/php/cpanel.php';
echo "  cpanel.php: " . $cpanelPhpPath . (file_exists($cpanelPhpPath) ? ' (ok)' : ' (MISSING)') . "\n";

if (!file_exists($cpanelPhpPath)) {
    echo "\nFATAL: cpanel.php not loadable. Configure cron manually in cPanel → Cron Jobs.\n";
    echo "First job to add (every 15 min):\n  " . $jobs[0]['command'] . "\n";
    exit;
}

@require_once $cpanelPhpPath;
if (!class_exists('CPANEL')) {
    echo "\nFATAL: CPANEL class not available even after include.\n";
    exit;
}

$cpanel = new CPANEL();

echo "\n=== Existing cron jobs ===\n";
$existing = $cpanel->uapi('Cron', 'list_jobs', []);
$existingData = $existing['cpanelresult']['result']['data'] ?? null;
$existingCmds = [];
if (is_array($existingData)) {
    foreach ($existingData as $j) {
        $existingCmds[] = (string)($j['command'] ?? '');
        $sched = sprintf('%s %s %s %s %s',
            $j['minute']  ?? '*', $j['hour']    ?? '*',
            $j['day']     ?? '*', $j['month']   ?? '*',
            $j['weekday'] ?? '*');
        echo "  {$sched}  " . substr((string)($j['command'] ?? ''), 0, 100) . "\n";
    }
} else {
    echo "  (none) — list_jobs raw: " . substr(json_encode($existing), 0, 250) . "\n";
}

echo "\n=== Adding cron jobs ===\n";
foreach ($jobs as $job) {
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
    $resp = $cpanel->uapi('Cron', 'add_line', $args);
    $status = (int)($resp['cpanelresult']['result']['status'] ?? 0);
    if ($status === 1) {
        echo "  ✓ added {$job['name']}\n";
    } else {
        $errs = $resp['cpanelresult']['result']['errors'] ?? [];
        $err  = is_array($errs) && $errs ? $errs[0] : substr(json_encode($resp), 0, 200);
        echo "  ✗ failed {$job['name']}: " . substr((string)$err, 0, 200) . "\n";
    }
}

echo "\nDONE. DELETE THIS FILE NOW.\n";
