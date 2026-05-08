<?php
// Cron: pulls Instantly mailbox health snapshot and stores it in
// settings.INSTANTLY_HEALTH_SNAPSHOT so the dashboard can render it without
// hitting the API on every page load.
//
// Schedule: every 60 minutes via cPanel cron:
//   curl -s 'https://adverton.net/crm/cron-instantly-health.php?token=XXX' >/dev/null
// Token is the INSTANTLY_WEBHOOK_SECRET (reused — keeps our list of secrets short).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/instantly.php';

header('Content-Type: text/plain');

$secret = crm_config('INSTANTLY_WEBHOOK_SECRET');
$got    = $_GET['token'] ?? '';

if (!$secret) {
    http_response_code(503);
    exit("INSTANTLY_WEBHOOK_SECRET not set\n");
}
if (!hash_equals((string)$secret, (string)$got)) {
    http_response_code(403);
    exit("bad token\n");
}

$apiKey = crm_instantlyApiKey();
if (!$apiKey) {
    http_response_code(503);
    exit("INSTANTLY_API_KEY not set\n");
}

$snap = crm_instantlyAccountsSnapshot();
$ok   = crm_instantlySaveHealthSnapshot($snap);

if (!$ok) {
    http_response_code(500);
    exit("snapshot save failed\n");
}

$count = count($snap['items'] ?? []);
$err   = (string)($snap['error'] ?? '');

echo "ok: synced={$count}";
if ($err) echo " err={$err}";
echo "\n";
