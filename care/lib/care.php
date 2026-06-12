<?php
// Adverton Care — shared bootstrap for the standalone telephony product.
// Reuses the CRM's DB connection + config loader (crm_db / crm_config) but
// keeps Care's own tables (care_*), logging and helpers isolated. Care files
// live in public_html/care/ ; the CRM lib is a sibling at public_html/crm/lib/.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);

require_once __DIR__ . '/../../crm/lib/db.php';        // crm_config(), crm_db(), crm_h()
require_once __DIR__ . '/../../crm/lib/phone_normalize.php'; // crm_phoneToE164()

const CARE_LOG = '/home/advertonnet/logs/care.log';

function care_log(string $line): void {
    @file_put_contents(CARE_LOG, gmdate('Y-m-d\TH:i:s\Z') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function care_db(): PDO { return crm_db(); }

// E.164 normalize with a safe fallback (digits → +1XXXXXXXXXX for US).
function care_e164(string $raw): ?string {
    if (function_exists('crm_phoneToE164')) {
        $n = crm_phoneToE164($raw);
        if ($n) return $n;
    }
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 10) return '+1' . $d;
    if (strlen($d) === 11 && $d[0] === '1') return '+' . $d;
    return $d ? '+' . $d : null;
}

// Has this number opted out (replied STOP)? Global suppression.
// Opt-out is per-sender (per TCPA/CTIA): a STOP to one contractor must not mute
// every Adverton client. A row with client_id = 0 is a global hard-suppress
// (abuse) that applies everywhere.
function care_isOptedOut(string $phoneE164, int $clientId = 0): bool {
    try {
        $st = care_db()->prepare('SELECT 1 FROM care_optouts WHERE phone = ? AND (client_id = ? OR client_id = 0) LIMIT 1');
        $st->execute([$phoneE164, $clientId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// Public base for Twilio webhook URLs + the client dashboard. Change to
// https://care.adverton.net once that subdomain points at public_html/care.
const CARE_BASE_URL = 'https://adverton.net/care';

// Per-client daily outbound-SMS ceiling (fair-use / runaway-cost guard). A normal
// small contractor never approaches this; it only trips on a bug or abuse, so a
// stray loop can never run up a surprise Twilio bill. Bump if a client needs more.
const CARE_DAILY_SMS_CAP = 200;

// ── Passwordless access tokens (one stable token per client) ─────────────
function care_issueToken(int $clientId): string {
    try {
        $st = care_db()->prepare('SELECT token FROM care_access WHERE client_id = ? LIMIT 1');
        $st->execute([$clientId]);
        $existing = $st->fetchColumn();
        if ($existing) return (string)$existing;
        $token = bin2hex(random_bytes(24));   // 48 hex chars
        care_db()->prepare('INSERT INTO care_access (client_id, token) VALUES (?, ?)')->execute([$clientId, $token]);
        return $token;
    } catch (Throwable $e) { care_log('issueToken err: ' . $e->getMessage()); return ''; }
}

function care_clientFromToken(?string $token): ?int {
    if (!$token || !preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    try {
        $st = care_db()->prepare('SELECT client_id FROM care_access WHERE token = ? LIMIT 1');
        $st->execute([$token]);
        $cid = $st->fetchColumn();
        if ($cid) { @care_db()->prepare('UPDATE care_access SET last_seen_at = NOW() WHERE token = ?')->execute([$token]); }
        return $cid ? (int)$cid : null;
    } catch (Throwable $e) { return null; }
}

// Resolve the dashboard client from ?t= or the care_sess cookie; sets the
// cookie (90d, HttpOnly, Secure, SameSite=Lax) so the magic link is
// bookmarkable without the token staying in the URL.
function care_currentClientId(): ?int {
    $qt = (string)($_GET['t'] ?? '');
    $token = $qt !== '' ? $qt : (string)($_COOKIE['care_sess'] ?? '');
    $cid = care_clientFromToken($token);
    if ($cid && $qt !== '' && !headers_sent()) {
        setcookie('care_sess', $token, [
            'expires' => time() + 86400 * 90, 'path' => '/care',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    return $cid;
}
