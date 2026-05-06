<?php
// Per-user 2FA enrollment + disable.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$db = crm_db();

$msg = ''; $err = '';

// Pull current user state (auth.php only loads minimal columns)
$stmt = $db->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$state = $stmt->fetch();
$enabled = !empty($state['totp_enabled']);
$pendingSecret = $_SESSION['pending_totp_secret'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!crm_csrfCheck($_POST['csrf'] ?? null)) {
        http_response_code(403); exit('CSRF');
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'start') {
        $secret = crm_totpGenerateSecret();
        $_SESSION['pending_totp_secret'] = $secret;
        $pendingSecret = $secret;
        $msg = 'Scan the QR with Google Authenticator / 1Password / Authy, then enter a code below.';
    } elseif ($action === 'verify') {
        $secret = (string)($_SESSION['pending_totp_secret'] ?? '');
        $code   = (string)($_POST['code'] ?? '');
        if ($secret && crm_totpVerify($secret, $code)) {
            $stmt = $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?');
            $stmt->execute([$secret, (int)$user['id']]);
            unset($_SESSION['pending_totp_secret']);
            $msg = '2FA enabled. From next login, you will need a 6-digit code.';
            $enabled = true; $pendingSecret = '';
        } else {
            $err = 'Code did not match. Try again (the code refreshes every 30s).';
        }
    } elseif ($action === 'disable') {
        $stmt = $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = FALSE WHERE id = ?');
        $stmt->execute([(int)$user['id']]);
        $enabled = false;
        $msg = '2FA disabled.';
    }
}

crm_renderHead('2FA Setup');
crm_renderHeader($user, '');
?>
<style>
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;max-width:520px;margin:0 auto}
  h1{margin:0 0 6px;font-size:22px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .err{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  label{display:block;font-size:12px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:18px;letter-spacing:.2em;text-align:center;font-family:ui-monospace,monospace;box-sizing:border-box}
  button{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:11px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button.danger{background:#fee2e2;color:#991b1b}
  .qr{display:block;margin:10px auto;border:1px solid #e7e4ee;border-radius:8px;padding:6px;background:#fff}
  .secret{font-family:ui-monospace,monospace;background:#faf9ff;padding:6px 12px;border-radius:6px;letter-spacing:.05em;text-align:center;display:inline-block}
</style>
<main>
  <div class="card">
    <h1>Two-factor authentication</h1>
    <div class="sub">Adds a second step at login: a 6-digit code from your authenticator app.</div>

    <?php if ($msg): ?><div class="saved"><?= crm_h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?= crm_h($err) ?></div><?php endif; ?>

    <?php if ($enabled): ?>
      <p style="font-size:14px;color:#16a34a;font-weight:600">✓ 2FA is enabled on this account.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <input type="hidden" name="action" value="disable">
        <button type="submit" class="danger" onclick="return confirm('Disable 2FA?')">Disable 2FA</button>
      </form>
    <?php elseif ($pendingSecret): ?>
      <?php $uri = crm_totpProvisioningUri($user['username'] ?? 'user', $pendingSecret); ?>
      <p>Scan with Google Authenticator / 1Password / Authy:</p>
      <img class="qr" src="<?= crm_h(crm_totpQrUrl($uri)) ?>" alt="QR" width="200" height="200">
      <p style="text-align:center;font-size:13px;color:#6b6877">Or enter manually: <span class="secret"><?= crm_h($pendingSecret) ?></span></p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <input type="hidden" name="action" value="verify">
        <label>Enter the current 6-digit code</label>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus required>
        <button type="submit">Enable 2FA</button>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
        <input type="hidden" name="action" value="start">
        <button type="submit">Set up 2FA</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body></html>
