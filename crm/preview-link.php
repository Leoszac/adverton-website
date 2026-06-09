<?php
// Operator-only: generate a shareable client-preview link (magic token) that
// Leo/VAs can COPY and send themselves — does NOT email the client.
// /crm/preview-link.php?id=N

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/magic-tokens.php';

$user = crm_requireRole(['founder', 'sales']);

$id = (int)($_GET['id'] ?? 0);
$client = $id > 0 ? crm_getClient($id) : null;
if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }

$tok = crm_setClientMagicToken($id, 30);
$url = 'https://adverton.net/preview/' . $id . '?t=' . $tok;
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Preview link — <?= $h($client['business_name']) ?></title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#faf9ff;color:#0e0d12;max-width:680px;margin:0 auto;padding:40px 20px}
  h1{font-size:20px;margin:0 0 4px}
  .box{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:20px;margin-top:16px}
  input{width:100%;padding:11px 12px;border:1px solid #e7e4ee;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:ui-monospace,monospace}
  button{margin-top:12px;background:#6d28d9;color:#fff;border:0;padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .muted{color:#6b6877;font-size:13px;margin-top:12px;line-height:1.5}
  a{color:#6d28d9}
</style></head><body>
<h1>🔗 Preview link for <?= $h($client['business_name']) ?></h1>
<p class="muted" style="margin-top:0">Copy this and send it yourself — it does <strong>not</strong> notify the client.</p>
<div class="box">
  <input id="u" readonly value="<?= $h($url) ?>" onclick="this.select()">
  <button id="cp" onclick="navigator.clipboard.writeText(document.getElementById('u').value).then(()=>{var b=document.getElementById('cp');b.textContent='✓ Copied!';setTimeout(()=>b.textContent='Copy link',1600)})">Copy link</button>
  <p class="muted">Valid 30 days. Anyone with the link can view the draft on any device (no login needed). Opening it again here generates a fresh link.</p>
</div>
<p style="margin-top:24px"><a href="/crm/client-review.php?id=<?= $id ?>">&larr; Back to review</a></p>
</body></html>
