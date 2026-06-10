<?php
// cron-assets-intake.php
//
// Polls the assets@adverton.net Maildir and runs each newly-arrived message
// through email-pipe.php (parse -> AI client-match -> store photos to the
// client's inbox), then files the message into cur/ so it is processed exactly
// once. Intended to run every 5 minutes.
//
// Why a cron instead of the cPanel "pipe forwarder": on this shared host the
// pipe-to-program forwarder did not fire reliably (and its config is not
// inspectable from the account). Mail lands in the mailbox normally with no
// bounce risk, so we do the processing here where we control the whole path.
//
// PHP 7.4-safe (no match/nullsafe/str_contains) since CLI version can drift.

$MAILDIR = '/home/advertonnet/mail/.assets@adverton_net';
$PIPE    = '/home/advertonnet/public_html/crm/email-pipe.php';
$PHP     = '/usr/local/bin/php';
$LOG     = '/home/advertonnet/logs/assets-intake.log';

function intake_log($msg) {
    global $LOG;
    @file_put_contents($LOG, gmdate('Y-m-d\TH:i:s\Z') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

$newDir = $MAILDIR . '/new';
$curDir = $MAILDIR . '/cur';

if (!is_dir($newDir) || !is_dir($curDir)) {
    intake_log('ERROR: maildir missing (' . $newDir . ')');
    exit(1);
}
if (!is_readable($PIPE)) {
    intake_log('ERROR: pipe script missing (' . $PIPE . ')');
    exit(1);
}

// Single-run lock so a slow batch can't overlap the next 5-min tick.
$lock = @fopen($MAILDIR . '/.intake.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$files = glob($newDir . '/*');
$files = is_array($files) ? array_filter($files, 'is_file') : array();
if (!$files) { exit(0); }  // nothing new — stay quiet

$done = 0;
foreach ($files as $f) {
    $base = basename($f);
    // Feed the raw message to the pipe over stdin, exactly like Exim would.
    // Route the pipe's error_log (its match/store/drop reasons) into our log
    // so a dropped photo leaves a "why" trail.
    $out = shell_exec('cat ' . escapeshellarg($f) . ' | ' . escapeshellarg($PHP)
        . ' -d log_errors=1 -d error_log=' . escapeshellarg($LOG) . ' '
        . escapeshellarg($PIPE) . ' 2>&1');
    // File into cur/ with the Maildir "seen" flag so it is never reprocessed.
    $dest = $curDir . '/' . $base . ':2,S';
    if (!@rename($f, $dest)) {
        intake_log('WARN: could not move ' . $base . ' out of new/');
    }
    $out = trim((string)$out);
    intake_log('processed ' . $base . ($out !== '' ? ' | ' . str_replace("\n", ' ; ', $out) : ' | (ok)'));
    $done++;
}
intake_log('batch done: ' . $done . ' message(s)');
