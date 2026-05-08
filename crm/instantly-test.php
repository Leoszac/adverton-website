<?php
// Instantly API test endpoint — verify connection + show connected mailboxes
// with their warmup status. Auth-required (founder/sales).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/instantly.php';

$user = crm_requireLogin();

$apiKey  = crm_instantlyApiKey();
$test    = $apiKey ? crm_instantlyTestConnection() : null;
$accounts = $apiKey ? crm_instantlyListAccounts(50) : ['error'=>'no key', 'items'=>[]];
$campaigns = $apiKey ? crm_instantlyListCampaigns(20) : ['error'=>'no key', 'items'=>[]];

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Instantly — connection test</title>
<style>
body{font:14px/1.5 -apple-system,"Helvetica Neue",Arial,sans-serif;color:#0e0d12;background:#fafafa;margin:0;padding:32px}
.wrap{max-width:1100px;margin:0 auto}
h1{font-size:22px;margin:0 0 24px}
.card{background:#fff;border:1px solid #e7e4ee;border-radius:10px;padding:18px 22px;margin-bottom:18px}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600}
.ok{background:#d1fae5;color:#065f46}
.err{background:#fee2e2;color:#991b1b}
.warn{background:#fef3c7;color:#92400e}
.muted{color:#6b6877;font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px 10px;text-align:left;font-size:13px;border-bottom:1px solid #f0eef5}
th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b6877;font-weight:700}
.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
.back{display:inline-block;margin-bottom:16px;color:#6d28d9;text-decoration:none;font-weight:600;font-size:13px}
pre{background:#f5f5f5;padding:8px 12px;border-radius:5px;font-size:11px;overflow-x:auto;color:#444}
</style>
</head>
<body>
<div class="wrap">

  <a href="/crm/integrations.php" class="back">← Integrations</a>
  <h1>Instantly API — connection test</h1>

  <div class="card">
    <strong>API key:</strong>
    <?php if (!$apiKey): ?>
      <span class="badge err">not configured</span>
      <p class="muted">Paste your Instantly API key in <a href="/crm/integrations.php">Integrations</a> first, then refresh this page.</p>
    <?php else: ?>
      <span class="badge ok">configured</span>
      <span class="muted" style="margin-left:8px">key length: <?= strlen($apiKey) ?> chars</span>
    <?php endif; ?>
  </div>

  <?php if ($apiKey): ?>
    <div class="card">
      <strong>Connection test:</strong>
      <?php if ($test['ok']): ?>
        <span class="badge ok">✓ <?= crm_h($test['message']) ?></span>
        <span class="muted" style="margin-left:8px"><?= (int)$test['account_count'] ?> account(s) reachable</span>
      <?php else: ?>
        <span class="badge err">✗ failed</span>
        <pre><?= crm_h($test['message']) ?></pre>
        <p class="muted">If you just pasted the key, make sure you clicked "Save" on the integrations page. If the error mentions "401" or "unauthorized", regenerate the key in Instantly.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <strong>Connected mailboxes (<?= count($accounts['items']) ?>):</strong>
      <?php if ($accounts['error']): ?>
        <span class="badge err">error: <?= crm_h($accounts['error']) ?></span>
      <?php elseif (!$accounts['items']): ?>
        <p class="muted">No accounts returned by Instantly API.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Email</th>
              <th>Status</th>
              <th>Warmup</th>
              <th class="num">Daily limit</th>
              <th class="num">Sent today</th>
              <th class="num">Health</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accounts['items'] as $a): ?>
              <tr>
                <td><strong><?= crm_h($a['email'] ?? '?') ?></strong></td>
                <td>
                  <?php $st = (string)($a['status'] ?? '?'); ?>
                  <span class="badge <?= $st==='active'?'ok':($st==='paused'?'warn':'err') ?>"><?= crm_h($st) ?></span>
                </td>
                <td>
                  <?php
                    $w = $a['warmup'] ?? null;
                    $wstatus = is_array($w) ? ($w['status'] ?? '?') : ($w ?? '?');
                  ?>
                  <span class="badge <?= $wstatus==='active'?'ok':'warn' ?>"><?= crm_h((string)$wstatus) ?></span>
                </td>
                <td class="num"><?= crm_h((string)($a['daily_limit'] ?? $a['warmup']['warmup_limit'] ?? '—')) ?></td>
                <td class="num"><?= crm_h((string)($a['emails_sent_today'] ?? '—')) ?></td>
                <td class="num"><?= crm_h((string)($a['health_score'] ?? $a['warmup']['warmup_score'] ?? '—')) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <strong>Campaigns (<?= count($campaigns['items']) ?>):</strong>
      <?php if ($campaigns['error']): ?>
        <span class="badge err">error: <?= crm_h($campaigns['error']) ?></span>
      <?php elseif (!$campaigns['items']): ?>
        <p class="muted">No campaigns yet — that's expected during warmup. Campaigns will appear here once we start building cold-email sequences in Instantly.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Status</th>
              <th>ID</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($campaigns['items'] as $c): ?>
              <tr>
                <td><strong><?= crm_h($c['name'] ?? '?') ?></strong></td>
                <td><span class="badge <?= ($c['status']??'')==='running'?'ok':'warn' ?>"><?= crm_h((string)($c['status']??'?')) ?></span></td>
                <td class="muted"><code><?= crm_h((string)($c['id']??'?')) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
