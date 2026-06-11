<?php
// Adverton Care — agency provisioning console. Lives in /crm/ so the CRM login
// session (cookie scoped to /crm/) authenticates it. One click to set up a
// client: assign a Care number, wire webhooks, set forward-to, mint the
// contractor's passwordless dashboard link.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/../care/lib/provision.php';

$user = crm_requireRole(['founder', 'sales']);

function care_ah(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$result = null; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('crm_csrfCheck')) { crm_csrfCheck(); }
    $clientId = (int)($_POST['client_id'] ?? 0);
    $fwd      = (string)($_POST['forward_to'] ?? '');
    $area     = trim((string)($_POST['area_code'] ?? '')) ?: null;
    if ($clientId > 0 && $fwd !== '') {
        $result = care_provisionClient($clientId, $fwd, $area);
        if (!$result['ok']) $err = (string)$result['error'];
    } else {
        $err = 'Pick a client and enter the forward-to number.';
    }
}

$db = care_db();
$clients = $db->query("SELECT id, business_name, status FROM clients WHERE status IN ('onboarding','active','renewed') ORDER BY business_name ASC")->fetchAll();
$provisioned = [];
foreach ($db->query('SELECT n.client_id, n.twilio_number, n.forward_to, a.token FROM care_numbers n LEFT JOIN care_access a ON a.client_id=n.client_id WHERE n.active=1')->fetchAll() as $p) {
    $provisioned[(int)$p['client_id']] = $p;
}
$csrfField = function_exists('crm_csrfField') ? crm_csrfField() : (function_exists('crm_csrfToken') ? '<input type="hidden" name="csrf" value="' . care_ah((string)crm_csrfToken()) . '">' : '');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Care — Provisioning</title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f1f5f4;color:#0f2e2a;margin:0;padding:0 16px 60px}
  .wrap{max-width:680px;margin:0 auto}
  h1{color:#0d9488;font-size:22px;margin:22px 0 4px}.sub{color:#5b7771;font-size:14px;margin-bottom:20px}
  .card{background:#fff;border:1px solid #e2ebe9;border-radius:14px;padding:20px;margin-bottom:18px}
  label{display:block;font-weight:700;font-size:14px;margin:12px 0 6px}
  input,select{width:100%;padding:11px;border:1px solid #cfdedb;border-radius:10px;font:inherit;font-size:16px}
  button{margin-top:16px;background:#0d9488;color:#fff;border:none;font-weight:800;padding:13px 22px;border-radius:10px;font-size:15px;cursor:pointer}
  .err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:10px;padding:12px;margin:12px 0;font-weight:600}
  .ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:10px;padding:16px;margin:12px 0}
  .ok code{background:#fff;padding:3px 8px;border-radius:6px;font-size:13px;word-break:break-all}
  table{width:100%;border-collapse:collapse;font-size:14px}td,th{text-align:left;padding:8px;border-bottom:1px solid #e2ebe9}
  .pill{font-size:11px;background:#eef2f1;color:#5b7771;padding:2px 8px;border-radius:999px}
  a{color:#0d9488}
</style></head>
<body><div class="wrap">
  <h1>Care — Provisioning</h1>
  <div class="sub">Set up a contractor: assign a number, forward to their cell, get their dashboard link. <?php if (care_twilioStub()): ?><span class="pill">STUB MODE — Twilio not connected (fake numbers)</span><?php endif; ?></div>

  <?php if ($err): ?><div class="err"><?= care_ah($err) ?></div><?php endif; ?>
  <?php if ($result && $result['ok']): ?>
    <div class="ok">
      <strong>✓ <?= isset($result['reused']) ? 'Updated' : 'Provisioned' ?>.</strong><br>
      Care number: <code><?= care_ah($result['number']) ?></code> → forwards to <code><?= care_ah($result['forward_to']) ?></code><br>
      Put the Care number on their Google / website / ads.<br><br>
      <strong>Contractor dashboard link (send them this):</strong><br>
      <code><?= care_ah($result['dashboard']) ?></code>
      <?php if (!empty($result['stub'])): ?><br><span class="pill">stub number — re-provision once Twilio is live</span><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="post">
      <?= $csrfField ?>
      <label>Client</label>
      <select name="client_id" required>
        <option value="">— pick a contractor —</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= care_ah((string)$c['business_name']) ?> (#<?= (int)$c['id'] ?>)<?= isset($provisioned[(int)$c['id']]) ? ' — already set up' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <label>Forward calls to (their cell)</label>
      <input name="forward_to" placeholder="(555) 123-4567" required>
      <label>Area code for the new number (optional)</label>
      <input name="area_code" placeholder="e.g. 305" maxlength="3">
      <button type="submit">Provision Care number</button>
    </form>
  </div>

  <div class="card">
    <strong>Already provisioned</strong>
    <table><tr><th>Client</th><th>Care #</th><th>Forwards to</th><th>Dashboard</th></tr>
    <?php foreach ($provisioned as $cid => $p): ?>
      <tr><td>#<?= (int)$cid ?></td><td><?= care_ah((string)$p['twilio_number']) ?></td><td><?= care_ah((string)$p['forward_to']) ?></td>
      <td><?php if (!empty($p['token'])): ?><a href="<?= care_ah(CARE_BASE_URL . '/?t=' . $p['token']) ?>" target="_blank">open</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$provisioned): ?><tr><td colspan="4" style="color:#5b7771">None yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div></body></html>
