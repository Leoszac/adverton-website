<?php
// Magic-link tokens — single-use, time-limited, opaque tokens that let a
// client (no CRM login) access a public form like /pre-contract or /kickoff.
//
// Pattern: 64-char hex from random_bytes(32). Stored alongside the row that
// owns it (leads.magic_token, clients.magic_token). On use, we rotate the
// token (single-use semantics) so a leaked link can't be replayed.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_MAGIC_TOKEN_TTL_DAYS = 14;

function crm_generateMagicToken(): string {
    return bin2hex(random_bytes(32));
}

// Resolve a magic token to (kind, row_id) where kind is 'lead' or 'client'.
// Returns null if not found, expired, or malformed.
function crm_resolveMagicToken(?string $token): ?array {
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    try {
        // Try leads first (pre-contract phase)
        $stmt = crm_db()->prepare(
            'SELECT id, magic_token_expires_at FROM leads
             WHERE magic_token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['magic_token_expires_at']
                && strtotime($row['magic_token_expires_at']) < time()) {
                return null;
            }
            return ['kind' => 'lead', 'id' => (int)$row['id']];
        }
        // Then clients (kickoff phase)
        $stmt = crm_db()->prepare(
            'SELECT id, magic_token_expires_at FROM clients
             WHERE magic_token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['magic_token_expires_at']
                && strtotime($row['magic_token_expires_at']) < time()) {
                return null;
            }
            return ['kind' => 'client', 'id' => (int)$row['id']];
        }
        return null;
    } catch (Throwable $e) {
        error_log('[crm_resolveMagicToken] ' . $e->getMessage());
        return null;
    }
}

// Mint a fresh token on a lead and persist it. Returns the token. Replaces
// any prior token (single active token per lead).
function crm_setLeadMagicToken(int $leadId, int $ttlDays = CRM_MAGIC_TOKEN_TTL_DAYS): string {
    $token   = crm_generateMagicToken();
    $expires = date('Y-m-d H:i:s', time() + $ttlDays * 86400);
    $stmt = crm_db()->prepare(
        'UPDATE leads
         SET magic_token = ?, magic_token_expires_at = ?
         WHERE id = ?'
    );
    $stmt->execute([$token, $expires, $leadId]);
    return $token;
}

// Mint a fresh token on a client and persist it.
function crm_setClientMagicToken(int $clientId, int $ttlDays = CRM_MAGIC_TOKEN_TTL_DAYS): string {
    $token   = crm_generateMagicToken();
    $expires = date('Y-m-d H:i:s', time() + $ttlDays * 86400);
    $stmt = crm_db()->prepare(
        'UPDATE clients
         SET magic_token = ?, magic_token_expires_at = ?
         WHERE id = ?'
    );
    $stmt->execute([$token, $expires, $clientId]);
    return $token;
}

// Invalidate the token after a successful single-use submission (or on
// admin reset). Pass kind='lead'|'client'.
function crm_invalidateMagicToken(string $kind, int $rowId): void {
    $table = $kind === 'lead' ? 'leads' : 'clients';
    $stmt = crm_db()->prepare(
        "UPDATE {$table}
         SET magic_token = NULL, magic_token_expires_at = NULL
         WHERE id = ?"
    );
    $stmt->execute([$rowId]);
}
