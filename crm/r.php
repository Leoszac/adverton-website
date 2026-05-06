<?php
// Public click redirector. Validates the click_token exists in email_sends
// before redirecting — prevents abuse as an open-redirect for phishing.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/email_track.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$url   = (string)($_GET['u'] ?? '');

$parts = parse_url($url);
$urlOk = isset($parts['scheme']) && isset($parts['host'])
      && in_array(strtolower($parts['scheme']), ['http','https'], true);

if (!$urlOk || strlen($token) !== 32) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Invalid link.");
}

// Token MUST exist in email_sends before we redirect (prevents open-redirect abuse)
try {
    $stmt = crm_db()->prepare('SELECT 1 FROM email_sends WHERE click_token = ? LIMIT 1');
    $stmt->execute([$token]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Invalid link.");
    }
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Invalid link.");
}

crm_recordClick(
    $token,
    $url,
    (string)($_SERVER['REMOTE_ADDR']    ?? ''),
    (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
);
header('Location: ' . $url, true, 302);
