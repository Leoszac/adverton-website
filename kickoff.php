<?php
// Client-facing kickoff wizard — magic link, no CRM login.
// /kickoff?t=TOKEN[&step=N]
//
// Self-handles GET (render) and POST (save + advance). On final-step submit
// flips the intake status to ready_for_ai and shows the thank-you page.

declare(strict_types=1);

define('CRM_ENTRY', 1);
require_once __DIR__ . '/crm/lib/db.php';
require_once __DIR__ . '/crm/lib/clients.php';
require_once __DIR__ . '/crm/lib/intake.php';
require_once __DIR__ . '/crm/lib/intake-wizard.php';
require_once __DIR__ . '/crm/lib/magic-tokens.php';
require_once __DIR__ . '/crm/lib/activities.php';

function kickoffError(int $status, string $title, string $msg): void {
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
    <p>Need help? <a href="mailto:hello@adverton.net">hello@adverton.net</a></p>
  </div>
</body></html><?php
    exit;
}

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$resolved = crm_resolveMagicToken($token);
if (!$resolved || $resolved['kind'] !== 'client') {
    kickoffError(410, 'Link expired or invalid',
        "This kickoff link doesn't work. Links expire after 14 days. We'll send a fresh one.");
}
$clientId = $resolved['id'];
$client   = crm_getClient($clientId);
if (!$client) {
    kickoffError(404, 'Not found', "We couldn't find your record. Reach out and we'll fix it.");
}

crm_ensureIntake($clientId);
$intake = crm_getIntake($clientId);

// ─── POST: save the current step's data, advance, redirect ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = (int)($_POST['step'] ?? 1);
    $step = max(1, min(CRM_INTAKE_TOTAL_STEPS, $step));

    $payload = crm_intakeNormalizePost($_POST, $step);
    crm_saveIntakeStep($clientId, $step, $payload);

    if ($step === CRM_INTAKE_TOTAL_STEPS) {
        // Final submit
        $r = crm_markIntakeReady($clientId);
        if (!$r['ok']) {
            // Send them back to step 1 with a guidance flash
            $first = $r['missing'][0] ?? 'something';
            header('Location: /kickoff?t=' . urlencode($token) . '&step=1&err=' . urlencode("Almost there — please complete {$first}."));
            exit;
        }
        crm_logActivity(null, null, 'system', 'kickoff_completed',
            'Client completed kickoff intake (client #' . $clientId . ')');
        // One-time invalidation so the link isn't reusable
        crm_invalidateMagicToken('client', $clientId);
        header('Location: /kickoff-thank-you.html');
        exit;
    }

    header('Location: /kickoff?t=' . urlencode($token) . '&step=' . ($step + 1));
    exit;
}

// ─── GET: render current step ─────────────────────────────────────────
$step = (int)($_GET['step'] ?? ($intake['current_step'] ?? 1));
$step = max(1, min(CRM_INTAKE_TOTAL_STEPS, $step));
$err  = trim((string)($_GET['err'] ?? ''));

intake_renderShellOpen($client, $intake, $step, 'magic');
if ($err) echo '<div class="err">' . intake_h($err) . '</div>';
intake_renderStep($step, $intake, '/kickoff.php', $token, 'magic');
intake_renderShellClose();
