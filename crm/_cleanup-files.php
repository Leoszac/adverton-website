<?php
// One-shot cleanup endpoint. Removes leftover one-shot files that the cPanel
// deploy hook didn't clear (likely a deploy-user vs php-fpm-user ownership
// mismatch where `rm` from the deploy hook can't unlink files created by
// php-fpm).
//
// Auth: NONE — but the impact is bounded. The list of targets is a hardcoded
// whitelist of files that must go away anyway, and the script self-destructs
// on first run. After self-destruct the endpoint returns 404 and re-deploys
// of crm/ won't bring it back (also rm'd by .cpanel.yml as defense-in-depth).
//
// Worst case if a stranger triggers it first: they execute the cleanup we
// wanted executed anyway, then it's gone. No data loss, no privilege.

declare(strict_types=1);

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
