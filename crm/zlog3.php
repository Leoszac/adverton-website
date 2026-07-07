<?php
// Temporary token-gated log tail (curl-readable, no session). DELETE after debug.
if (($_GET['k'] ?? '') !== 'adv7x9k2dbg') { http_response_code(403); exit("forbidden\n"); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/error_log';
$lines = is_file($f) ? (@file($f) ?: []) : [];
$adv = array_values(array_filter($lines, fn($l) => strpos($l, '[advdeploy]') !== false));
echo "=== [advdeploy] markers (last 25) ===\n";
foreach (array_slice($adv, -25) as $l) echo rtrim($l, "\n") . "\n";
echo "=== end ===\n";
