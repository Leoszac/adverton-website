<?php
// Runtime-editable settings (key/value) stored in DB.
//
// Used as a layer on top of crm-config.php for things the founder wants to
// change from the UI: webhook secrets, integration URLs, optional sender
// overrides. DB credentials and SEED_TOKEN MUST stay in crm-config.php.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

// Whitelist of keys that integrations.php is allowed to manage.
// crm_config() consults the DB for any key in this list before falling back
// to crm-config.php.
const CRM_DB_BACKED_KEYS = [
    'STRIPE_API_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'PANDADOC_WEBHOOK_SECRET',
    'OPENPHONE_WEBHOOK_SECRET',
    'SMARTLEAD_WEBHOOK_SECRET',
    'INSTANTLY_API_KEY',
    'NEW_LEAD_WEBHOOK_URL',
    'CALENDLY_API_TOKEN',
    'RESEND_API_KEY',
    'CRM_FROM_ADDRESS',
    'CRM_REPLY_TO',
    // Onboarding pipeline (Sprints 0–4): managed from /crm/integrations.php
    // so the founder doesn't need shell/file-manager access to crm-config.php.
    'ANTHROPIC_API_KEY',
    'CREDENTIALS_KEY',
    'NAMECHEAP_API_USER',
    'NAMECHEAP_API_KEY',
    'NAMECHEAP_CLIENT_IP',
    'NAMECHEAP_SANDBOX',
    // OpenSign keys are intentionally NOT whitelisted right now — the lib
    // (crm/lib/opensign.php) is dormant pending a paid OpenSign plan. The
    // pre-contract flow uses Stripe Checkout + click-wrap T&C instead.
    // To activate OpenSign later: add OPENSIGN_API_KEY, OPENSIGN_TEMPLATE_ID,
    // OPENSIGN_WEBHOOK_SECRET, OPENSIGN_BASE_URL here + restore the section
    // in /crm/integrations.php.
];

function crm_loadDbSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = crm_db()->query('SELECT `key`, `value` FROM settings');
        $cache = [];
        foreach ($stmt->fetchAll() as $r) {
            $cache[(string)$r['key']] = (string)($r['value'] ?? '');
        }
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

function crm_saveSetting(string $key, string $value, ?int $userId = null): bool {
    if (!in_array($key, CRM_DB_BACKED_KEYS, true)) return false;
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO settings (`key`, `value`, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)'
        );
        return $stmt->execute([$key, $value, $userId]);
    } catch (Throwable $e) {
        error_log('[crm_saveSetting] ' . $e->getMessage());
        return false;
    }
}

function crm_getSettingMeta(string $key): array {
    try {
        $stmt = crm_db()->prepare('SELECT `value`, updated_at FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return [
            'value'      => (string)($row['value'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
            'set'        => !empty($row['value']),
        ];
    } catch (Throwable $e) {
        return ['value'=>'','updated_at'=>null,'set'=>false];
    }
}
