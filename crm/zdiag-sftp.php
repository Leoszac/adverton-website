<?php
// One-shot READ-ONLY feasibility probe for SFTP deploy. Founder/sales only. Delete after.
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
crm_requireRole(['founder', 'sales']);
header('Content-Type: text/plain; charset=utf-8');

$v = curl_version();
$protos = $v['protocols'] ?? [];
echo "curl " . $v['version'] . "\n";
echo "curl sftp support : " . (in_array('sftp', $protos, true) ? 'YES' : 'NO') . "\n";
echo "curl scp support  : " . (in_array('scp', $protos, true) ? 'YES' : 'NO') . "\n";
echo "php ssh2 ext      : " . (extension_loaded('ssh2') ? 'YES' : 'NO') . "\n";
echo "phpseclib present : " . ((class_exists('phpseclib3\\Net\\SFTP') || class_exists('phpseclib\\Net\\SFTP')) ? 'YES' : 'NO') . "\n";
echo str_repeat('-', 60) . "\n";

foreach ([22, 21] as $port) {
    $t = microtime(true);
    $fp = @fsockopen('airjohnsontoledo.com', $port, $errno, $errstr, 8);
    $ms = round((microtime(true) - $t) * 1000);
    if ($fp) {
        echo "TCP airjohnsontoledo.com:{$port} => OPEN in {$ms}ms";
        if ($port === 22) { $b = @fgets($fp, 128); echo "  banner: " . trim((string)$b); }
        echo "\n";
        fclose($fp);
    } else {
        echo "TCP airjohnsontoledo.com:{$port} => FAILED ({$errno} {$errstr}) in {$ms}ms\n";
    }
}
echo "[done]\n";
