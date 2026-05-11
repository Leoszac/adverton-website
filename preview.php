<?php
// Public preview — magic-link gated. Renders the same HTML the operator
// sees in /crm/client-review.php, so the client can review on their phone
// and click "approve" before we deploy to their hosting.
//
// /preview/{id}?t=TOKEN  (rewritten by .htaccess from /preview.php?id=…)

declare(strict_types=1);

define('CRM_ENTRY', 1);
require_once __DIR__ . '/crm/lib/db.php';
require_once __DIR__ . '/crm/lib/clients.php';
require_once __DIR__ . '/crm/lib/intake.php';
require_once __DIR__ . '/crm/lib/magic-tokens.php';
require_once __DIR__ . '/crm/lib/preview.php';

function previewError(int $status, string $title, string $msg): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Adverton</title>
<style>
  body{margin:0;font-family:-apple-system,Segoe UI,sans-serif;background:#faf9ff;color:#0e0d12;display:grid;place-items:center;min-height:100vh;padding:20px}
  .card{max-width:480px;background:#fff;padding:32px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.06);text-align:center}
  h1{margin:0 0 8px;font-size:22px}
  p{color:#6b6877;font-size:15px;line-height:1.5;margin:0 0 14px}
  a{color:#6d28d9}
</style></head><body>
  <div class="card">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <p><a href="mailto:hello@adverton.net">hello@adverton.net</a></p>
  </div></body></html><?php
    exit;
}

$clientId = (int)($_GET['id'] ?? 0);
$token    = trim((string)($_GET['t'] ?? ''));
$page     = (string)($_GET['page'] ?? 'home');
$allowedPages = ['home', 'about', 'services', 'service-area', 'contact'];
if (!in_array($page, $allowedPages, true)) $page = 'home';

if ($clientId <= 0) {
    previewError(400, 'Missing preview id',
        'The preview link is incomplete. Please use the link from your email exactly.');
}

// Token must resolve to THIS client. Lookup-by-token is more robust than
// "just trust the id query param" because the token IS the auth.
$resolved = crm_resolveMagicToken($token);
if (!$resolved || $resolved['kind'] !== 'client' || $resolved['id'] !== $clientId) {
    previewError(410, 'Preview link expired or invalid',
        "This preview link doesn't work. Links expire after 14 days. We'll send a fresh one.");
}

$res = crm_renderPreviewHtml($clientId, $page);
if (!$res['ok']) {
    previewError(500, 'Preview not ready yet',
        'We are still drafting your site. We will email you when it is ready to review.');
}

// Rewrite template nav URLs (which point to /about.html etc. — the post-
// deploy URLs) to preview-scoped URLs so the nav works on adverton.net.
// /         → /preview/{id}?t=TOKEN
// /xyz.html → /preview/{id}/xyz?t=TOKEN
$qs = '?t=' . urlencode($token);
$html = $res['html'];
$html = preg_replace_callback(
    '#href="/([a-z0-9-]+)\.html"#i',
    fn($m) => 'href="/preview/' . $clientId . '/' . $m[1] . $qs . '"',
    $html
);
$html = str_replace('href="/"', 'href="/preview/' . $clientId . $qs . '"', $html);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');   // never index previews
header('Cache-Control: no-store');           // never cache (drafts change)
echo $html;
