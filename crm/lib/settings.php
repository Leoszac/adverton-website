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
    'INSTANTLY_WEBHOOK_SECRET',
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
    // Cold-calling DNC scrub (Sprint cold-call, schema-v13). When empty,
    // crm/lib/dnc_scrub.php falls into STUB mode (returns 'clean' for all)
    // and the import + calling pages show a yellow warning.
    'DNCSCRUB_API_KEY',
    'DNCSCRUB_API_URL',
    // Outscraper — prospect sourcing (search + email enrichment + validator).
    // Stored here so sourcing scripts read it via crm_config() — no re-pasting.
    'OUTSCRAPER_API_KEY',
    // Adverton Care — Twilio (SMS text-back + call forwarding + reviews).
    // The Auth Token doubles as the webhook-signature key. Paste from
    // console.twilio.com. Empty → Care runs in STUB MODE (logic works, no
    // live sends). See care/lib/twilio.php.
    'TWILIO_ACCOUNT_SID',
    'TWILIO_AUTH_TOKEN',
    // OpenSign keys are intentionally NOT whitelisted right now — the lib
    // (crm/lib/opensign.php) is dormant pending a paid OpenSign plan. The
    // pre-contract flow uses Stripe Checkout + click-wrap T&C instead.
    // To activate OpenSign later: add OPENSIGN_API_KEY, OPENSIGN_TEMPLATE_ID,
    // OPENSIGN_WEBHOOK_SECRET, OPENSIGN_BASE_URL here + restore the section
    // in /crm/integrations.php.
];

// Subset of CRM_DB_BACKED_KEYS encrypted at rest (AES-256-GCM via credentials.php).
// The Twilio Auth Token is the priority: it's both the API credential and the
// webhook-signing key. Excludes the master key itself, URLs, flags, addresses.
const CRM_ENCRYPTED_KEYS = [
    'STRIPE_API_KEY', 'STRIPE_WEBHOOK_SECRET', 'PANDADOC_WEBHOOK_SECRET',
    'OPENPHONE_WEBHOOK_SECRET', 'SMARTLEAD_WEBHOOK_SECRET', 'INSTANTLY_API_KEY',
    'INSTANTLY_WEBHOOK_SECRET', 'CALENDLY_API_TOKEN', 'RESEND_API_KEY',
    'ANTHROPIC_API_KEY', 'NAMECHEAP_API_KEY', 'DNCSCRUB_API_KEY',
    'OUTSCRAPER_API_KEY', 'TWILIO_AUTH_TOKEN',
];

// Encrypt a sensitive value for storage. Returns the value UNCHANGED if it's
// not an encrypted key, is empty, or encryption is unavailable — so a missing
// CREDENTIALS_KEY degrades to today's plaintext behavior instead of breaking.
function crm_settingEncrypt(string $key, string $value): string {
    if ($value === '' || !in_array($key, CRM_ENCRYPTED_KEYS, true)) return $value;
    try { require_once __DIR__ . '/credentials.php'; return crm_credEncrypt($value); }
    catch (Throwable $e) { error_log('[crm_settingEncrypt] ' . $key . ': ' . $e->getMessage()); return $value; }
}

// Decrypt a stored value if it's an AES-GCM blob ("AGCM" prefix). Plaintext
// passes through unchanged (backward-compat with values saved before encryption
// was enabled). On decrypt failure, returns '' so the integration fails safe
// (e.g. Care drops to stub) rather than using a corrupt secret.
function crm_settingDecrypt(string $key, string $value): string {
    if ($value === '' || !in_array($key, CRM_ENCRYPTED_KEYS, true)) return $value;
    if (strncmp($value, 'AGCM', 4) !== 0) return $value;   // not encrypted → plaintext
    try { require_once __DIR__ . '/credentials.php'; return crm_credDecrypt($value); }
    catch (Throwable $e) { error_log('[crm_settingDecrypt] ' . $key . ': ' . $e->getMessage()); return ''; }
}

function crm_loadDbSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = crm_db()->query('SELECT `key`, `value` FROM settings');
        $cache = [];
        foreach ($stmt->fetchAll() as $r) {
            $k = (string)$r['key'];
            $cache[$k] = crm_settingDecrypt($k, (string)($r['value'] ?? ''));
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
        return $stmt->execute([$key, crm_settingEncrypt($key, $value), $userId]);
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
        $raw = (string)($row['value'] ?? '');
        return [
            'value'      => crm_settingDecrypt($key, $raw),
            'updated_at' => $row['updated_at'] ?? null,
            'set'        => ($raw !== ''),
        ];
    } catch (Throwable $e) {
        return ['value'=>'','updated_at'=>null,'set'=>false];
    }
}
