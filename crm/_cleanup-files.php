<?php
// One-shot cleanup endpoint. Removes leftover one-shot files that the cPanel
// deploy hook didn't clear (likely an ownership/permissions edge case where
// `rm` runs as the deploy user but the files were created by php-fpm).
//
// Auth: requires CRM login as founder.
// Behavior: hardcoded whitelist of targets, self-destructs on success.
//
// Delete this file after running successfully. The .cpanel.yml will also
// remove it on the next deploy (defense-in-depth).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$user = crm_requireRole(['founder']);

header('Content-Type: text/plain; charset=utf-8');

$targets = [
    __DIR__ . '/patch-nurture-tail-task.php',
    __DIR__ . '/seed-nurture-sequences.php',
];

foreach ($targets as $f) {
    $name = basename($f);
    if (!file_exists($f)) {
        echo "[skip] {$name} — already gone\n";
        continue;
    }
    $perm = substr(sprintf('%o', fileperms($f)), -4);
    if (@unlink($f)) {
        echo "[ok]   {$name} removed (was perms {$perm})\n";
    } else {
        $err = error_get_last();
        echo "[fail] {$name} — perms {$perm}, error: " . ($err['message'] ?? 'unknown') . "\n";
    }
}

if (@unlink(__FILE__)) {
    echo "[ok]   _cleanup-files.php self-destructed\n";
} else {
    echo "[fail] _cleanup-files.php could not remove itself — delete manually via cPanel\n";
}

echo "\nDone.\n";
