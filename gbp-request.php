<?php
// Handler for the "Get Found on Google" (GBP) ebook download form.
// Posted from /gbp-guide.html. On success: persists lead to CRM (source
// ebook_gbp), emails a signed download link, redirects to thank-you.

declare(strict_types=1);

define('AUDIT_ENTRY', 1);

require_once __DIR__ . '/lib/audit-email.php';  // renderEmailShell, sendEmail, htmlEsc, stripHtml

if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
@require_once __DIR__ . '/crm/lib/db.php';
@require_once __DIR__ . '/crm/lib/leads.php';

function loadAuditConfig(): array {
    $candidates = [
        '/home/advertonnet/audit-config.php',
        dirname(__DIR__) . '/audit-config.php',
        __DIR__ . '/audit-config.php',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) { $cfg = include $p; if (is_array($cfg)) return $cfg; }
    }
    return [];
}
$AUDIT_CONFIG = loadAuditConfig();
function config(string $key): ?string {
    global $AUDIT_CONFIG;
    return isset($AUDIT_CONFIG[$key]) ? (string)$AUDIT_CONFIG[$key] : null;
}

const REDIRECT_OK   = '/gbp-guide-thank-you.html';
const REDIRECT_FAIL = '/gbp-guide.html?error=';

function bail(int $code, string $devMsg, string $userKey = 'generic'): void {
    http_response_code($code);
    error_log("[adverton-gbp] HTTP $code: $devMsg");
    header('Location: ' . REDIRECT_FAIL . urlencode($userKey));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bail(405, 'method not allowed', 'method');
}

// Honeypot
if (!empty($_POST['website_url_hp'] ?? '')) {
    header('Location: ' . REDIRECT_OK);
    exit;
}

// Rate limit: 5 requests / IP / 10 minutes
if (!gbpRateLimit($_SERVER['REMOTE_ADDR'] ?? '', 5, 600)) {
    bail(429, 'rate limit exceeded for IP ' . ($_SERVER['REMOTE_ADDR'] ?? ''), 'rate_limit');
}

$required = ['first_name', 'last_name', 'email', 'trade'];
foreach ($required as $f) {
    if (empty(trim((string)($_POST[$f] ?? '')))) {
        bail(400, "missing: $f", 'missing_field');
    }
}

$email = trim((string)$_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bail(400, "bad email: $email", 'bad_email');
}
if (empty($_POST['consent'] ?? '')) {
    bail(400, 'consent not checked', 'consent');
}

$form = [];
foreach (['first_name', 'last_name', 'email', 'phone', 'trade', 'business_name'] as $k) {
    $form[$k] = trim(preg_replace('/[\r\n]+/', ' ', (string)($_POST[$k] ?? '')));
}

$requestId = bin2hex(random_bytes(8));

logGbpRequest([
    'request_id' => $requestId,
    'email'      => $form['email'],
    'trade'      => $form['trade'],
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
]);

@sendGbpDeliveryEmail($form, $requestId);
@notifyGbpLead($form, $requestId);

if (function_exists('crm_insertLead')) {
    @crm_insertLead([
        'source'        => 'ebook_gbp',
        'source_page'   => $_SERVER['HTTP_REFERER'] ?? null,
        'first_name'    => $form['first_name'],
        'last_name'     => $form['last_name'],
        'email'         => $form['email'],
        'phone'         => $form['phone'],
        'business_name' => $form['business_name'],
        'trade'         => $form['trade'],
        'audit_id'      => $requestId,
        'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'utm_source'    => $_POST['utm_source']   ?? null,
        'utm_medium'    => $_POST['utm_medium']   ?? null,
        'utm_campaign'  => $_POST['utm_campaign'] ?? null,
    ]);
}

header('Location: ' . REDIRECT_OK);
exit;

// ------- Email -------

function sendGbpDeliveryEmail(array $form, string $requestId): bool {
    $first = htmlEsc($form['first_name'] ?? 'there');
    $downloadUrl = buildGbpDownloadUrl($form['email']);

    $body  = "<p style='font-size:16px;color:#0e0d12;margin:0 0 14px;'>Hi {$first},</p>";
    $body .= "<p style='color:#383640;line-height:1.6;margin:0 0 18px;'>Here's your copy of <strong>Get Found on Google</strong> — the contractor's guide to a Google Business Profile that actually books jobs.</p>";
    $body .= "<table role='presentation' cellpadding='0' cellspacing='0' style='width:100%;margin:0 0 24px;'><tr><td align='center'>"
           . "<a href='" . htmlEsc($downloadUrl) . "' style='display:inline-block;background:#6d28d9;color:#fff;padding:14px 32px;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;box-shadow:0 6px 18px rgba(109,40,217,0.3);'>Download the guide →</a>"
           . "</td></tr></table>";
    $body .= "<p style='color:#383640;line-height:1.6;font-size:14px;margin:0 0 12px;'>Inside you'll find:</p>";
    $body .= "<ul style='color:#383640;line-height:1.7;font-size:14px;padding-left:20px;margin:0 0 24px;'>"
           . "<li>How to set up and verify your profile the right way</li>"
           . "<li>The optimization levers that move you up the map pack</li>"
           . "<li>How to build a steady stream of 5-star reviews — manual and automated</li>"
           . "<li>A 15-minute weekly routine and a one-page game plan</li>"
           . "</ul>";
    $body .= "<p style='color:#383640;line-height:1.6;font-size:14px;margin:0 0 12px;'>Want to see exactly where you rank against the contractors in your town? <strong>Run a free GBP audit</strong>: <a href=\"https://adverton.net/audit\" style='color:#6d28d9;text-decoration:underline;font-weight:600;'>adverton.net/audit</a>.</p>";
    $body .= "<p style='color:#0e0d12;margin:24px 0 0;'>— Leo from Adverton<br><span style='color:#6b6877;font-size:13px;'>The marketing team for U.S. home service contractors</span></p>";

    $html = renderEmailShell("Your Google Business Profile playbook", $body, $form['email'], [
        'header_tag'    => 'GBP guide',
        'why_receiving' => "You're receiving this because you requested the Google Business Profile guide at adverton.net.",
    ]);
    return sendEmail($form['email'], "Your Google Business Profile playbook is here", $html, stripHtml($html));
}

function notifyGbpLead(array $form, string $requestId): bool {
    $recipient = config('LEAD_NOTIFICATION_EMAIL') ?: 'hello@adverton.net';
    $subject = "[GBP GUIDE] New download: {$form['first_name']} {$form['last_name']} — {$form['trade']}";
    $lines = [
        "Request ID:  {$requestId}",
        "Name:        {$form['first_name']} {$form['last_name']}",
        "Email:       {$form['email']}",
        "Phone:       " . ($form['phone'] ?: '—'),
        "Trade:       {$form['trade']}",
        "Business:    " . ($form['business_name'] ?: '—'),
        "",
        "Source:      ebook_gbp",
        "Submitted:   " . gmdate('Y-m-d H:i:s') . " UTC",
        "Source IP:   " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    ];
    $bodyText = implode("\n", $lines);
    $bodyHtml = "<pre style='font-family:ui-monospace,Menlo,monospace;font-size:13px;line-height:1.6;color:#0e0d12;white-space:pre-wrap;'>" . htmlEsc($bodyText) . "</pre>";
    return sendEmail($recipient, $subject, $bodyHtml, $bodyText);
}

// ------- Signed download URL (HMAC, magnet=gbp) -------

function buildGbpDownloadUrl(string $email): string {
    $salt = config('UNSUBSCRIBE_SALT') ?: 'adverton-default-salt';
    $payload = strtolower(trim($email)) . '|gbp|' . gmdate('Y-m');
    $sig = substr(hash_hmac('sha256', $payload, $salt), 0, 32);
    $e64 = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    return 'https://adverton.net/gbp-download.php?e=' . $e64 . '&s=' . $sig;
}

// ------- Helpers -------

function logGbpRequest(array $row): void {
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . json_encode($row) . "\n";
    $logPath = '/home/advertonnet/logs/gbp-ebook.log';
    if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0750, true);
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

function gbpRateLimit(string $ip, int $maxRequests, int $windowSec): bool {
    if ($ip === '') return true;
    $stateDir = '/home/advertonnet/ratelimit';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0750, true);
    $path = $stateDir . '/gbp-' . preg_replace('/[^a-zA-Z0-9]/', '_', $ip) . '.json';
    $now = time(); $hits = [];
    if (is_readable($path)) {
        $raw = (array) json_decode((string) @file_get_contents($path), true);
        $hits = array_values(array_filter($raw, fn($t) => is_int($t) && $t >= $now - $windowSec));
    }
    if (count($hits) >= $maxRequests) return false;
    $hits[] = $now;
    @file_put_contents($path, json_encode($hits), LOCK_EX);
    return true;
}
