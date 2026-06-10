<?php
// care/cron-reviews.php — send due review requests + one reminder each.
// Run via cPanel cron every 5–15 min:
//   */15 * * * * /usr/local/bin/php /home/advertonnet/public_html/care/cron-reviews.php

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/reviews.php';

// Single-run lock so a slow batch can't overlap the next tick.
$lock = @fopen('/home/advertonnet/logs/.care-reviews.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$r = care_sendDueReviews();
if ($r['sent'] || $r['reminded'] || $r['failed']) {
    care_log("cron-reviews: sent={$r['sent']} reminded={$r['reminded']} failed={$r['failed']}");
}
