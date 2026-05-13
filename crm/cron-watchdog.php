<?php
// cron-watchdog.php — runs every 15 min, pings Discord/Slack webhook if
// any other managed cron is stale (log mtime beyond expected interval × buffer).
//
// Schedule: */15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-watchdog.php
//
// Uses NEW_LEAD_WEBHOOK_URL (Discord/Slack-compatible JSON {content: msg}).
// State file at logs/cron-watchdog.state dedups: don't re-alert within 6h
// per cron so the channel doesn't spam during prolonged outages.

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

// Cron name → max age in seconds before considered stale.
// (Expected interval × ~2-3 buffer to avoid flapping on slow runs.)
$crons = [
    'cron-sequences'         => 30 * 60,           // every 15min · alert >30min
    'cron-calendly'          => 3 * 3600,          // hourly :17 · alert >3h
    'cron-client-triggers'   => 30 * 3600,         // daily 07:05 · alert >30h
    'cron-lost-reengagement' => 30 * 3600,         // daily 08:20 · alert >30h
    'cron-health-score'      => 9 * 24 * 3600,     // weekly Mon 06:00 · alert >9d
    'cron-instantly-health'  => 3 * 3600,          // hourly :00 · alert >3h
];

$logsDir   = '/home2/advertonnet/logs';
$stateFile = $logsDir . '/cron-watchdog.state';
$state     = file_exists($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];

$webhook = (string) crm_config('NEW_LEAD_WEBHOOK_URL');
if ($webhook === '') {
    echo "watchdog: NEW_LEAD_WEBHOOK_URL not set — skipping\n";
    exit;
}

$alerts = [];
$now    = time();
foreach ($crons as $name => $maxAge) {
    $log = "$logsDir/$name.log";
    if (!file_exists($log)) {
        $alerts[] = ['name' => $name, 'reason' => 'log file missing'];
        continue;
    }
    $age = $now - filemtime($log);
    if ($age <= $maxAge) continue;
    $lastAlert = (int)($state[$name] ?? 0);
    if ($now - $lastAlert < 6 * 3600) continue;  // dedup
    $alerts[] = ['name' => $name, 'reason' => sprintf('%.1fh stale (threshold %.1fh)', $age/3600, $maxAge/3600)];
    $state[$name] = $now;
}

if ($alerts) {
    $lines = ["⚠️ **Adverton cron watchdog** — stale cron detected:"];
    foreach ($alerts as $a) {
        $lines[] = sprintf("• `%s` — %s", $a['name'], $a['reason']);
    }
    $lines[] = "Check `https://adverton.net/crm/_health.php`";
    $payload = json_encode(['content' => implode("\n", $lines)]);

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents($stateFile, json_encode($state));
    echo "watchdog: " . count($alerts) . " alert(s) sent (webhook HTTP $code)\n";
} else {
    echo "watchdog: all crons healthy\n";
}
