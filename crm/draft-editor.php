<?php
// Manual editor for the AI-generated draft (client_intake.ai_drafts_json).
// One input per field — matches the schema in crm/lib/ai-generator.php.
// Saves via update.php?mode=intake_draft_save. No regeneration, no client
// notification.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/intake.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']);

$clientId = (int)($_GET['id'] ?? 0);
$client   = $clientId > 0 ? crm_getClient($clientId) : null;
if (!$client) { http_response_code(404); header('Location: /crm/clients.php'); exit; }

$intake = crm_getIntake($clientId);
if (!$intake || empty($intake['ai_drafts_json'])) {
    header('Location: /crm/client-review.php?id=' . $clientId
        . '&genErr=' . urlencode('Generate AI copy before editing'));
    exit;
}

$copy = is_array($intake['ai_drafts_decoded'] ?? null) ? $intake['ai_drafts_decoded'] : [];

// Service names are locked to intake.services (the AI prompt commits to
// "same order, exact name"). We only let the operator edit description +
// emoji, not rename a service.
$intakeServices = (array)($intake['services_decoded'] ?? []);
$draftServices  = (array)($copy['services']  ?? []);
$trustStrip     = (array)($copy['trust_strip'] ?? ['', '', '']);
while (count($trustStrip) < 3) $trustStrip[] = '';
$faq            = (array)($copy['faq'] ?? []);
while (count($faq) < 5) $faq[] = ['question' => '', 'answer_html' => ''];

$savedErr = trim((string)($_GET['err'] ?? ''));

crm_renderHead('Edit draft · ' . ($client['business_name'] ?? 'Client'));
crm_renderHeader($user, '');
?>
<style>
  main{max-width:880px}
  .panel{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;margin-bottom:14px}
  .panel h2{margin:0 0 14px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  label{display:block;font-size:12px;color:#6b6877;font-weight:600;margin:10px 0 4px;text-transform:uppercase;letter-spacing:.04em}
  input[type="text"], textarea{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid #d6d3e0;border-radius:7px;font-size:14px;font-family:inherit;color:#0e0d12;background:#fff}
  input[type="text"]:focus, textarea:focus{outline:0;border-color:#6d28d9}
  textarea{min-height:80px;resize:vertical;line-height:1.5}
  .hint{font-size:12px;color:#6b6877;margin-top:3px}
  .inline-row{display:grid;grid-template-columns:48px 1fr 80px;gap:10px;align-items:start;margin:8px 0 14px;padding-bottom:14px;border-bottom:1px solid #f3f1f8}
  .inline-row:last-child{border-bottom:0}
  .inline-row .num{padding-top:8px;font-size:13px;color:#6b6877;font-weight:600}
  .inline-row .emoji{padding-top:1px}
  .actions{display:flex;gap:10px;margin-top:8px}
  .btn{padding:11px 22px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;border:0}
  .btn-primary{background:#6d28d9;color:#fff}
  .btn-secondary{background:#fff;color:#6d28d9;border:1px solid #6d28d9}
  .err{background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:14px}
  .schema-note{background:#faf9ff;border:1px solid #ece9f3;border-radius:8px;padding:10px 12px;font-size:12px;color:#6b6877;line-height:1.5;margin-bottom:14px}
  .schema-note code{background:#fff;padding:1px 5px;border-radius:3px;font-size:11px;color:#6d28d9}
</style>
<main>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <h1 style="margin:0;font-size:20px">
      <a href="/crm/client-review.php?id=<?= (int)$client['id'] ?>" style="color:#6b6877;text-decoration:none">‹</a>
      Edit draft · <?= crm_h($client['business_name'] ?? 'Client') ?>
    </h1>
  </div>

  <?php if ($savedErr): ?><div class="err"><?= crm_h($savedErr) ?></div><?php endif; ?>

  <div class="schema-note">
    HTML is only allowed in fields labeled <code>(HTML)</code> — use <code>&lt;p&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;li&gt;</code>. Other tags get stripped on save. Plain text fields take no markup.
  </div>

  <form method="post" action="/crm/update.php">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
    <input type="hidden" name="mode" value="intake_draft_save">
    <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">

    <div class="panel">
      <h2>Hero</h2>
      <label>Headline</label>
      <input type="text" name="hero_headline" value="<?= crm_h((string)($copy['hero']['headline'] ?? '')) ?>" maxlength="120">
      <div class="hint">4–9 words, includes the trade.</div>

      <label>Subheadline</label>
      <input type="text" name="hero_subheadline" value="<?= crm_h((string)($copy['hero']['subheadline'] ?? '')) ?>" maxlength="240">
      <div class="hint">1 sentence, 12–22 words.</div>

      <label>CTA primary</label>
      <input type="text" name="hero_cta_primary" value="<?= crm_h((string)($copy['hero']['cta_primary'] ?? '')) ?>" maxlength="40">
      <div class="hint">2–3 words, action verb (e.g. "Get a free quote").</div>

      <label>CTA secondary</label>
      <input type="text" name="hero_cta_secondary" value="<?= crm_h((string)($copy['hero']['cta_secondary'] ?? '')) ?>" maxlength="40">
    </div>

    <div class="panel">
      <h2>Trust strip · 3 short claims</h2>
      <?php foreach ($trustStrip as $i => $val): ?>
        <label>Claim <?= $i + 1 ?></label>
        <input type="text" name="trust_strip[]" value="<?= crm_h((string)$val) ?>" maxlength="80">
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <h2>About</h2>
      <label>Title</label>
      <input type="text" name="about_title" value="<?= crm_h((string)($copy['about']['title'] ?? '')) ?>" maxlength="80">
      <div class="hint">3–6 words.</div>

      <label>Body (HTML)</label>
      <textarea name="about_body_html" rows="6"><?= crm_h((string)($copy['about']['body_html'] ?? '')) ?></textarea>
      <div class="hint">2–3 short paragraphs wrapped in <code>&lt;p&gt;…&lt;/p&gt;</code>.</div>
    </div>

    <div class="panel">
      <h2>Services (<?= count($draftServices) ?> listed)</h2>
      <div class="hint" style="margin-bottom:14px">Service names are locked to the intake. To change a name, edit the kickoff intake and regenerate. Order matches the intake.</div>
      <?php foreach ($draftServices as $idx => $svc):
          $name = (string)($svc['name'] ?? ($intakeServices[$idx]['name'] ?? ''));
      ?>
        <input type="hidden" name="service_name[]" value="<?= crm_h($name) ?>">
        <div class="inline-row">
          <div class="num"><?= $idx + 1 ?>.</div>
          <div>
            <label style="margin-top:0"><?= crm_h($name) ?> · description (HTML)</label>
            <textarea name="service_description_html[]" rows="3"><?= crm_h((string)($svc['description_html'] ?? '')) ?></textarea>
          </div>
          <div class="emoji">
            <label style="margin-top:0">Emoji</label>
            <input type="text" name="service_icon_emoji[]" value="<?= crm_h((string)($svc['icon_emoji'] ?? '')) ?>" maxlength="8" style="text-align:center;font-size:18px">
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <h2>FAQ · 5 items</h2>
      <?php foreach ($faq as $idx => $row): if ($idx >= 5) break; ?>
        <div style="margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f3f1f8">
          <label style="margin-top:0">Q<?= $idx + 1 ?> · Question</label>
          <input type="text" name="faq_question[]" value="<?= crm_h((string)($row['question'] ?? '')) ?>" maxlength="180">
          <label>A<?= $idx + 1 ?> · Answer (HTML)</label>
          <textarea name="faq_answer_html[]" rows="3"><?= crm_h((string)($row['answer_html'] ?? '')) ?></textarea>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <h2>Footer blurb</h2>
      <label>One sentence describing the business + service area</label>
      <input type="text" name="footer_blurb" value="<?= crm_h((string)($copy['footer_blurb'] ?? '')) ?>" maxlength="280">
    </div>

    <div class="actions">
      <button type="submit" class="btn btn-primary">💾 Save changes</button>
      <a href="/crm/client-review.php?id=<?= (int)$client['id'] ?>" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</main>
</body></html>
