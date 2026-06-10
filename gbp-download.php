<?php
// Signed download endpoint for the "Get Found on Google" (GBP) ebook.
// URL: /gbp-download.php?e=<base64-email>&s=<hmac-sig>
// PDF lives outside public_html at /home/advertonnet/private/GBP_Playbook.pdf.

declare(strict_types=1);

const PDF_PATH     = '/home/advertonnet/private/GBP_Playbook.pdf';
const PDF_FILENAME = 'Adverton_Google_Business_Profile_Guide.pdf';

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

function denyDownload(string $reason): void {
    error_log("[adverton-gbp-download] $reason");
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Link expired — Adverton</title>'
       . '<style>body{font-family:-apple-system,Helvetica,Arial,sans-serif;max-width:480px;margin:80px auto;padding:0 20px;color:#0e0d12;line-height:1.55;}'
       . 'h1{font-size:26px;margin-bottom:12px;}p{color:#383640;}a{color:#6d28d9;font-weight:600;}</style></head>'
       . '<body><h1>That link expired or is invalid.</h1>'
       . '<p>Download links for the Google Business Profile guide expire monthly to keep the file private. '
       . 'Request a fresh copy at <a href="/gbp-guide.html">adverton.net/gbp-guide</a> — '
       . 'we\'ll re-send the link to your inbox in seconds.</p></body></html>';
    exit;
}

function logDownload(array $row): void {
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . json_encode($row) . "\n";
    $logPath = '/home/advertonnet/logs/gbp-ebook-download.log';
    if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0750, true);
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

$e64 = trim((string)($_GET['e'] ?? ''));
$sig = trim((string)($_GET['s'] ?? ''));
if ($e64 === '' || $sig === '') denyDownload('missing params');

$padded = $e64 . str_repeat('=', (4 - strlen($e64) % 4) % 4);
$email = base64_decode(strtr($padded, '-_', '+/'), true);
if ($email === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    denyDownload('bad email decode');
}

// Verify HMAC against email|gbp|YYYY-MM (current month, then previous).
$salt = config('UNSUBSCRIBE_SALT') ?: 'adverton-default-salt';
$emailNorm = strtolower(trim($email));
$valid = false;
foreach ([0, -1] as $monthOffset) {
    $month = gmdate('Y-m', strtotime($monthOffset === 0 ? 'now' : "$monthOffset month"));
    $expected = substr(hash_hmac('sha256', $emailNorm . '|gbp|' . $month, $salt), 0, 32);
    if (hash_equals($expected, $sig)) { $valid = true; break; }
}
if (!$valid) denyDownload("signature mismatch for $email");

if (!is_readable(PDF_PATH)) {
    error_log('[adverton-gbp-download] PDF not found at ' . PDF_PATH);
    http_response_code(503);
    echo 'The guide is being updated. Please try again in a few minutes.';
    exit;
}

logDownload(['email' => $emailNorm, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
             'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), 'size' => filesize(PDF_PATH)]);

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize(PDF_PATH));
header('Content-Disposition: inline; filename="' . PDF_FILENAME . '"');
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');
readfile(PDF_PATH);
exit;
