<?php
// CRM auth — PHP sessions + bcrypt + CSRF.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

function crm_sessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/crm/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('ADV_CRM');
    session_start();
}

function crm_currentUser(): ?array {
    crm_sessionStart();
    if (empty($_SESSION['uid'])) return null;
    // 2FA gate: if pending, treat as not-logged-in
    if (!empty($_SESSION['totp_required'])) return null;
    static $cache = null;
    if ($cache && $cache['id'] === $_SESSION['uid']) return $cache;
    $stmt = crm_db()->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['uid']]);
    $row = $stmt->fetch();
    $cache = $row ?: null;
    if (!$row) crm_logout();
    return $cache;
}

function crm_requireRole(array $roles): array {
    $user = crm_requireLogin();
    if (!in_array($user['role'] ?? 'sales', $roles, true)) {
        http_response_code(403);
        exit('Forbidden — your role does not permit this page.');
    }
    return $user;
}

// Leads-only role. The users.role ENUM ships with an unused 'operator' value
// (schema-v5); we reuse it as the restricted "leads only" role so no DB
// migration is needed. Such a user can ONLY see/edit leads — no clients, cold
// outbound, sequences, reports, settings, etc. Enforced per-page (founder/sales
// guards already exclude it) and at the update.php write chokepoint.
const CRM_ROLE_LEADS = 'operator';

function crm_isLeads(?array $user): bool {
    return ($user['role'] ?? '') === CRM_ROLE_LEADS;
}

function crm_requireLogin(): array {
    $user = crm_currentUser();
    if (!$user) {
        header('Location: /crm/');
        exit;
    }
    return $user;
}

function crm_attemptLogin(string $username, string $password): bool {
    $username = trim($username);
    if ($username === '' || $password === '') return false;

    $stmt = crm_db()->prepare('SELECT id, password_hash, totp_enabled FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        crm_log("auth_fail user={$username} ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        if (!$row) password_verify($password, '$2y$10$abcdefghijklmnopqrstuv');
        return false;
    }

    crm_sessionStart();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));

    if (!empty($row['totp_enabled'])) {
        $_SESSION['totp_required'] = true;
        crm_log("auth_pending_2fa uid={$row['id']} ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    } else {
        crm_log("auth_ok uid={$row['id']} ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }
    return true;
}

function crm_isTotpPending(): bool {
    crm_sessionStart();
    return !empty($_SESSION['totp_required']) && !empty($_SESSION['uid']);
}

function crm_verifyTotpStep(string $code): bool {
    crm_sessionStart();
    if (empty($_SESSION['uid']) || empty($_SESSION['totp_required'])) return false;
    require_once __DIR__ . '/totp.php';
    $stmt = crm_db()->prepare('SELECT totp_secret FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['uid']]);
    $row = $stmt->fetch();
    $secret = (string)($row['totp_secret'] ?? '');
    if ($secret === '') return false;
    if (!crm_totpVerify($secret, $code)) {
        crm_log("totp_fail uid={$_SESSION['uid']} ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        return false;
    }
    unset($_SESSION['totp_required']);
    crm_log("auth_ok_2fa uid={$_SESSION['uid']} ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    return true;
}

function crm_logout(): void {
    crm_sessionStart();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function crm_csrfToken(): string {
    crm_sessionStart();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function crm_csrfCheck(?string $token): bool {
    crm_sessionStart();
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], $token);
}

// Rate-limit logins by IP: 10 attempts / 10 minutes
function crm_loginRateOk(string $ip): bool {
    if ($ip === '') return true;
    $dir = '/home/advertonnet/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $path = $dir . '/crm-login-' . preg_replace('/[^a-zA-Z0-9]/', '_', $ip) . '.json';

    $now = time();
    $hits = [];
    if (is_readable($path)) {
        $raw = (array) json_decode((string) @file_get_contents($path), true);
        $hits = array_values(array_filter($raw, fn($t) => is_int($t) && $t >= $now - 600));
    }
    if (count($hits) >= 10) return false;
    $hits[] = $now;
    @file_put_contents($path, json_encode($hits), LOCK_EX);
    return true;
}
