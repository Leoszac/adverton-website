<?php
// One-shot READ-ONLY diagnostic: surfaces the PHP error log + config so we can
// see the exact fatal behind the deploy 500. Founder/sales only. Delete after.
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
crm_requireRole(['founder', 'sales']);
header('Content-Type: text/plain; charset=utf-8');

echo "PHP " . PHP_VERSION . " sapi=" . PHP_SAPI . "\n";
echo "max_execution_time = " . ini_get('max_execution_time') . "\n";
echo "memory_limit       = " . ini_get('memory_limit') . "\n";
echo "error_log (ini)    = " . (ini_get('error_log') ?: '(none)') . "\n";
echo "display_errors     = " . ini_get('display_errors') . "\n";
$home = $_SERVER['HOME'] ?? getenv('HOME') ?? '/home/advertonnet';
echo "HOME               = " . $home . "\n";
// does set_time_limit actually work here?
$ok = @set_time_limit(0);
echo "set_time_limit(0)  = " . var_export($ok, true) . "\n";
echo "curl               = " . (function_exists('curl_version') ? curl_version()['version'] : 'MISSING') . "\n";
echo str_repeat('=', 70) . "\n";

$cands = array_unique(array_filter([
    ini_get('error_log'),
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    __DIR__ . '/../error_log',
    $home . '/logs/error_log',
    $home . '/public_html/error_log',
    $home . '/public_html/crm/error_log',
    $home . '/logs/care.log',
    dirname(__DIR__) . '/logs/care.log',
]));
foreach ($cands as $f) {
    echo "\n### " . $f . " ";
    if (!is_file($f)) { echo "(not found)\n"; continue; }
    echo "(" . filesize($f) . " bytes, mtime " . date('Y-m-d H:i:s', (int)filemtime($f)) . ")\n";
    $lines = @file($f);
    if ($lines === false) { echo "(unreadable)\n"; continue; }
    foreach (array_slice($lines, -45) as $l) echo rtrim($l, "\n") . "\n";
}
echo "\n[done]\n";
