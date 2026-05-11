<?php
// Public endpoint — receives contact form submissions from deployed client
// websites (rendered by crm/web-templates/*.php).
//
// Flow:
//   1. Validate client_id exists + client is active
//   2. Honeypot check (bot field)
//   3. Insert into client_form_submissions
//   4. Email the client (their billing/primary email) + ops mirror
//   5. Return small HTML thank-you OR redirect back to source_url with ?sent=1
//
// CORS: returns Access-Control-Allow-Origin: <Origin> so the form can POST
// from any domain the client's site is hosted on.

declare(strict_types=1);
define('CRM_ENTRY', 1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';

// Direct Resend send (no per-lead tracking — these aren't lead-attributed).
function client_form_send_email(string $to, string $subject, string $bodyHtml, ?string $replyTo = null): void {
    $apiKey = crm_config('RESEND_API_KEY');
    if (!$apiKey || !$to) return;
    $from = crm_config('CRM_FROM_ADDRESS') ?: 'Adverton <hello@adverton.net>';
    $payload = [
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:-apple-system,Segoe UI,sans-serif;color:#0e0d12;line-height:1.55">' . $bodyHtml . '</body></html>',
    ];
    if ($replyTo) $payload['reply_to'] = $replyTo;
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// ─── CORS ─────────────────────────────────────────────────────────────
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Method not allowed');
}

// ─── Validate ─────────────────────────────────────────────────────────
$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
if ($clientId <= 0) {
    http_response_code(400);
    exit('Missing client_id');
}
$client = crm_getClient($clientId);
if (!$client || ($client['status'] ?? '') !== 'active') {
    // Don't leak whether the client exists
    http_response_code(400);
    exit('Invalid request');
}

// Honeypot — real users don't fill hidden fields
if (!empty($_POST['hp'] ?? '')) {
    http_response_code(204);
    exit;
}

$name    = mb_substr(trim((string)($_POST['name']    ?? '')), 0, 160);
$email   = mb_substr(trim((string)($_POST['email']   ?? '')), 0, 160);
$phone   = mb_substr(trim((string)($_POST['phone']   ?? '')), 0, 40);
$message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 4000);

if ($name === '' && $email === '' && $phone === '') {
    http_response_code(400);
    exit('Name, email, or phone required');
}

$sourceUrl = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
$ip        = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua        = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

// ─── Persist ──────────────────────────────────────────────────────────
try {
    $stmt = crm_db()->prepare(
        'INSERT INTO client_form_submissions
            (client_id, name, email, phone, message, source_url, ip, ua)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$clientId, $name, $email, $phone, $message, $sourceUrl, $ip, $ua]);
    $submissionId = (int) crm_db()->lastInsertId();
} catch (Throwable $e) {
    error_log('[client-form-submit] persist: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}

// ─── Notify client ────────────────────────────────────────────────────
$bizName    = (string)($client['business_name'] ?? 'your business');
$clientMail = (string)($client['billing_email'] ?: $client['primary_email'] ?: '');

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$bodyLines = [];
if ($name)    $bodyLines[] = '<strong>Name:</strong> ' . $h($name);
if ($email)   $bodyLines[] = '<strong>Email:</strong> <a href="mailto:' . $h($email) . '">' . $h($email) . '</a>';
if ($phone)   $bodyLines[] = '<strong>Phone:</strong> <a href="tel:' . $h($phone) . '">' . $h($phone) . '</a>';
if ($message) $bodyLines[] = '<strong>Message:</strong><br>' . nl2br($h($message));
if ($sourceUrl) $bodyLines[] = '<small style="color:#888">From: ' . $h($sourceUrl) . '</small>';
$bodyHtml = '<p>You got a new lead from your website:</p><p>' . implode('<br><br>', $bodyLines) . '</p>';

if ($clientMail) {
    client_form_send_email(
        $clientMail,
        "New lead from your website — " . ($name ?: $email ?: $phone),
        $bodyHtml,
        $email ?: null
    );
}

// Mirror to ops so we can spot delivery issues
client_form_send_email(
    'hello@adverton.net',
    "[FORM] {$bizName} — new website lead #{$submissionId}",
    $bodyHtml
);

// ─── Respond ──────────────────────────────────────────────────────────
// If the form posted with redirect=1, bounce back; otherwise show a small
// thank-you page (works inside an iframe / doesn't require client-side JS).
if (!empty($_POST['redirect'] ?? '') && $sourceUrl !== '') {
    $sep = (strpos($sourceUrl, '?') === false) ? '?' : '&';
    header('Location: ' . $sourceUrl . $sep . 'sent=1');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Thanks — <?= $h($bizName) ?></title>
<style>
  body{margin:0;font-family:-apple-system,Segoe UI,sans-serif;background:#faf9ff;color:#0e0d12;display:grid;place-items:center;min-height:100vh;padding:20px}
  .card{max-width:440px;background:#fff;padding:32px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.06);text-align:center}
  h1{margin:0 0 8px;font-size:22px}
  p{color:#6b6877;font-size:15px;line-height:1.5;margin:0 0 14px}
  a{color:#6d28d9}
</style></head><body>
<div class="card">
  <h1>Thanks!</h1>
  <p><?= $h($bizName) ?> got your message and will be in touch shortly.</p>
  <?php if ($sourceUrl): ?><p><a href="<?= $h($sourceUrl) ?>">← Back</a></p><?php endif; ?>
</div>
</body></html>
