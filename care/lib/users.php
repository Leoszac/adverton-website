<?php
// Adverton Care — multi-user access per business. Each team member has their own
// login token (magic link). The owner adds/removes members. Resolves the current
// user from ?t= or the care_sess cookie. A legacy care_access token (pre-v19)
// still works as the owner so old links don't break.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/care.php';

function care_userByToken(?string $token): ?array {
    if (!$token || !preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    try {
        $st = care_db()->prepare('SELECT id, client_id, name, role FROM care_users WHERE token = ? LIMIT 1');
        $st->execute([$token]);
        $u = $st->fetch();
        if ($u) {
            @care_db()->prepare('UPDATE care_users SET last_seen_at = NOW() WHERE id = ?')->execute([$u['id']]);
            return ['id'=>(int)$u['id'], 'client_id'=>(int)$u['client_id'], 'name'=>$u['name'], 'role'=>$u['role']];
        }
        // Legacy: a pre-v19 care_access token logs in as the owner.
        $cid = function_exists('care_clientFromToken') ? care_clientFromToken($token) : null;
        if ($cid) return ['id'=>0, 'client_id'=>(int)$cid, 'name'=>null, 'role'=>'owner'];
        return null;
    } catch (Throwable $e) { return null; }
}

// Resolve the signed-in user from ?t= or the cookie; sets the 90-day cookie.
function care_currentUser(): ?array {
    $qt = (string)($_GET['t'] ?? '');
    $token = $qt !== '' ? $qt : (string)($_COOKIE['care_sess'] ?? '');
    $u = care_userByToken($token);
    if ($u && $qt !== '' && !headers_sent()) {
        setcookie('care_sess', $token, [
            'expires' => time() + 86400 * 90, 'path' => '/care',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    return $u;
}

function care_addUser(int $clientId, ?string $name, ?string $contact, string $role = 'staff'): array {
    $role = in_array($role, ['owner','staff'], true) ? $role : 'staff';
    $c = null;
    if ($contact) { $c = (strpos($contact, '@') !== false) ? trim($contact) : care_e164($contact); }
    try {
        $token = bin2hex(random_bytes(24));
        care_db()->prepare('INSERT INTO care_users (client_id, name, contact, role, token) VALUES (?, ?, ?, ?, ?)')
            ->execute([$clientId, ($name ?: null), $c, $role, $token]);
        return ['ok'=>true, 'token'=>$token, 'link'=>CARE_BASE_URL . '/?t=' . $token];
    } catch (Throwable $e) { return ['ok'=>false, 'error'=>$e->getMessage()]; }
}

function care_listUsers(int $clientId): array {
    try {
        $st = care_db()->prepare('SELECT id, name, contact, role, token, last_seen_at FROM care_users WHERE client_id = ? ORDER BY FIELD(role,"owner","staff"), id ASC');
        $st->execute([$clientId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Remove a member. Never removes the last owner.
function care_revokeUser(int $clientId, int $userId): bool {
    try {
        $st = care_db()->prepare('SELECT role FROM care_users WHERE id = ? AND client_id = ?');
        $st->execute([$userId, $clientId]);
        $r = $st->fetch();
        if (!$r) return false;
        if ($r['role'] === 'owner') {
            $oc = care_db()->prepare("SELECT COUNT(*) FROM care_users WHERE client_id = ? AND role = 'owner'");
            $oc->execute([$clientId]);
            if ((int)$oc->fetchColumn() <= 1) return false;   // keep at least one owner
        }
        $d = care_db()->prepare('DELETE FROM care_users WHERE id = ? AND client_id = ?');
        $d->execute([$userId, $clientId]);
        return $d->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

// The owner's login token for a client — creating one if none exists, and
// backfilling their contact phone so they can self-serve re-login.
function care_ensureOwner(int $clientId, ?string $contact = null): string {
    foreach (care_listUsers($clientId) as $u) {
        if ($u['role'] === 'owner') {
            if ($contact && !$u['contact']) {
                try { care_db()->prepare('UPDATE care_users SET contact = ? WHERE id = ?')->execute([(care_e164($contact) ?: $contact), $u['id']]); } catch (Throwable $e) {}
            }
            return (string)$u['token'];
        }
    }
    $a = care_addUser($clientId, null, $contact, 'owner');
    return $a['ok'] ? (string)$a['token'] : '';
}

// Find a user by their phone/email (for self-serve "text me my link").
function care_findUserByContact(string $contact): ?array {
    $c = (strpos($contact, '@') !== false) ? trim($contact) : care_e164($contact);
    if (!$c) return null;
    try {
        $st = care_db()->prepare('SELECT id, client_id, name, role, token FROM care_users WHERE contact = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$c]);
        $u = $st->fetch();
        return $u ?: null;
    } catch (Throwable $e) { return null; }
}

// Light per-IP rate limit for the sign-in (anti-abuse on the "text me a link").
function care_loginRateOk(string $ip): bool {
    if ($ip === '') return true;
    $dir = '/home/advertonnet/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $path = $dir . '/carelogin-' . preg_replace('/[^a-zA-Z0-9]/', '_', $ip) . '.json';
    $now = time(); $hits = [];
    if (is_readable($path)) { $raw = (array) json_decode((string) @file_get_contents($path), true); $hits = array_values(array_filter($raw, static fn($t) => is_int($t) && $t >= $now - 600)); }
    if (count($hits) >= 5) return false;
    $hits[] = $now; @file_put_contents($path, json_encode($hits), LOCK_EX);
    return true;
}
