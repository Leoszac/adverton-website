<?php
// One-shot diagnostic for the stuck instantly-health cron.
// Token-gated. Manual delete after use.
//   GET /crm/_diag-instantly.php?go=SEED_TOKEN

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['go'] ?? ''))) {
    http_response_code(403);
    exit("forbidden\n");
}

if (!function_exists('shell_exec')) exit("shell_exec disabled\n");

$logFile = '/home/advertonnet/logs/cron-instantly-health.log';
$cronScript = '/home/advertonnet/public_html/crm/cron-instantly-health.php';
$phpBin = '/usr/local/bin/php';

echo "=== Crontab line for cron-instantly-health ===\n";
echo (string) shell_exec("crontab -l 2>&1 | grep -i instantly || echo '(no line found)'");
echo "\n";

echo "=== Log file ===\n";
echo (string) shell_exec("ls -la $logFile 2>&1");
echo "\n";

echo "=== Last 30 log lines ===\n";
echo (string) shell_exec("tail -30 $logFile 2>&1");
echo "\n";

echo "=== INSTANTLY_API_KEY configured? ===\n";
$key = crm_config('INSTANTLY_API_KEY');
echo $key ? "Yes (length=" . strlen((string)$key) . ", prefix=" . substr((string)$key, 0, 6) . "…)\n" : "❌ NOT SET\n";
echo "\n";

echo "=== Last snapshot in DB ===\n";
try {
    $stmt = crm_db()->prepare("SELECT updated_at, JSON_LENGTH(JSON_EXTRACT(value, '$.items')) AS item_count FROM settings WHERE \"name\" = 'INSTANTLY_HEALTH_SNAPSHOT'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        echo "updated_at: " . $row['updated_at'] . "\n";
        echo "items:      " . $row['item_count'] . "\n";
    } else {
        // Try another schema variant
        $stmt = crm_db()->prepare("SELECT * FROM settings WHERE \"name\" LIKE '%INSTANTLY%'");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        echo "Found " . count($rows) . " INSTANTLY settings rows\n";
        foreach ($rows as $r) {
            echo "  name=" . ($r['name'] ?? '?') . " updated_at=" . ($r['updated_at'] ?? '?') . "\n";
        }
    }
} catch (Throwable $e) {
    echo "DB error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== Run cron-instantly-health.php WITH display_errors=1 ===\n";
$cmd = "$phpBin -d display_errors=1 -d error_reporting=E_ALL $cronScript 2>&1; echo \"---EXIT---\$?\"";
echo "Command: $cmd\n";
$start = microtime(true);
$out = (string) shell_exec($cmd);
$dur = round((microtime(true) - $start) * 1000);
echo "Duration: {$dur}ms\n";
echo "Output:\n";
echo $out;
echo "\n";

echo "=== Try requiring lib/instantly.php in isolation ===\n";
$probeCmd = "$phpBin -d display_errors=1 -d error_reporting=E_ALL -r \"define('CRM_ENTRY',1); require '/home/advertonnet/public_html/crm/lib/db.php'; require '/home/advertonnet/public_html/crm/lib/instantly.php'; echo 'loaded OK\\n';\" 2>&1; echo \"---EXIT---\$?\"";
echo (string) shell_exec($probeCmd);
echo "\n";

echo "=== Tail of PHP error_log (if any) ===\n";
echo (string) shell_exec("find /home/advertonnet -maxdepth 3 -name 'error_log' -mmin -1440 2>/dev/null | xargs -I {} sh -c 'echo \"== {} ==\"; tail -20 {}' 2>&1 | head -60");
echo "\n";

echo "=== Log mtime after manual run ===\n";
echo (string) shell_exec("ls -la $logFile 2>&1");

echo "\n[done] Manual cleanup with next deploy or rm via cPanel.\n";
