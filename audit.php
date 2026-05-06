<?php
// Adverton GBP audit handler. Receives POST from /audit.html.
// Two paths:
//   - automated: user pasted a GBP URL → resolve → score → email
//   - manual:    user clicked "I can't find my link" → defer to Leandro

declare(strict_types=1);

define('AUDIT_ENTRY', 1);

require_once __DIR__ . '/lib/places-api.php';
require_once __DIR__ . '/lib/gbp-resolver.php';
require_once __DIR__ . '/lib/audit-scorer.php';
require_once __DIR__ . '/lib/audit-email.php';

// CRM persistence (best-effort: never breaks lead capture if DB is down).
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
@require_once __DIR__ . '/crm/lib/db.php';
@require_once __DIR__ . '/crm/lib/leads.php';

// ------- Config loader -------

function loadAuditConfig(): array {
    $candidates = [
        '/home2/advertonnet/audit-config.php',
        dirname(__DIR__) . '/audit-config.php',
        __DIR__ . '/audit-config.php',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) {
            $cfg = include $p;
            if (is_array($cfg)) return $cfg;
        }
    }
    return [];
}
$AUDIT_CONFIG = loadAuditConfig();
function config(string $key): ?string {
    global $AUDIT_CONFIG;
    return isset($AUDIT_CONFIG[$key]) ? (string)$AUDIT_CONFIG[$key] : null;
}

// ------- Routing -------

const REDIRECT_OK_AUTO   = '/audit-thank-you.html';
const REDIRECT_OK_MANUAL = '/audit-thank-you.html?path=manual';
const REDIRECT_FAIL      = '/audit.html?error=';

function bail(int $code, string $devMsg, string $userKey = 'generic'): void {
    http_response_code($code);
    error_log("[adverton-audit] HTTP $code: $devMsg");
    header('Location: ' . REDIRECT_FAIL . urlencode($userKey));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bail(405, 'method not allowed', 'method');
}

// Honeypot
if (!empty($_POST['website_url_hp'] ?? '')) {
    header('Location: ' . REDIRECT_OK_AUTO);
    exit;
}

// Rate limit: 5 audits / IP / 10 minutes. Cheap protection against burning
// the Google Places API quota or Resend send credits.
if (!checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '', 5, 600)) {
    bail(429, 'rate limit exceeded for IP ' . ($_SERVER['REMOTE_ADDR'] ?? ''), 'rate_limit');
}

// Common required fields
$required = ['first_name', 'last_name', 'email', 'phone', 'trade'];
foreach ($required as $f) {
    if (empty(trim((string)($_POST[$f] ?? '')))) {
        bail(400, "missing: $f", 'missing_field');
    }
}

$email = trim((string)$_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bail(400, "bad email: $email", 'bad_email');
}

$phoneDigits = preg_replace('/\D/', '', (string)$_POST['phone']);
if (strlen($phoneDigits) < 10) {
    bail(400, "bad phone: {$_POST['phone']}", 'bad_phone');
}

if (empty($_POST['consent'] ?? '')) {
    bail(400, 'consent not checked', 'consent');
}

// reCAPTCHA (best-effort — same threshold as send.php)
$secret = config('RECAPTCHA_SECRET');
if ($secret && !empty($_POST['g-recaptcha-response'] ?? '')) {
    $token = (string)$_POST['g-recaptcha-response'];
    $verify = @file_get_contents(
        'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secret) .
        '&response=' . urlencode($token) .
        '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? '')
    );
    $result = json_decode((string)$verify, true);
    if (!($result['success'] ?? false) || (($result['score'] ?? 0) < 0.3)) {
        bail(403, 'recaptcha failed: ' . json_encode($result), 'recaptcha');
    }
}

// Sanitize form values for downstream use
$form = [];
foreach (['first_name', 'last_name', 'email', 'phone', 'trade', 'gbp_url', 'business_name', 'city_state', 'website'] as $k) {
    $v = (string)($_POST[$k] ?? '');
    $form[$k] = trim(preg_replace('/[\r\n]+/', ' ', $v));
}

$auditId = bin2hex(random_bytes(8));
$isManual = !empty($_POST['manual_audit'] ?? '');

logAudit([
    'audit_id'   => $auditId,
    'manual'     => $isManual ? 1 : 0,
    'email'      => $form['email'],
    'gbp_url'    => $form['gbp_url'] ?? '',
    'trade'      => $form['trade'],
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
]);

// ------- Manual path -------

if ($isManual) {
    if (empty($form['business_name']) || empty($form['city_state'])) {
        bail(400, 'manual path missing business_name or city_state', 'missing_field');
    }
    @sendManualPendingEmail($form);
    @notifyNewLead($form, null, $auditId, true);
    if (function_exists('crm_insertLead')) {
        @crm_insertLead([
            'source'        => 'audit_manual',
            'source_page'   => $_SERVER['HTTP_REFERER'] ?? null,
            'first_name'    => $form['first_name'],
            'last_name'     => $form['last_name'],
            'email'         => $form['email'],
            'phone'         => $form['phone'],
            'business_name' => $form['business_name'],
            'trade'         => $form['trade'],
            'city_state'    => $form['city_state'],
            'website'       => $form['website'],
            'audit_id'      => $auditId,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'utm_source'    => $_POST['utm_source']   ?? null,
            'utm_medium'    => $_POST['utm_medium']   ?? null,
            'utm_campaign'  => $_POST['utm_campaign'] ?? null,
        ]);
    }
    header('Location: ' . REDIRECT_OK_MANUAL);
    exit;
}

// ------- Automated path -------

if (empty($form['gbp_url'])) {
    bail(400, 'missing gbp_url', 'missing_url');
}

try {
    $resolved = resolveGbpUrl($form['gbp_url']);
} catch (GbpResolverException $e) {
    error_log('[adverton-audit] resolver: ' . $e->getMessage());
    header('Location: ' . REDIRECT_FAIL . urlencode($e->kind));
    exit;
}

try {
    $place = placesDetails($resolved['place_id']);
} catch (Throwable $e) {
    error_log('[adverton-audit] places api: ' . $e->getMessage());
    bail(502, 'places api error', 'api_error');
}

$auditResult = scoreAudit($place, $form['trade']);
$auditResult['business_name']  = $place['displayName']['text'] ?? $resolved['matched_name'];
$auditResult['google_maps_uri'] = $place['googleMapsUri'] ?? '';

// Fire emails (best-effort: we still redirect to thank-you even if one fails)
@sendAuditEmail($form, $auditResult, $auditId);
@notifyNewLead($form, $auditResult, $auditId, false);

if (function_exists('crm_insertLead')) {
    @crm_insertLead([
        'source'        => 'audit_auto',
        'source_page'   => $_SERVER['HTTP_REFERER'] ?? null,
        'first_name'    => $form['first_name'],
        'last_name'     => $form['last_name'],
        'email'         => $form['email'],
        'phone'         => $form['phone'],
        'business_name' => $auditResult['business_name'] ?? $form['business_name'],
        'trade'         => $form['trade'],
        'city_state'    => $form['city_state'],
        'website'       => $form['website'],
        'gbp_url'       => $form['gbp_url'],
        'audit_score'   => $auditResult['score'] ?? null,
        'audit_id'      => $auditId,
        'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'utm_source'    => $_POST['utm_source']   ?? null,
        'utm_medium'    => $_POST['utm_medium']   ?? null,
        'utm_campaign'  => $_POST['utm_campaign'] ?? null,
    ]);
}

header('Location: ' . REDIRECT_OK_AUTO);
exit;

// ------- Local helpers -------

function logAudit(array $row): void {
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . json_encode($row) . "\n";
    $logPath = '/home2/advertonnet/logs/audit.log';
    $dir = dirname($logPath);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

function checkRateLimit(string $ip, int $maxRequests, int $windowSec): bool {
    if ($ip === '') return true;
    $stateDir = '/home2/advertonnet/ratelimit';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);
    $path = $stateDir . '/audit-' . preg_replace('/[^a-zA-Z0-9]/', '_', $ip) . '.json';

    $now = time();
    $hits = [];
    if (is_readable($path)) {
        $raw = (array) json_decode((string) @file_get_contents($path), true);
        $hits = array_values(array_filter($raw, fn($t) => is_int($t) && $t >= $now - $windowSec));
    }
    if (count($hits) >= $maxRequests) return false;
    $hits[] = $now;
    @file_put_contents($path, json_encode($hits), LOCK_EX);
    return true;
}
