<?php
// Operator review dashboard for a client's website draft.
// Split view: intake summary + AI generation controls on the left,
// live preview iframe on the right. Three-button workflow:
//
//   1. "Generate copy"     → calls Claude, populates ai_drafts_json
//   2. "Send preview link" → emails the client a magic-link preview
//   3. "Approve preview"   → flips status to 'approved' (gates deploy)
//
// Sprint 4 will add the actual Deploy button (needs credentials in vault).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/intake.php';
require_once __DIR__ . '/lib/preview.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/magic-tokens.php';

$user = crm_requireRole(['founder','sales']);

$clientId = (int)($_GET['id'] ?? 0);
$client   = $clientId > 0 ? crm_getClient($clientId) : null;
if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }

$intake = crm_getIntake($clientId);
$status = (string)($intake['status'] ?? 'not_started');

// Operator-only preview URL — no token, no client notification.
// Loaded by the iframe and "Open preview in new tab" button.
$internalPreviewUrl = '/crm/preview-internal.php?id=' . $clientId;

// Client-facing preview URL (magic-link). Only populated after operator
// clicks "Send preview link to client" — that action mints the token AND
// emails the client. Surfaced in the UI as confirmation of last send.
$clientPreviewToken = null;
$clientPreviewUrl   = null;
if (!empty($intake) && !empty($client['magic_token'])
    && !empty($client['magic_token_expires_at'])
    && strtotime($client['magic_token_expires_at']) > time()) {
    $clientPreviewToken = (string)$client['magic_token'];
    $clientPreviewUrl   = 'https://adverton.net/preview/' . $clientId . '?t=' . urlencode($clientPreviewToken);
}

$saved   = ($_GET['saved'] ?? '') === '1';
$msg     = trim((string)($_GET['msg']     ?? ''));   // success-side detail (e.g. test connection result)
$genErr  = trim((string)($_GET['genErr']  ?? ''));
$sendErr = trim((string)($_GET['sendErr'] ?? ''));

crm_renderHead('Review · ' . ($client['business_name'] ?? 'Client'));
crm_renderHeader($user, '');
?>
<style>
  main{max-width:1280px}
  .grid{display:grid;grid-template-columns:380px 1fr;gap:18px}
  @media(max-width:1024px){.grid{grid-template-columns:1fr}}
  .panel{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px}
  .panel h2{margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
  .b-not{background:#fee2e2;color:#991b1b}
  .b-prog{background:#fef3c7;color:#92400e}
  .b-ready{background:#dbeafe;color:#1e40af}
  .b-gen{background:#fae8ff;color:#6b21a8}
  .b-appr{background:#dcfce7;color:#166534}
  .row{display:grid;grid-template-columns:120px 1fr;gap:8px;font-size:13px;padding:6px 0;border-bottom:1px solid #f3f1f8}
  .row .k{color:#6b6877}
  .row .v{color:#0e0d12;word-break:break-word}
  .row:last-child{border-bottom:0}
  .actions{display:flex;flex-direction:column;gap:8px;margin-top:14px}
  .actions form,.actions button,.actions a{margin:0}
  .actions .btn,.actions button{width:100%;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;border:0;display:block;box-sizing:border-box}
  .btn-primary{background:#6d28d9;color:#fff}
  .btn-primary:disabled{background:#c7c2d6;cursor:not-allowed}
  .btn-secondary{background:#fff;color:#6d28d9;border:1px solid #6d28d9}
  .btn-success{background:#16a34a;color:#fff}
  .btn-disabled{background:#f3f1f8;color:#9ca3af;cursor:not-allowed;border:0}
  .err{background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .ok{background:#dcfce7;color:#166534;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:12px}
  .preview-frame{width:100%;height:80vh;min-height:600px;border:1px solid #e7e4ee;border-radius:12px;background:#fff}
  .preview-empty{display:flex;align-items:center;justify-content:center;text-align:center;color:#6b6877;font-size:14px;padding:60px 24px}
</style>
<main>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h1 style="margin:0;font-size:20px">
      <a href="/crm/client.php?id=<?= (int)$client['id'] ?>" style="color:#6b6877;text-decoration:none">‹</a>
      <?= crm_h($client['business_name'] ?? 'Client') ?>
      <?php
        // Smarter "ready_for_ai" label: if a draft already exists (e.g. operator
        // re-opened the kickoff wizard after AI gen), say "Ready to re-generate"
        // so the operator isn't confused by the old "Last AI gen" timestamp.
        $hasDraft = !empty($intake['ai_drafts_json']);
        echo match ($status) {
            'not_started','in_progress'  => '<span class="badge b-prog">Kickoff in progress</span>',
            'ready_for_ai'               => $hasDraft
                ? '<span class="badge b-ready">Ready to re-generate</span>'
                : '<span class="badge b-ready">Ready to generate</span>',
            'ai_generated'               => '<span class="badge b-gen">AI draft ready</span>',
            'pending_approval'           => '<span class="badge b-gen">Pending approval</span>',
            'approved'                   => '<span class="badge b-appr">Approved</span>',
            'deployed'                   => '<span class="badge b-appr">Live</span>',
            default                      => '<span class="badge b-not">' . crm_h($status) . '</span>',
        };
      ?>
    </h1>
  </div>

  <?php if ($saved):   ?><div class="ok"><?= $msg !== '' ? crm_h($msg) : 'Saved.' ?></div><?php endif; ?>
  <?php if ($genErr):  ?><div class="err">AI generation failed: <?= crm_h($genErr) ?></div><?php endif; ?>
  <?php if ($sendErr): ?><div class="err"><?= crm_h($sendErr) ?></div><?php endif; ?>

  <div class="grid">
    <!-- LEFT: intake snapshot + actions -->
    <div>
      <div class="panel">
        <h2>Intake snapshot</h2>
        <?php if (!$intake): ?>
          <p style="color:#6b6877;font-size:13px;margin:0">No kickoff data yet. <a href="/crm/client-kickoff.php?id=<?= (int)$client['id'] ?>" style="color:#6d28d9">Start kickoff →</a></p>
        <?php else: ?>
          <div class="row"><div class="k">Display name</div><div class="v"><?= crm_h($intake['display_name'] ?? '—') ?></div></div>
          <div class="row"><div class="k">Tagline</div><div class="v"><?= crm_h($intake['tagline'] ?? '—') ?></div></div>
          <div class="row"><div class="k">Template</div><div class="v"><?= crm_h($intake['template_choice'] ?? '—') ?></div></div>
          <?php $svc = $intake['services_decoded'] ?? []; ?>
          <div class="row"><div class="k">Services</div><div class="v"><?= count((array)$svc) ?> listed</div></div>
          <div class="row"><div class="k">24/7</div><div class="v"><?= !empty($intake['emergency_24_7']) ? 'Yes' : 'No' ?></div></div>
          <div class="row"><div class="k">Years in biz</div><div class="v"><?= crm_h((string)($intake['years_in_business'] ?? '—')) ?></div></div>
          <div class="row"><div class="k">Primary goal</div><div class="v"><?= crm_h($intake['primary_goal'] ?? '—') ?></div></div>
          <div class="row"><div class="k">Photos URL</div><div class="v"><?= crm_h($intake['photos_drive_url'] ?? '—') ?></div></div>
          <div class="row"><div class="k">Last AI gen</div><div class="v"><?= !empty($intake['ai_generated_at']) ? crm_h(crm_fmtRelative($intake['ai_generated_at'])) : '—' ?></div></div>
        <?php endif; ?>
      </div>

      <div class="panel" style="margin-top:14px">
        <h2>Actions</h2>
        <div class="actions">
          <form method="post" action="/crm/update.php" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Generating… (30–45s)'">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <input type="hidden" name="mode" value="intake_generate">
            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
            <button type="submit" class="btn-primary"
                    <?= !in_array($status, ['ready_for_ai','ai_generated','pending_approval'], true) ? 'disabled' : '' ?>>
              <?= !empty($intake['ai_drafts_json']) ? '🔄 Regenerate AI copy' : '✨ Generate AI copy' ?>
            </button>
          </form>

          <?php if (!empty($intake['ai_drafts_json'])): ?>
            <a class="btn-secondary" href="<?= crm_h($internalPreviewUrl) ?>" target="_blank">↗ Open preview in new tab</a>
            <a class="btn-secondary" href="/crm/draft-editor.php?id=<?= (int)$client['id'] ?>">✏️ Edit draft text</a>
          <?php endif; ?>

          <a class="btn-secondary" href="/crm/client.php?id=<?= (int)$client['id'] ?>#assets" target="_blank">🖼️ Manage photos</a>

          <?php if (!empty($intake['ai_drafts_json']) && (!empty($client['billing_email']) || !empty($client['primary_email']))): ?>
            <form method="post" action="/crm/update.php"
                  onsubmit="return confirm('This will EMAIL the preview link to <?= crm_h($client['billing_email'] ?: $client['primary_email']) ?> — they will see the draft.\n\nProceed?')">
              <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
              <input type="hidden" name="mode" value="intake_send_preview">
              <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
              <button type="submit" class="btn-secondary">📧 Email preview link to client (notifies them)</button>
            </form>
            <?php if ($clientPreviewUrl): ?>
              <div style="font-size:12px;color:#6b6877;padding:6px 4px;line-height:1.4">
                ✓ Client link active · expires <?= crm_h(date('M j', strtotime($client['magic_token_expires_at']))) ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <form method="post" action="/crm/update.php"
                onsubmit="return confirm('Mark this draft as APPROVED?\n\nThis unlocks the Deploy button — the site will be uploaded to the client hosting on the next click.')">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <input type="hidden" name="mode" value="intake_approve">
            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
            <button type="submit" class="btn-success"
                    <?= !in_array($status, ['ai_generated','pending_approval'], true) ? 'disabled' : '' ?>>
              ✓ Approve draft
            </button>
          </form>

          <form method="post" action="/crm/update.php"
                onsubmit="return confirm('Deploy to client hosting NOW?\n\nThis uploads the rendered HTML to the credential on file (cPanel/SFTP/Wordpress). After success, follow-up tasks (GBP/LSA/Tradio) auto-appear in Today.\n\nMake sure the credential is in /crm/client-credentials.php first.')">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <input type="hidden" name="mode" value="intake_deploy">
            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
            <button type="submit" class="btn-primary"
                    <?= !in_array($status, ['approved','deployed'], true) ? 'disabled' : '' ?>>
              🚀 Deploy to client hosting
            </button>
          </form>

          <?php if ($status === 'deployed'): ?>
            <form method="post" action="/crm/update.php"
                  onsubmit="return confirm('Roll back the LAST deploy?\n\nSwaps the current live HTML files with their backup copies (the previous deploy). This is reversible — clicking Rollback again brings back the current deploy.\n\nWordPress sites can't be rolled back automatically — use wp-admin → Pages → Revisions.')">
              <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
              <input type="hidden" name="mode" value="deploy_rollback">
              <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
              <button type="submit" class="btn-secondary" style="border-color:#92400e;color:#92400e">↩️ Rollback last deploy</button>
            </form>
          <?php endif; ?>

          <a class="btn-secondary" href="/crm/client-credentials.php?id=<?= (int)$client['id'] ?>">🔑 Manage credentials</a>

          <form method="post" action="/crm/update.php"
                onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Testing connection…'">
            <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
            <input type="hidden" name="mode" value="deploy_test_connection">
            <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
            <button type="submit" class="btn-secondary">🔌 Test deploy connection</button>
          </form>

          <?php if (!empty($intake['deployed_url'])): ?>
            <a class="btn-secondary" href="<?= crm_h($intake['deployed_url']) ?>" target="_blank">↗ Visit deployed site</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: live preview -->
    <div>
      <div class="panel" style="padding:8px;margin-bottom:0">
        <?php if (empty($intake['ai_drafts_json'])): ?>
          <div class="preview-empty">
            <div>
              <div style="font-size:48px;margin-bottom:14px">📝</div>
              <strong style="color:#0e0d12">No AI copy yet.</strong><br>
              <span>Click "Generate AI copy" on the left. Generation takes 30–45 seconds.</span>
            </div>
          </div>
        <?php else: ?>
          <iframe class="preview-frame" src="<?= crm_h($internalPreviewUrl) ?>" title="Preview"></iframe>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</body></html>
<?php
