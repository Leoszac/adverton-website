<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$db = crm_db();

$msgPwd = ''; $errPwd = '';
$msgTotp = ''; $errTotp = '';

// --- Password change ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    if (!crm_csrfCheck($_POST['csrf'] ?? null)) { http_response_code(403); exit('CSRF'); }
    $cur  = (string)($_POST['current_password'] ?? '');
    $new  = (string)($_POST['new_password']     ?? '');
    $conf = (string)($_POST['confirm_password'] ?? '');

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($cur, (string)$row['password_hash'])) {
        $errPwd = 'Current password is wrong.';
        crm_log("pwd_change_fail uid={$user['id']}");
    } elseif (mb_strlen($new) < 10) {
        $errPwd = 'New password must be at least 10 characters.';
    } elseif ($new !== $conf) {
        $errPwd = 'New password and confirmation do not match.';
    } elseif ($new === $cur) {
        $errPwd = 'New password must differ from the current one.';
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, (int)$user['id']]);
        crm_log("pwd_change_ok uid={$user['id']}");
        $msgPwd = 'Password updated. Use the new one next login.';
    }
}

// --- 2FA flow (moved from 2fa-setup.php) ---
$stmt = $db->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
$stmt->execute([(int)$user['id']]);
$state = $stmt->fetch();
$enabled = !empty($state['totp_enabled']);
$pendingSecret = $_SESSION['pending_totp_secret'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'totp') {
    if (!crm_csrfCheck($_POST['csrf'] ?? null)) { http_response_code(403); exit('CSRF'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'start') {
        $secret = crm_totpGenerateSecret();
        $_SESSION['pending_totp_secret'] = $secret;
        $pendingSecret = $secret;
        $msgTotp = 'Scan the QR with Google Authenticator / 1Password / Authy, then enter a code.';
    } elseif ($action === 'verify') {
        $secret = (string)($_SESSION['pending_totp_secret'] ?? '');
        $code   = (string)($_POST['code'] ?? '');
        if ($secret && crm_totpVerify($secret, $code)) {
            $stmt = $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = TRUE WHERE id = ?');
            $stmt->execute([$secret, (int)$user['id']]);
            unset($_SESSION['pending_totp_secret']);
            $msgTotp = '2FA enabled. From next login you will need a 6-digit code.';
            $enabled = true; $pendingSecret = '';
        } else {
            $errTotp = 'Code did not match. Try again (codes refresh every 30s).';
        }
    } elseif ($action === 'disable') {
        $stmt = $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = FALSE WHERE id = ?');
        $stmt->execute([(int)$user['id']]);
        $enabled = false;
        $msgTotp = '2FA disabled.';
    }
}

crm_renderHead('Account');
crm_renderHeader($user, '');
?>
<style>
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  @media (max-width:880px){ .grid2{grid-template-columns:1fr} }
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;margin-bottom:14px}
  h1{margin:0 0 18px;font-size:22px}
  h2{margin:0 0 6px;font-size:18px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .err{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=password],input[type=text]{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:10px 12px;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}
  input.code{font-size:20px;letter-spacing:.2em;text-align:center;font-family:ui-monospace,monospace}
  button{margin-top:14px;background:#6d28d9;color:#fff;border:0;padding:11px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button.danger{background:#fee2e2;color:#991b1b}
  .qr{display:block;margin:10px auto;border:1px solid #e7e4ee;border-radius:8px;padding:6px;background:#fff}
  .secret{font-family:ui-monospace,monospace;background:#faf9ff;padding:6px 12px;border-radius:6px;letter-spacing:.05em;display:inline-block}
  .who{color:#6b6877;font-size:13px;margin-bottom:14px}
</style>
<main>
  <h1>Account · <?= crm_h($user['display_name']) ?></h1>
  <div class="who">Signed in as <strong><?= crm_h($user['username']) ?></strong> · role <strong><?= crm_h($user['role'] ?? 'sales') ?></strong></div>

  <div class="grid2">
    <div class="card">
      <h2>Change password</h2>
      <div class="sub">Use a strong password (10+ characters).</div>

      <?php if ($msgPwd): ?><div class="saved"><?= crm_h($msgPwd) ?></div><?php endif; ?>
      <?php if ($errPwd): ?><div class="err"><?= crm_h($errPwd) ?></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="form" value="password">
        <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

        <label>Current password</label>
        <input type="password" name="current_password" autocomplete="current-password" required>

        <label>New password</label>
        <input type="password" name="new_password" autocomplete="new-password" minlength="10" required>

        <label>Confirm new password</label>
        <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>

        <button type="submit">Update password</button>
      </form>
    </div>

    <div class="card">
      <h2>Two-factor authentication</h2>
      <div class="sub">Adds a 6-digit code at login from your authenticator app.</div>

      <?php if ($msgTotp): ?><div class="saved"><?= crm_h($msgTotp) ?></div><?php endif; ?>
      <?php if ($errTotp): ?><div class="err"><?= crm_h($errTotp) ?></div><?php endif; ?>

      <?php if ($enabled): ?>
        <p style="font-size:14px;color:#16a34a;font-weight:600">✓ 2FA is enabled.</p>
        <form method="post">
          <input type="hidden" name="form" value="totp">
          <input type="hidden" name="action" value="disable">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <button type="submit" class="danger" onclick="return confirm('Disable 2FA?')">Disable 2FA</button>
        </form>
      <?php elseif ($pendingSecret): ?>
        <?php $uri = crm_totpProvisioningUri($user['username'] ?? 'user', $pendingSecret); ?>
        <p>Scan with your authenticator:</p>
        <img class="qr" src="<?= crm_h(crm_totpQrUrl($uri)) ?>" alt="QR" width="200" height="200">
        <p style="text-align:center;font-size:13px;color:#6b6877">Or enter manually: <span class="secret"><?= crm_h($pendingSecret) ?></span></p>
        <form method="post" autocomplete="off">
          <input type="hidden" name="form" value="totp">
          <input type="hidden" name="action" value="verify">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <label>Enter the current 6-digit code</label>
          <input type="text" class="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required>
          <button type="submit">Enable 2FA</button>
        </form>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="form" value="totp">
          <input type="hidden" name="action" value="start">
          <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
          <button type="submit">Set up 2FA</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</main>
</body></html>
