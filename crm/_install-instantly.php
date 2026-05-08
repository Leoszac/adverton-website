<?php
// One-shot installer: writes INSTANTLY_API_KEY to settings DB and runs a
// connection test. DELETE this file after first successful run.
//
// Usage:
//   curl -sX POST 'https://adverton.net/crm/_install-instantly.php?token=inst-setup-7f9k2m4q8z' \
//        --data-urlencode "key=<INSTANTLY_API_KEY>"
//
// Auth: token in query string. Token is rotated/deleted with the file.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/instantly.php';

const ONE_SHOT_TOKEN = 'inst-setup-7f9k2m4q8z';

header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== ONE_SHOT_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$apiKey = trim((string)($_POST['key'] ?? ''));
if ($apiKey === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing key param']);
    exit;
}

$saved = crm_saveSetting('INSTANTLY_API_KEY', $apiKey, null);

// Force a fresh DB-settings load on next call (clear static cache by triggering a new request)
// crm_loadDbSettings is static-cached; we re-call by hitting a fresh function path
$test = crm_instantlyTestConnection();
$accounts = crm_instantlyListAccounts(50);

echo json_encode([
    'saved' => $saved,
    'key_length' => strlen($apiKey),
    'test' => $test,
    'mailboxes' => array_map(fn($a) => [
        'email'        => $a['email'] ?? null,
        'status'       => $a['status'] ?? null,
        'warmup'       => is_array($a['warmup'] ?? null) ? ($a['warmup']['status'] ?? '?') : ($a['warmup'] ?? '?'),
        'health'       => $a['health_score'] ?? ($a['warmup']['warmup_score'] ?? null),
        'sent_today'   => $a['emails_sent_today'] ?? null,
    ], $accounts['items'] ?? []),
    'mailbox_count' => count($accounts['items'] ?? []),
    'accounts_error' => $accounts['error'] ?? '',
], JSON_PRETTY_PRINT);
