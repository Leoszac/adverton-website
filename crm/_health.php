<?php
// Public health check — no auth, no params. Reports cron health by inspecting
// log mtimes. Safe to expose: only returns aggregate metadata, no lead data.

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$logsDir = '/home/advertonnet/logs';
$crons = [
    'cron-sequences'         => 'Every 15 min',
    'cron-calendly'          => 'Hourly :17',
    'cron-client-triggers'   => 'Daily 07:05 ET',
    'cron-lost-reengagement' => 'Daily 08:20 ET',
    'cron-health-score'      => 'Weekly Mon 06:00 ET',
    'cron-instantly-health'  => 'Hourly :00',
    'cron-watchdog'          => 'Every 15 min',
    'cron-dnc-rescrub'       => 'Daily 03:00 ET',
];

echo "=== Adverton cron health ===\n";
$tz = new DateTimeZone('America/New_York');
echo "Now: " . (new DateTime('now', $tz))->format('Y-m-d H:i:s T') . "\n\n";

foreach ($crons as $name => $schedule) {
    $log = "{$logsDir}/{$name}.log";
    printf("%-26s  %-22s  ", $name, $schedule);
    if (!file_exists($log)) {
        echo "❌  log file missing — never ran or output wasn't captured\n";
        continue;
    }
    $mtime = filemtime($log);
    $size  = filesize($log);
    $age   = time() - $mtime;
    $ageStr = $age < 60     ? "{$age}s ago"
            : ($age < 3600  ? floor($age/60) . "m ago"
            : ($age < 86400 ? floor($age/3600) . "h ago"
            :                 floor($age/86400) . "d ago"));
    printf("%-15s  %s bytes\n", $ageStr, number_format($size));
}

// Show last 5 log lines for each cron, with a hint if the log looks like
// HTML-error garbage from a curl-based cron hitting a WAF block.
foreach ($crons as $name => $schedule) {
    $log = "{$logsDir}/{$name}.log";
    echo "\n=== {$name} (tail) ===\n";
    if (!file_exists($log)) {
        echo "  (no log yet)\n";
        continue;
    }
    $head = (string) @file_get_contents($log, false, null, 0, 200);
    if (strpos($head, '403 Forbidden') !== false || strpos($head, '<!DOCTYPE html>') !== false) {
        echo "  ⚠️  log contains HTML — cron is being blocked at the web layer (Imunify/mod_security).\n";
        echo "     Switch this cron to PHP CLI (run /crm/_fix-cron.php?go=1 once).\n";
        continue;
    }
    $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -5) as $l) {
        echo "  " . substr($l, 0, 200) . "\n";
    }
}
