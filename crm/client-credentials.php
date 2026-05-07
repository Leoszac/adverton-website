<?php
// Operator UI for the encrypted credentials vault. Lists what's on file
// for a client, plus an "Add credential" form. Reveal-on-click for the
// encrypted value (one-shot, audit-logged). Founder/sales only.
//
// /crm/client-credentials.php?id=N

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/credentials.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']);

$clientId = (int)($_GET['id'] ?? 0);
$client = $clientId > 0 ? crm_getClient($clientId) : null;
if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }

// Reveal flow: ?reveal=N → fetch, audit log, render with value visible.
$reveal = (int)($_GET['reveal'] ?? 0);
$revealed = null;
if ($reveal > 0) {
    $r = crm_revealCredential($reveal, (int)$user['id']);
    if ($r['ok'] && (int)($r['row']['client_id'] ?? 0) === $clientId) {
        $revealed = $r;
    }
}

$creds = crm_listCredentials($clientId);
$saved = ($_GET['saved'] ?? '') === '1';

crm_renderHead('Credentials · ' . ($client['business_name'] ?? 'Client'));
crm_renderHeader($user, '');
?>
<style>
  main{max-width:960px}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  .card h2{margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  table.creds{width:100%;border-collapse:collapse;font-size:13px}
  table.creds th{text-align:left;padding:8px;color:#6b6877;font-weight:600;border-bottom:1px solid #e7e4ee;text-transform:uppercase;font-size:11px;letter-spacing:.06em}
  table.creds td{padding:10px 8px;border-bottom:1px solid #f3f1f8;vertical-align:top}
  table.creds .kind{font-weight:600;color:#0e0d12}
  table.creds .v{font-family:ui-monospace,monospace;font-size:12px}
  .has-val{display:inline-block;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
  .no-val{display:inline-block;background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600}
  label{display:block;font-size:12px;font-weight:600;color:#383640;margin:14px 0 6px}
  input,select,textarea{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}
  textarea{min-height:60px}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  button.primary{background:#6d28d9;color:#fff;border:0;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
  button.danger{background:#fee2e2;color:#991b1b;border:0;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer}
  .reveal-card{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-bottom:14px;font-size:14px}
  .reveal-card pre{font-family:ui-monospace,monospace;background:#fff;padding:10px;border-radius:6px;border:1px solid #fde68a;margin:6px 0 0;overflow-x:auto;font-size:13px}
  .ok{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
</style>
<main>
  <a href="/crm/client.php?id=<?= $clientId ?>" style="color:#6b6877;text-decoration:none;font-size:13px">‹ Back to <?= crm_h($client['business_name'] ?? 'client') ?></a>
  <h1 style="margin:8px 0 16px;font-size:20px">Credentials vault · <?= crm_h($client['business_name'] ?? 'Client') ?></h1>

  <?php if ($saved): ?><div class="ok">Saved. The value is now encrypted at rest.</div><?php endif; ?>

  <?php if ($revealed): ?>
    <div class="reveal-card">
      <strong>Revealed (audit-logged):</strong>
      <?= crm_h($revealed['row']['kind']) ?><?= !empty($revealed['row']['label']) ? ' · ' . crm_h($revealed['row']['label']) : '' ?>
      <pre><?= crm_h($revealed['value'] ?? '(no value stored)') ?></pre>
      <div style="font-size:12px;color:#92400e;margin-top:8px">This view will not persist. Reload the page and the value disappears.</div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>On file (<?= count($creds) ?>)</h2>
    <?php if (!$creds): ?>
      <p style="color:#6b6877;font-size:13px">No credentials yet.</p>
    <?php else: ?>
      <table class="creds">
        <thead><tr><th>Kind</th><th>Label</th><th>URL</th><th>Username</th><th>Value</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($creds as $c): ?>
            <tr>
              <td class="kind"><?= crm_h(str_replace('_',' ',$c['kind'])) ?></td>
              <td><?= crm_h((string)($c['label'] ?? '')) ?></td>
              <td class="v"><?= crm_h((string)($c['url'] ?? '')) ?></td>
              <td class="v"><?= crm_h((string)($c['username'] ?? '')) ?></td>
              <td>
                <?php if (!empty($c['has_value'])): ?>
                  <span class="has-val">●●●●●●</span>
                  <a href="?id=<?= $clientId ?>&reveal=<?= (int)$c['id'] ?>" style="font-size:11px;margin-left:6px;color:#6d28d9">reveal</a>
                <?php else: ?>
                  <span class="no-val">no value</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" action="/crm/update.php" style="margin:0;display:inline" onsubmit="return confirm('Delete this credential? This is logged.')">
                  <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
                  <input type="hidden" name="mode" value="credential_delete">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="client_id" value="<?= $clientId ?>">
                  <button type="submit" class="danger" title="Delete">✕</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <form class="card" method="post" action="/crm/update.php">
    <h2>Add credential</h2>
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
    <input type="hidden" name="mode" value="credential_save">
    <input type="hidden" name="client_id" value="<?= $clientId ?>">

    <div class="row2">
      <div>
        <label>Kind</label>
        <select name="kind" required>
          <?php foreach (CRM_CRED_KIND_LIST as $k): ?>
            <option value="<?= crm_h($k) ?>"><?= crm_h(str_replace('_',' ',$k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Label <span style="color:#6b6877;font-weight:400">(optional)</span></label>
        <input type="text" name="label" maxlength="120" placeholder="e.g. 'Main cPanel' or 'Namecheap acct'">
      </div>
    </div>
    <div class="row2">
      <div>
        <label>URL / host</label>
        <input type="text" name="url" maxlength="255" placeholder="ftp.example.com or https://site.com">
      </div>
      <div>
        <label>Username</label>
        <input type="text" name="username" maxlength="160" autocomplete="off">
      </div>
    </div>
    <label>Value (password / API key) — encrypted at rest</label>
    <input type="text" name="value" maxlength="1024" autocomplete="off" placeholder="will be AES-256 encrypted on save">
    <label>Notes <span style="color:#6b6877;font-weight:400">(optional, NOT encrypted)</span></label>
    <textarea name="notes" maxlength="2000" placeholder="MFA backup codes, account recovery email, etc."></textarea>

    <button type="submit" class="primary" style="margin-top:14px">Save (encrypted)</button>
  </form>

  <div class="card" style="background:#f7f6fb">
    <h2>Master key sanity check</h2>
    <?php
      try {
          $rawKey = crm_credMasterKey();
          echo '<p style="color:#166534;font-size:13px;margin:0">✓ CREDENTIALS_KEY is configured (' . strlen($rawKey) . ' bytes).</p>';
      } catch (Throwable $e) {
          echo '<p style="color:#991b1b;font-size:13px;margin:0">✗ ' . crm_h($e->getMessage()) . '. Add it to <code>crm-config.php</code> before saving credentials.</p>';
      }
    ?>
  </div>
</main>
</body></html>
<?php
