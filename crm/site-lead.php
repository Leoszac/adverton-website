<?php
// Public contact-form receiver for client websites (preview + deployed).
// The rendered template posts here; we email the submission to the client
// (the contractor) so it lands in their inbox.
//
//   <form method="post" action="https://adverton.net/crm/site-lead.php?c=N">
//
// Hardening: honeypot field 'company', no auth (public endpoint), minimal
// validation, best-effort send + CRM event log.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

// Per-IP file rate limiter (mirrors audit.php/ebook-request.php pattern).
function siteLeadRateLimit(string $ip, int $max, int $window): bool {
    if ($ip === '') return true;
    $dir = '/home/advertonnet/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $path = $dir . '/sitelead-' . preg_replace('/[^a-zA-Z0-9]/', '_', $ip) . '.json';
    $now = time();
    $hits = [];
    if (is_readable($path)) {
        $raw  = (array) json_decode((string) @file_get_contents($path), true);
        $hits = array_values(array_filter($raw, static fn($t) => is_int($t) && $t >= $now - $window));
    }
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    @file_put_contents($path, json_encode($hits), LOCK_EX);
    return true;
}

function siteLeadDone(string $msg, bool $ok = true): void {
    http_response_code($ok ? 200 : 400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . ($ok ? 'Thank you' : 'Try again') . '</title>';
    echo '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:hsl(215,70%,18%);color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;text-align:center;padding:24px}div{max-width:460px}h1{font-size:26px;margin:0 0 10px}p{font-size:16px;line-height:1.5;color:#dbe5f0}</style>';
    echo '<div><h1>' . ($ok ? 'Thanks! 🙌' : 'Hmm…') . '</h1><p>' . htmlspecialchars($msg) . '</p></div>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    siteLeadDone('Please submit the form from the website.', false);
}

// Honeypot — bots fill the hidden 'company' field; accept silently and drop.
if (trim((string)($_POST['company'] ?? '')) !== '') {
    siteLeadDone('Thanks! We got your request.');
}

// Per-IP rate limit: 8 submits / 10 min (anti-spam / Resend cost abuse).
if (!siteLeadRateLimit((string)($_SERVER['REMOTE_ADDR'] ?? ''), 8, 600)) {
    siteLeadDone('Too many requests — please wait a few minutes and try again.', false);
}

$clientId = (int)($_GET['c'] ?? $_POST['c'] ?? 0);
$client   = $clientId > 0 ? crm_getClient($clientId) : null;
if (!$client) {
    siteLeadDone('We could not route your message. Please call us directly.', false);
}

$name  = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$city  = trim((string)($_POST['city'] ?? ''));
$msg   = trim((string)($_POST['message'] ?? ''));
if ($name === '' && $phone === '') {
    siteLeadDone('Please add at least your name and phone so we can reach you.', false);
}

$to  = (string)($client['primary_email'] ?: $client['billing_email'] ?: '');
$biz = (string)($client['business_name'] ?? 'your website');

$bodyHtml = '<h2 style="font-family:sans-serif">New lead from your website</h2>'
    . '<table style="font-family:sans-serif;font-size:15px;border-collapse:collapse">'
    . '<tr><td style="padding:4px 12px 4px 0;color:#666">Name</td><td><strong>' . $h($name ?: '—') . '</strong></td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#666">Phone</td><td><strong>' . $h($phone ?: '—') . '</strong></td></tr>'
    . ($city !== '' ? '<tr><td style="padding:4px 12px 4px 0;color:#666">City</td><td>' . $h($city) . '</td></tr>' : '')
    . '</table>'
    . ($msg !== '' ? '<p style="font-family:sans-serif;font-size:15px"><strong>Message:</strong><br>' . nl2br($h($msg)) . '</p>' : '')
    . '<hr><p style="font-family:sans-serif;color:#999;font-size:12px">Sent automatically by your Adverton website (' . $h($biz) . '). Call or email them back to win the job.</p>';

if ($to !== '') {
    $apiKey = crm_config('RESEND_API_KEY');
    $from   = 'Adverton <no-reply@adverton.net>';   // lead notifications go from no-reply, never the founder's inbox
    if ($apiKey) {
        $payload = [
            'from'    => $from,
            'to'      => [$to],
            'subject' => 'New website lead: ' . ($name !== '' ? $name : '(no name)'),
            'html'    => $bodyHtml,
        ];
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            error_log("[site-lead] Resend failed for client {$clientId}: HTTP {$code}");
        }
    } else {
        error_log('[site-lead] RESEND_API_KEY not set');
    }
} else {
    error_log("[site-lead] client {$clientId} has no email on file");
}

if (function_exists('crm_logClientEvent')) {
    try {
        crm_logClientEvent($clientId, null, 'note',
            'Website lead: ' . $name . ' / ' . $phone . ($city !== '' ? ' / ' . $city : ''));
    } catch (Throwable $e) { /* best effort */ }
}

siteLeadDone($biz . ' got your request and will reach out shortly.');
