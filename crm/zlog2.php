<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
crm_requireRole(['founder', 'sales']);
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/error_log';
$lines = is_file($f) ? (@file($f) ?: []) : [];
$adv = array_values(array_filter($lines, fn($l) => strpos($l, '[advdeploy]') !== false));
echo "=== [advdeploy] markers (last 20) ===\n";
foreach (array_slice($adv, -20) as $l) echo rtrim($l, "\n") . "\n";
echo "\n=== full tail (last 15) ===\n";
foreach (array_slice($lines, -15) as $l) echo rtrim($l, "\n") . "\n";
