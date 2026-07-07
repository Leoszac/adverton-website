<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
crm_requireRole(['founder', 'sales']);
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/error_log';
echo "=== tail of $f ===\n";
if (is_file($f)) {
    $lines = @file($f) ?: [];
    foreach (array_slice($lines, -30) as $l) echo rtrim($l, "\n") . "\n";
} else {
    echo "(not found)\n";
}
@unlink(__FILE__);
