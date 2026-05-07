<?php
// Pre-contract form — public, magic-link gated.
// The lead receives an email with /pre-contract?t=TOKEN and fills in the
// billing/legal data we need to generate the PandaDoc service agreement.
//
// On GET: validate the token, render the form pre-filled with what we
// already know about the lead (name, email, phone, business_name).
// On POST: see pre-contract-submit.php (separate handler, mirrors the
// audit.php pattern of "form HTML → handler PHP").

declare(strict_types=1);

define('CRM_ENTRY', 1);
require_once __DIR__ . '/crm/lib/db.php';
require_once __DIR__ . '/crm/lib/magic-tokens.php';

$token = trim((string)($_GET['t'] ?? ''));
$resolved = crm_resolveMagicToken($token);

// Helper: render an error page and exit. Status 410 (Gone) for expired/used
// links so search engines + email-link previewers don't index/cache them.
function preContractError(int $status, string $title, string $msg): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Adverton</title>
<style>
  body{margin:0;font-family:-apple-system,Segoe UI,sans-serif;background:#faf9ff;color:#0e0d12;display:grid;place-items:center;min-height:100vh;padding:20px}
  .card{max-width:480px;background:#fff;padding:32px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,0.06);text-align:center}
  h1{margin:0 0 8px;font-size:22px}
  p{color:#6b6877;font-size:15px;line-height:1.5;margin:0 0 16px}
  a{color:#6d28d9}
</style></head><body>
  <div class="card">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <p>Need help? Email <a href="mailto:hello@adverton.net">hello@adverton.net</a> and we'll resend your link.</p>
  </div>
</body></html><?php
    exit;
}

if (!$resolved) {
    preContractError(410, 'Link expired or invalid',
        "This pre-contract link doesn't work. Links expire after 14 days or once used.");
}
if ($resolved['kind'] !== 'lead') {
    preContractError(410, 'Already submitted',
        'This form was already completed. Your contract should already be in your inbox.');
}

// Fetch the lead to pre-fill the form
try {
    $stmt = crm_db()->prepare(
        'SELECT id, first_name, last_name, email, phone, business_name, trade, city_state
         FROM leads WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$resolved['id']]);
    $lead = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[pre-contract.php fetch lead] ' . $e->getMessage());
    preContractError(500, 'Something went wrong',
        'We hit a technical issue. Please try again in a minute.');
}
if (!$lead) {
    preContractError(404, 'Lead not found',
        'We could not find your record. Reach out and we will sort it out.');
}

$signerSuggested = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
$flash = trim((string)($_GET['err'] ?? ''));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Pre-contract — Adverton</title>
<link rel="stylesheet" href="/styles.css?v=20260507">
<style>
  body{background:var(--purple-bg, #faf9ff)}
  main{max-width:640px;margin:0 auto;padding:32px 20px}
  .hdr{text-align:center;margin-bottom:28px}
  .hdr h1{margin:0 0 8px;font-size:26px;letter-spacing:-0.01em}
  .hdr p{color:var(--ink-3, #6b6877);font-size:15px;line-height:1.5;margin:0}
  .card{background:#fff;border:1px solid var(--line, #e7e4ee);border-radius:14px;padding:24px;margin-bottom:14px}
  label{display:block;font-size:13px;font-weight:600;color:var(--ink-2, #383640);margin:14px 0 6px}
  label .req{color:#dc2626}
  input[type=text],input[type=email],input[type=tel],select,textarea{
    width:100%;background:#fff;border:1px solid var(--line, #e7e4ee);color:#0e0d12;
    padding:11px 13px;border-radius:9px;font-size:16px;font-family:inherit;box-sizing:border-box;
  }
  input:focus,select:focus,textarea:focus{outline:none;border-color:#6d28d9;box-shadow:0 0 0 3px rgba(109,40,217,0.1)}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:480px){.row2{grid-template-columns:1fr}}
  .help{font-size:12px;color:var(--ink-3, #6b6877);margin-top:4px;line-height:1.4}
  button.primary{
    margin-top:22px;width:100%;background:#6d28d9;color:#fff;border:0;
    padding:14px;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;
  }
  button.primary:hover{background:#5b21b6}
  .flash-err{background:#fee2e2;color:#991b1b;padding:12px 14px;border-radius:9px;font-size:14px;margin-bottom:14px}
  .footer-note{text-align:center;color:var(--ink-3, #6b6877);font-size:12px;line-height:1.5;margin-top:14px}
</style>
</head>
<body>
<main>
  <div class="hdr">
    <h1>Hi <?= htmlspecialchars($lead['first_name'] ?? 'there') ?> — let's get the paperwork going</h1>
    <p>5 minutes to fill in. After this we'll send you the service agreement to sign and you're set.</p>
  </div>

  <?php if ($flash): ?>
    <div class="flash-err"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <form class="card" method="post" action="/pre-contract-submit.php" autocomplete="on">
    <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">

    <label>Legal business name <span class="req">*</span></label>
    <input type="text" name="legal_entity_name" required maxlength="255"
           value="<?= htmlspecialchars($lead['business_name'] ?? '') ?>">
    <div class="help">The exact name on your license/insurance — what should appear on the contract. If you're a sole proprietor, your full legal name.</div>

    <div class="row2">
      <div>
        <label>Authorized signer (full name) <span class="req">*</span></label>
        <input type="text" name="authorized_signer" required maxlength="160"
               value="<?= htmlspecialchars($signerSuggested) ?>">
      </div>
      <div>
        <label>Signer role / title</label>
        <input type="text" name="signer_role" maxlength="80" placeholder="Owner, President, etc.">
      </div>
    </div>

    <div class="row2">
      <div>
        <label>Billing email <span class="req">*</span></label>
        <input type="email" name="billing_email" required maxlength="160"
               value="<?= htmlspecialchars($lead['email'] ?? '') ?>">
        <div class="help">Where the invoice + receipts will go.</div>
      </div>
      <div>
        <label>Phone <span class="req">*</span></label>
        <input type="tel" name="primary_phone" required maxlength="40"
               value="<?= htmlspecialchars($lead['phone'] ?? '') ?>">
      </div>
    </div>

    <label>Billing address (street) <span class="req">*</span></label>
    <input type="text" name="billing_address" required maxlength="255" autocomplete="street-address">

    <div class="row2">
      <div>
        <label>City <span class="req">*</span></label>
        <input type="text" name="billing_city" required maxlength="80" autocomplete="address-level2">
      </div>
      <div>
        <label>State <span class="req">*</span></label>
        <input type="text" name="billing_state" required maxlength="40" autocomplete="address-level1">
      </div>
    </div>

    <div class="row2">
      <div>
        <label>ZIP / Postal code <span class="req">*</span></label>
        <input type="text" name="billing_zip" required maxlength="20" autocomplete="postal-code">
      </div>
      <div>
        <label>Tax ID / EIN <span style="color:var(--ink-3, #6b6877);font-weight:400">(optional)</span></label>
        <input type="text" name="tax_id" maxlength="40">
      </div>
    </div>

    <button type="submit" class="primary">Submit and send me the contract</button>
  </form>

  <p class="footer-note">By submitting, you confirm the legal entity above is authorized to enter a service agreement with Adverton (MDS LLC, Delaware). The contract will be sent to your billing email via PandaDoc for review and electronic signature.</p>
</main>
</body>
</html>
