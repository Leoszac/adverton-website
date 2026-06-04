<?php
// Operator-only preview. Renders the same HTML as /preview/{id}?t=TOKEN
// but gated by CRM session (founder/sales role) instead of magic token,
// so the operator can iterate on a draft without notifying the client.
//
// The public /preview/ route (preview.php at site root) is unchanged.
//
//   /crm/preview-internal.php?id={id}&page={page}

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/intake.php';
require_once __DIR__ . '/lib/preview.php';

$user = crm_requireRole(['founder','sales']);

$clientId = (int)($_GET['id'] ?? 0);
$page     = (string)($_GET['page'] ?? 'home');
// Flat pages (all templates) + seo_local pages (locations/reviews) + the
// programmatic "service:<slug>" / "location:<slug>" keys.
$flatAllowed = ['home','about','services','service-area','contact','locations','reviews'];
$validPage = in_array($page, $flatAllowed, true)
    || preg_match('#^(service|location):[a-z0-9-]+$#', $page) === 1;
if (!$validPage) $page = 'home';

if ($clientId <= 0) {
    http_response_code(400);
    exit('Missing client id.');
}

$res = crm_renderPreviewHtml($clientId, $page);
if (!$res['ok']) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#faf9ff;padding:40px;color:#0e0d12}</style>';
    echo '<h2>Preview not ready</h2><p>' . htmlspecialchars((string)$res['error']) . '</p>';
    echo '<p><a href="/crm/client-review.php?id=' . $clientId . '" style="color:#6d28d9">← Back to review</a></p>';
    exit;
}

// Rewrite template nav URLs to internal-preview-scoped URLs so iframe nav
// stays inside the operator preview (same pattern as preview.php does for
// the client-facing token route).
//   /         → /crm/preview-internal.php?id={id}
//   /xyz.html → /crm/preview-internal.php?id={id}&page=xyz
$base = '/crm/preview-internal.php?id=' . $clientId;
$html = $res['html'];
// Nested seo_local pages first: /services/x.html → page=service:x,
// /locations/y.html → page=location:y.
$html = preg_replace_callback(
    '#href="/(services|locations)/([a-z0-9-]+)\.html"#i',
    fn($m) => 'href="' . $base . '&page=' . (strtolower($m[1]) === 'services' ? 'service' : 'location') . ':' . $m[2] . '"',
    $html
);
// Flat pages: /xyz.html → page=xyz.
$html = preg_replace_callback(
    '#href="/([a-z0-9-]+)\.html"#i',
    fn($m) => 'href="' . $base . '&page=' . $m[1] . '"',
    $html
);
$html = str_replace('href="/"', 'href="' . $base . '"', $html);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
echo $html;
