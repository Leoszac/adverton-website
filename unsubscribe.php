<?php
// Unsubscribe handler. Linked from the footer of every audit email.
// CAN-SPAM compliant: 1-click opt-out, no login required.

declare(strict_types=1);

define('AUDIT_ENTRY', 1);

require_once __DIR__ . '/lib/audit-email.php';

// Inline config loader (mirror of audit.php) — needed for UNSUBSCRIBE_SALT.
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

$e64 = trim((string)($_GET['e'] ?? ''));
$sig = trim((string)($_GET['s'] ?? ''));

$email = ($e64 && $sig) ? verifyUnsubscribeToken($e64, $sig) : null;
$status = 'invalid';

if ($email) {
    $optOutPath = '/home2/advertonnet/opt-outs.txt';
    $dir = dirname($optOutPath);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = strtolower(trim($email)) . "\t" . gmdate('Y-m-d\TH:i:s\Z') . "\n";
    @file_put_contents($optOutPath, $line, FILE_APPEND | LOCK_EX);
    $status = 'ok';
}

http_response_code($status === 'ok' ? 200 : 400);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unsubscribed — Adverton</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="stylesheet" href="styles.css">
</head>
<body>
<header class="nav">
  <div class="wrap nav-inner">
    <a href="/" class="nav-logo"><img src="assets/adverton-logo.png" alt="Adverton"></a>
  </div>
</header>

<section class="hero" style="text-align:center; min-height: 60vh; display:flex; align-items:center; justify-content:center;">
  <div class="wrap" style="max-width: 560px;">
    <?php if ($status === 'ok'): ?>
      <div style="font-size: 64px; line-height:1; margin-bottom: 20px;">✓</div>
      <h1 style="margin-bottom: 16px;">You're unsubscribed.</h1>
      <p class="lede" style="margin-bottom: 24px;">We won't send <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong> any more marketing emails from Adverton.</p>
      <p style="color: var(--ink-3); font-size: 14px; margin-bottom: 32px;">If you change your mind or this was a mistake, just reply to any past email and we'll add you back.</p>
    <?php else: ?>
      <div style="font-size: 64px; line-height:1; margin-bottom: 20px;">✗</div>
      <h1 style="margin-bottom: 16px;">That link looks invalid.</h1>
      <p class="lede" style="margin-bottom: 24px;">The unsubscribe link is missing or has been tampered with. Please reply to any of our emails with the word <strong>UNSUBSCRIBE</strong> and we'll handle it manually.</p>
    <?php endif; ?>
    <a href="/" class="btn btn-secondary">← Back to homepage</a>
  </div>
</section>

<footer class="footer">
  <div class="wrap footer-bottom" style="text-align:center; padding: 28px 0;">© 2026 MDS LLC · Delaware, USA · adverton.net</div>
</footer>
</body>
</html>
