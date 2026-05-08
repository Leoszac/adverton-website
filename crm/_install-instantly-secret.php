<?php
// One-shot: install INSTANTLY_WEBHOOK_SECRET in DB + populate first health snapshot.
// DELETE after successful run.
//
// Usage:
//   curl -sX POST 'https://adverton.net/crm/_install-instantly-secret.php?token=inst-secret-9k7m2q' -d ''

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/instantly.php';

const ONE_SHOT_TOKEN  = 'inst-secret-9k7m2q';
const WEBHOOK_SECRET  = 'fb83e553a45863a3e9e8d190cb57c368';

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

$saved = crm_saveSetting('INSTANTLY_WEBHOOK_SECRET', WEBHOOK_SECRET, null);

// Trigger first health snapshot
$snap   = crm_instantlyAccountsSnapshot();
$snapOk = crm_instantlySaveHealthSnapshot($snap);

echo json_encode([
    'webhook_secret_saved' => $saved,
    'snapshot_saved'       => $snapOk,
    'snapshot_count'       => count($snap['items'] ?? []),
    'snapshot_error'       => $snap['error'] ?? '',
    'snapshot_sample'      => array_slice($snap['items'] ?? [], 0, 2),
], JSON_PRETTY_PRINT);
