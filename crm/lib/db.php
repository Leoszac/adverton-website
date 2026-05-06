<?php
// CRM DB layer — PDO MySQL connection + config loader.
// Real config lives at /home2/advertonnet/crm-config.php (chmod 600), OUTSIDE
// public_html. Never commit real credentials.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_loadConfig(): array {
    $candidates = [
        '/home2/advertonnet/crm-config.php',
        dirname(__DIR__, 3) . '/crm-config.php',
        __DIR__ . '/../crm-config.php',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) {
            $cfg = include $p;
            if (is_array($cfg)) return $cfg;
        }
    }
    error_log('[crm] no config file found in ' . implode(', ', $candidates));
    return [];
}

function crm_config(string $key, ?string $default = null): ?string {
    // DB-backed settings take precedence for whitelisted keys (managed via
    // /crm/integrations.php). DB credentials etc. always come from the file.
    if (defined('CRM_DB_BACKED_KEYS') === false) {
        // Lazy-load the whitelist once we have a DB connection candidate
        if (is_readable(__DIR__ . '/settings.php')) {
            require_once __DIR__ . '/settings.php';
        }
    }
    if (defined('CRM_DB_BACKED_KEYS') || (function_exists('crm_loadDbSettings'))) {
        if (function_exists('crm_loadDbSettings') && in_array($key, CRM_DB_BACKED_KEYS, true)) {
            $db = crm_loadDbSettings();
            if (isset($db[$key]) && $db[$key] !== '') return (string)$db[$key];
        }
    }
    static $cfg = null;
    if ($cfg === null) $cfg = crm_loadConfig();
    return isset($cfg[$key]) ? (string)$cfg[$key] : $default;
}

function crm_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // PHP-side timezone: all date()/strtotime() output in NY local
    if (!ini_get('date.timezone') || ini_get('date.timezone') !== 'America/New_York') {
        date_default_timezone_set('America/New_York');
    }

    $host = crm_config('DB_HOST', 'localhost');
    $name = crm_config('DB_NAME');
    $user = crm_config('DB_USER');
    $pass = crm_config('DB_PASS');
    if (!$name || !$user) {
        throw new RuntimeException('CRM config missing DB_NAME or DB_USER');
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // MySQL-side timezone: NOW(), CURDATE(), DATE_SUB() etc. now align to NY.
    // Hostingheroes' MySQL doesn't load named tz tables, so we use the offset
    // and update it daily-ish. -05:00 standard, -04:00 DST.
    $offset = crm_currentNyOffset();
    try { $pdo->exec("SET time_zone = '{$offset}'"); } catch (Throwable $e) { /* best-effort */ }

    return $pdo;
}

// Returns "-05:00" or "-04:00" depending on whether NY is in DST right now.
function crm_currentNyOffset(): string {
    $tz  = new DateTimeZone('America/New_York');
    $now = new DateTime('now', $tz);
    $sec = $tz->getOffset($now);            // e.g. -14400 in DST
    $sign = $sec < 0 ? '-' : '+';
    $abs  = abs($sec);
    $h = (int)($abs / 3600);
    $m = (int)(($abs % 3600) / 60);
    return sprintf('%s%02d:%02d', $sign, $h, $m);
}

function crm_log(string $line): void {
    $logPath = '/home2/advertonnet/logs/crm.log';
    $dir = dirname($logPath);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(
        $logPath,
        gmdate('Y-m-d\TH:i:s\Z') . ' ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function crm_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
