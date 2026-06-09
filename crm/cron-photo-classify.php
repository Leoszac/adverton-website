<?php
// Process pending client_assets through Anthropic Vision.
//
// Trigger: cron every 5 minutes.
//   */5 * * * * /usr/local/bin/php /home/advertonnet/public_html/crm/cron-photo-classify.php >> /home/advertonnet/logs/cron-photo-classify.log 2>&1
// Or via curl with token:
//   /usr/bin/curl -sS 'https://adverton.net/crm/cron-photo-classify.php?token=SEED_TOKEN'
//
// Per run: classify up to N pending assets (ai_description IS NULL) ordered
// oldest-first. Bounded so a backlog doesn't blow API quota in one shot.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/photos.php';

const CRM_PHOTO_BATCH_SIZE = 20;

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

$rows = crm_db()->query(
    'SELECT id FROM client_assets
     WHERE ai_description IS NULL
     ORDER BY uploaded_at ASC
     LIMIT ' . CRM_PHOTO_BATCH_SIZE
)->fetchAll();

$ok = 0; $fail = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $res = crm_classifyAssetWithAI($id);
    if ($res['ok']) {
        $ok++;
    } else {
        $fail++;
        error_log('[cron-photo-classify] asset ' . $id . ': ' . ($res['error'] ?? 'unknown'));
    }
}

echo "Photo classify: ok={$ok} fail={$fail} (batch up to " . CRM_PHOTO_BATCH_SIZE . ")\n";
