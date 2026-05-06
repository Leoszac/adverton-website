<?php
// Web Push subscription registry.
//
// NOTE: Sending actual VAPID-encrypted push payloads from PHP requires either
// the `web-push-libs/web-push-php` Composer library or hand-rolling ECDH/AES-GCM
// crypto (RFC 8291). That's out of scope for the v1 ship — this file only
// maintains the subscription registry. If/when you want to push payloads,
// install web-push-php via Composer and implement crm_pushSend() below.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

function crm_savePushSubscription(int $userId, string $endpoint, string $p256dh, string $authKey, ?string $ua): bool {
    if ($endpoint === '' || $p256dh === '' || $authKey === '') return false;
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, ua)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth_key=VALUES(auth_key), ua=VALUES(ua), user_id=VALUES(user_id)'
        );
        return $stmt->execute([
            $userId, $endpoint, $p256dh, $authKey,
            $ua ? mb_substr($ua, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[crm_savePushSubscription] ' . $e->getMessage());
        return false;
    }
}

function crm_listPushSubscriptions(int $userId): array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function crm_deletePushSubscription(string $endpoint): bool {
    try {
        $stmt = crm_db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        return $stmt->execute([$endpoint]);
    } catch (Throwable $e) { return false; }
}

// Stub. Returns false until web-push-php is installed.
function crm_pushSend(int $userId, string $title, string $body, string $url = '/crm/'): bool {
    error_log('[crm_pushSend] called but VAPID send not implemented (need web-push-php). user=' . $userId);
    return false;
}
