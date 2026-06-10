<?php
// Encrypted credentials vault. Stores hosting / domain / GBP / LSA / Yelp /
// Meta logins per client, encrypted at rest with AES-256-GCM (authenticated)
// + a master key from crm_config('CREDENTIALS_KEY').
//
// Threat model:
//   - DB dump leak → values still encrypted (key lives in php config, not DB)
//   - Tampered ciphertext → GCM auth tag fails → decrypt refuses (no oracle)
//   - Curious low-privilege user → role-gated reads, audit-logged
//   - Lost server access → key rotation procedure documented in CRM-SETUP.md
//
// Wire format of `value_enc` BLOB (current, authenticated):
//   4 bytes   magic "AGCM"
//   12 bytes  nonce/IV  (random per write)
//   16 bytes  GCM auth tag
//   N bytes   ciphertext (AES-256-GCM of UTF-8 plaintext)
// Legacy format still readable (no magic prefix): 16-byte IV + AES-256-CBC
// ciphertext. Legacy rows are upgraded to GCM the next time they're written.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_CRED_CIPHER      = 'AES-256-CBC';   // legacy read path only
const CRM_CRED_IV_LEN      = 16;              // legacy CBC IV length
const CRM_CRED_CIPHER_GCM  = 'aes-256-gcm';   // current authenticated cipher
const CRM_CRED_GCM_MAGIC   = 'AGCM';          // 4-byte prefix marking GCM blobs
const CRM_CRED_GCM_IV_LEN  = 12;              // 96-bit nonce (GCM standard)
const CRM_CRED_GCM_TAG_LEN = 16;              // 128-bit auth tag
const CRM_CRED_KIND_LIST = [
    'cpanel','sftp','wordpress','domain_registrar',
    'google_business_profile','google_local_services','google_ads',
    'facebook_business','yelp_for_business','custom',
];

// Resolve the master key. 32-byte raw key is the natural size for AES-256.
// We accept hex (64 chars) or raw 32-byte string. Throws if missing or
// malformed — never fail-open with a placeholder key.
function crm_credMasterKey(): string {
    $k = (string)crm_config('CREDENTIALS_KEY');
    if ($k === '') throw new RuntimeException('CREDENTIALS_KEY not set in crm-config.php');
    // Hex form: 64 chars representing 32 bytes
    if (strlen($k) === 64 && ctype_xdigit($k)) {
        $raw = hex2bin($k);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('CREDENTIALS_KEY hex parse failed');
        }
        return $raw;
    }
    // Raw form (rare; people will generally paste hex)
    if (strlen($k) === 32) return $k;
    throw new RuntimeException('CREDENTIALS_KEY must be 32 raw bytes or 64-char hex');
}

function crm_credEncrypt(string $plaintext): string {
    $key = crm_credMasterKey();
    $iv  = random_bytes(CRM_CRED_GCM_IV_LEN);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, CRM_CRED_CIPHER_GCM, $key, OPENSSL_RAW_DATA,
                           $iv, $tag, '', CRM_CRED_GCM_TAG_LEN);
    if ($ct === false || strlen($tag) !== CRM_CRED_GCM_TAG_LEN) {
        throw new RuntimeException('openssl_encrypt (gcm) failed');
    }
    return CRM_CRED_GCM_MAGIC . $iv . $tag . $ct;
}

function crm_credDecrypt(string $blob): string {
    $key = crm_credMasterKey();

    // Current authenticated format: "AGCM" + 12B nonce + 16B tag + ciphertext.
    if (strncmp($blob, CRM_CRED_GCM_MAGIC, 4) === 0) {
        $iv  = substr($blob, 4, CRM_CRED_GCM_IV_LEN);
        $tag = substr($blob, 4 + CRM_CRED_GCM_IV_LEN, CRM_CRED_GCM_TAG_LEN);
        $ct  = substr($blob, 4 + CRM_CRED_GCM_IV_LEN + CRM_CRED_GCM_TAG_LEN);
        if (strlen($iv) !== CRM_CRED_GCM_IV_LEN || strlen($tag) !== CRM_CRED_GCM_TAG_LEN) {
            throw new RuntimeException('credential blob malformed (gcm)');
        }
        $pt = openssl_decrypt($ct, CRM_CRED_CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) throw new RuntimeException('gcm auth/decrypt failed');  // tampered or wrong key
        return $pt;
    }

    // Legacy AES-256-CBC (no magic prefix): 16B IV + ciphertext. Read-only;
    // upgraded to GCM the next time the row is written.
    if (strlen($blob) < CRM_CRED_IV_LEN + 1) throw new RuntimeException('credential blob too short');
    $iv = substr($blob, 0, CRM_CRED_IV_LEN);
    $ct = substr($blob, CRM_CRED_IV_LEN);
    $pt = openssl_decrypt($ct, CRM_CRED_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    if ($pt === false) throw new RuntimeException('openssl_decrypt failed (legacy cbc)');
    return $pt;
}

// CRUD ----------------------------------------------------------------

// Save (insert or upsert) a credential. $value goes through encryption;
// non-secret fields (label, url, username, notes) are stored plain.
// One row per (client_id, kind, label) — we update if a row with same
// (client_id, kind, label) exists, otherwise insert.
function crm_storeCredential(int $clientId, string $kind, ?string $label,
                             ?string $url, ?string $username, ?string $value,
                             ?string $notes, ?int $actorUserId): ?int {
    if (!in_array($kind, CRM_CRED_KIND_LIST, true)) {
        throw new RuntimeException('Unknown credential kind: ' . $kind);
    }
    $valueEnc = ($value !== null && $value !== '') ? crm_credEncrypt($value) : null;

    try {
        $db = crm_db();
        // Try update by composite (client, kind, label-or-empty)
        $stmt = $db->prepare(
            'SELECT id FROM client_credentials
             WHERE client_id = ? AND kind = ? AND COALESCE(label, "") = COALESCE(?, "")
             LIMIT 1'
        );
        $stmt->execute([$clientId, $kind, $label]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $sets = ['url = ?','username = ?','notes = ?'];
            $params = [$url, $username, $notes];
            if ($valueEnc !== null) {
                $sets[] = 'value_enc = ?'; $params[] = $valueEnc;
                $sets[] = 'rotated_at = NOW()';
            }
            $params[] = (int)$existing;
            $stmt = $db->prepare('UPDATE client_credentials SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($params);
            crm_credAuditLog($clientId, $actorUserId, 'updated', $kind, $label);
            return (int)$existing;
        }

        $stmt = $db->prepare(
            'INSERT INTO client_credentials
                (client_id, kind, label, url, username, value_enc, notes, rotated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$clientId, $kind, $label, $url, $username, $valueEnc, $notes]);
        $newId = (int)$db->lastInsertId();
        crm_credAuditLog($clientId, $actorUserId, 'created', $kind, $label);
        return $newId;
    } catch (Throwable $e) {
        error_log('[crm_storeCredential] ' . $e->getMessage());
        return null;
    }
}

// Read a single credential's PLAINTEXT value. Audit-logged. Returns ['ok',
// 'value', 'error']. Never returns the value if decryption fails.
function crm_revealCredential(int $credId, ?int $actorUserId): array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT id, client_id, kind, label, url, username, value_enc, notes
             FROM client_credentials WHERE id = ?'
        );
        $stmt->execute([$credId]);
        $row = $stmt->fetch();
        if (!$row) return ['ok' => false, 'value' => null, 'error' => 'not found'];

        $value = null;
        if (!empty($row['value_enc'])) {
            $value = crm_credDecrypt((string)$row['value_enc']);
        }
        crm_credAuditLog((int)$row['client_id'], $actorUserId, 'read',
            (string)$row['kind'], (string)($row['label'] ?? ''));
        $row['value'] = $value;
        unset($row['value_enc']);
        return ['ok' => true, 'value' => $value, 'row' => $row, 'error' => null];
    } catch (Throwable $e) {
        error_log('[crm_revealCredential] ' . $e->getMessage());
        return ['ok' => false, 'value' => null, 'error' => $e->getMessage()];
    }
}

// List metadata only (NEVER includes plaintext). Used by client.php /
// client-credentials.php tables. Query each plaintext via crm_revealCredential.
function crm_listCredentials(int $clientId): array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT id, kind, label, url, username, notes, rotated_at, expires_at,
                    created_at, updated_at,
                    CASE WHEN value_enc IS NULL THEN 0 ELSE 1 END AS has_value
             FROM client_credentials
             WHERE client_id = ?
             ORDER BY kind ASC, label ASC'
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// Resolve the first credential of a given kind for a client (e.g. "give me
// the cPanel login for client #42 to deploy"). Returns the same shape as
// crm_revealCredential. Used by deploy.php.
function crm_getFirstCredentialOfKind(int $clientId, string $kind, ?int $actorUserId): array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT id FROM client_credentials
             WHERE client_id = ? AND kind = ?
             ORDER BY rotated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$clientId, $kind]);
        $cid = $stmt->fetchColumn();
        if (!$cid) return ['ok' => false, 'value' => null, 'error' => 'no credential of kind ' . $kind];
        return crm_revealCredential((int)$cid, $actorUserId);
    } catch (Throwable $e) {
        return ['ok' => false, 'value' => null, 'error' => $e->getMessage()];
    }
}

function crm_deleteCredential(int $credId, ?int $actorUserId): bool {
    try {
        $stmt = crm_db()->prepare('SELECT client_id, kind, label FROM client_credentials WHERE id = ?');
        $stmt->execute([$credId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        $stmt = crm_db()->prepare('DELETE FROM client_credentials WHERE id = ?');
        $ok = $stmt->execute([$credId]);
        if ($ok) crm_credAuditLog((int)$row['client_id'], $actorUserId, 'deleted',
            (string)$row['kind'], (string)($row['label'] ?? ''));
        return (bool)$ok;
    } catch (Throwable $e) {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Audit log — every read/write goes into client_events so a stolen
// credential leaves a trail. Best-effort; never blocks the operation.
function crm_credAuditLog(int $clientId, ?int $userId, string $action,
                         string $kind, ?string $label): void {
    try {
        $body = "credential_{$action}: {$kind}" . ($label ? " ({$label})" : '');
        $stmt = crm_db()->prepare(
            'INSERT INTO client_events (client_id, user_id, type, body, meta)
             VALUES (?, ?, "note", ?, ?)'
        );
        $stmt->execute([$clientId, $userId, $body,
            json_encode(['action' => $action, 'kind' => $kind, 'label' => $label])]);
    } catch (Throwable $e) {
        error_log('[crm_credAuditLog] ' . $e->getMessage());
    }
}
