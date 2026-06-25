<?php
// Auth-gated image streamer for client_assets.
//
// Two access paths:
//   1. CRM session (operator viewing client.php asset grid)
//   2. Magic-link client preview (the rendered website embeds asset URLs)
//
// /crm/asset.php?id=N            — operator (login required)
// /crm/asset.php?id=N&t=TOKEN    — magic-link path (token must resolve to
//                                  the SAME client that owns the asset)

declare(strict_types=1);

define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/photos.php';
require_once __DIR__ . '/lib/magic-tokens.php';

$id = (int)($_GET['id'] ?? 0);
$asset = $id > 0 ? crm_getAsset($id) : null;
if (!$asset) { http_response_code(404); exit('not found'); }

// Auth: either logged-in CRM user, OR a magic token that resolves to the
// same client_id the asset belongs to.
$authed = false;
$token = trim((string)($_GET['t'] ?? ''));
if ($token !== '') {
    $r = crm_resolveMagicToken($token);
    if ($r && $r['kind'] === 'client' && $r['id'] === (int)$asset['client_id']) {
        $authed = true;
    }
}
if (!$authed) {
    require_once __DIR__ . '/lib/auth.php';
    $user = crm_currentUser();
    // Client assets are not visible to the leads-only role.
    if (!$user || crm_isLeads($user)) { http_response_code(401); exit('not authorized'); }
    $authed = true;
}

if (!is_readable($asset['disk_path'])) {
    http_response_code(404); exit('file missing');
}

$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
$ascii = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string)$asset['original_name']);
$utf8  = rawurlencode((string)$asset['original_name']);

header('Content-Type: ' . $asset['mime']);
header('Content-Length: ' . filesize($asset['disk_path']));
header("Content-Disposition: {$disposition}; filename=\"{$ascii}\"; filename*=UTF-8''{$utf8}");
header('X-Content-Type-Options: nosniff');
// Allow short caching for inline embeds — assets rarely change once classified.
header('Cache-Control: private, max-age=300');
readfile($asset['disk_path']);
