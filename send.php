<?php
// Adverton contact form handler
// Receives POST from /index.html#contact and industry pages, sends to hello@adverton.net

declare(strict_types=1);

const RECIPIENT     = 'hello@adverton.net';
const SENDER_DOMAIN = 'adverton.net';
const RECAPTCHA_SECRET = '6LdFktQsAAAAAP0h44NT50T83DQZgpvlFqvxUexu'; // server-side
const RECAPTCHA_MIN_SCORE = 0.3;
const REDIRECT_OK   = '/thank-you.html';
const REDIRECT_FAIL = '/?form=error';

function bail(int $code, string $msg): void {
    http_response_code($code);
    error_log("[adverton-form] HTTP $code: $msg");
    header('Location: ' . REDIRECT_FAIL);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bail(405, 'Method not allowed');
}

// Honeypot — bots fill this hidden field
if (!empty($_POST['website'] ?? '')) {
    // Pretend success (don't tip off bots), but don't actually send
    header('Location: ' . REDIRECT_OK);
    exit;
}

// Required fields
$required = ['first_name', 'last_name', 'email', 'phone', 'business_name', 'trade', 'revenue', 'message'];
foreach ($required as $field) {
    if (empty(trim($_POST[$field] ?? ''))) {
        bail(400, "Missing field: $field");
    }
}

$email = trim($_POST['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bail(400, "Invalid email: $email");
}

// Optional reCAPTCHA validation (skipped if no token sent — still works without JS)
if (!empty($_POST['g-recaptcha-response'] ?? '')) {
    $token  = $_POST['g-recaptcha-response'];
    $verify = file_get_contents(
        'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode(RECAPTCHA_SECRET) .
        '&response=' . urlencode($token) .
        '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? '')
    );
    $result = json_decode($verify, true);
    if (!($result['success'] ?? false) || (($result['score'] ?? 0) < RECAPTCHA_MIN_SCORE)) {
        bail(403, 'reCAPTCHA failed: ' . json_encode($result));
    }
}

// Sanitize all input for the email body
$d = [];
foreach ($_POST as $k => $v) {
    if (is_string($v)) {
        $d[$k] = trim(preg_replace('/[\r\n]+/', ' ', $v));
    }
}

// Build email
$body  = "New contact form submission from adverton.net\n";
$body .= str_repeat('-', 60) . "\n\n";
$body .= "Name:       {$d['first_name']} {$d['last_name']}\n";
$body .= "Email:      {$d['email']}\n";
$body .= "Phone:      {$d['phone']}\n";
$body .= "Business:   {$d['business_name']}\n";
$body .= "Trade:      {$d['trade']}\n";
$body .= "Revenue:    {$d['revenue']}\n\n";
$body .= "Message:\n{$d['message']}\n\n";
$body .= str_repeat('-', 60) . "\n";
$body .= "Submitted:  " . date('Y-m-d H:i:s') . " UTC\n";
$body .= "Source IP:  " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
$body .= "Referrer:   " . ($_SERVER['HTTP_REFERER'] ?? 'unknown') . "\n";
$body .= "User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . "\n";

$subject = "New lead — {$d['business_name']} ({$d['trade']})";

$headers  = "From: Adverton Website <noreply@" . SENDER_DOMAIN . ">\r\n";
$headers .= "Reply-To: {$d['first_name']} {$d['last_name']} <{$d['email']}>\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: Adverton/1.0\r\n";

$ok = mail(RECIPIENT, $subject, $body, $headers);

if (!$ok) {
    bail(500, 'mail() returned false');
}

// Auto-responder to the prospect (best-effort — don't bail if it fails)
$ar_subject = "We got your message — Adverton";
$ar_body  = "Hi {$d['first_name']},\n\n";
$ar_body .= "Thanks for reaching out to Adverton. We received your message and one of our team will get back to you within 24 business hours with next steps.\n\n";
$ar_body .= "What happens next:\n";
$ar_body .= "  1. We review your business and current marketing\n";
$ar_body .= "  2. We send a custom proposal if there's a fit\n";
$ar_body .= "  3. We schedule a 15-minute call at a time that works for you\n\n";
$ar_body .= "If your situation is urgent, reply to this email or call us directly.\n\n";
$ar_body .= "— The Adverton team\n";
$ar_body .= "hello@adverton.net  ·  https://adverton.net\n";
$ar_headers  = "From: Adverton <hello@" . SENDER_DOMAIN . ">\r\n";
$ar_headers .= "Reply-To: hello@" . SENDER_DOMAIN . "\r\n";
$ar_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$ar_headers .= "X-Mailer: Adverton/1.0\r\n";
@mail($d['email'], $ar_subject, $ar_body, $ar_headers);

header('Location: ' . REDIRECT_OK);
exit;
