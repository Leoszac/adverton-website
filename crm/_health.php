<?php
// Public health check — no auth, no params. Reports cron health by inspecting
// log mtimes. Safe to expose: only returns aggregate metadata, no lead data.

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$logsDir = '/home2/advertonnet/logs';
$crons = [
    'cron-sequences'         => 'Every 15 min',
    'cron-calendly'          => 'Hourly :17',
    'cron-client-triggers'   => 'Daily 07:05 ET',
    'cron-lost-reengagement' => 'Daily 08:20 ET',
    'cron-health-score'      => 'Weekly Mon 06:00 ET',
];

echo "=== Adverton cron health ===\n";
echo "Now: " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

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

echo "\n=== Recent cron-sequences activity (tail) ===\n";
$seqLog = "{$logsDir}/cron-sequences.log";
if (file_exists($seqLog)) {
    $lines = @file($seqLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -10) as $l) {
        echo "  " . substr($l, 0, 200) . "\n";
    }
} else {
    echo "  (no log yet — first run pending or blocked)\n";
}
