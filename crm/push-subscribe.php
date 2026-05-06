<?php
// POST endpoint where the service worker registers a Web Push subscription.
// Auth-gated (must be logged in).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/push.php';

$user = crm_requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST only']); exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true) ?: [];

if (!empty($payload['unsubscribe']) && !empty($payload['endpoint'])) {
    $ok = crm_deletePushSubscription((string)$payload['endpoint']);
    echo json_encode(['ok' => $ok]); exit;
}

$endpoint = (string)($payload['endpoint'] ?? '');
$keys     = $payload['keys'] ?? [];
$p256dh   = (string)($keys['p256dh'] ?? '');
$auth     = (string)($keys['auth']   ?? '');

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'incomplete']); exit;
}

$ok = crm_savePushSubscription(
    (int)$user['id'], $endpoint, $p256dh, $auth,
    $_SERVER['HTTP_USER_AGENT'] ?? null
);
echo json_encode(['ok' => $ok]);
