<?php
// Shared rendering for the kickoff wizard. Used by:
//   crm/client-kickoff.php — operator path (CRM login)
//   kickoff.php             — client path (magic link)
//
// Both paths self-handle GET (render current step) and POST (save +
// advance). The handlers live in update.php (CRM) and kickoff.php (magic).
// This file owns ONLY the HTML rendering.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/intake.php';

// h() — local HTML escape, mirrors crm_h but available to the public-facing
// kickoff.php which doesn't load lib/auth.php.
function intake_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const INTAKE_STEPS_LABELS = [
    1 => 'Business basics',
    2 => 'Service area',
    3 => 'Services & hours',
    4 => 'Trust signals',
    5 => 'Visual',
    6 => 'Tone & goals',
    7 => 'Pick a template',
    8 => 'Review & submit',
];

// Top of every page — shell + header + progress bar.
// $action: form post target (e.g. '/crm/update.php' or '/kickoff.php?t=...')
// $context: 'crm' or 'magic' (drives copy + back button URL)
function intake_renderShellOpen(array $client, array $intake, int $step, string $context): void {
    $clientName = intake_h($client['business_name'] ?? 'your business');
    $progress   = round(($step / CRM_INTAKE_TOTAL_STEPS) * 100);
    $stepLabel  = INTAKE_STEPS_LABELS[$step] ?? '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Kickoff · Step <?= $step ?> of <?= CRM_INTAKE_TOTAL_STEPS ?> — Adverton</title>
<link rel="stylesheet" href="/styles.css?v=20260507">
<style>
  body{margin:0;background:#faf9ff;color:#0e0d12;font-family:-apple-system,Segoe UI,sans-serif}
  main{max-width:720px;margin:0 auto;padding:24px 20px 60px}
  .top{display:flex;align-items:center;gap:12px;margin-bottom:18px}
  .top .step{font-size:12px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600}
  .progress{height:6px;background:#ece9f3;border-radius:999px;overflow:hidden;margin-bottom:18px}
  .progress > div{height:100%;background:#6d28d9;transition:width .25s}
  h1{margin:0 0 6px;font-size:24px;letter-spacing:-0.01em}
  .lede{color:#6b6877;font-size:15px;line-height:1.5;margin:0 0 22px}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:14px;padding:22px;margin-bottom:14px}
  label{display:block;font-size:13px;font-weight:600;color:#383640;margin:14px 0 6px}
  label .req{color:#dc2626}
  label .opt{color:#6b6877;font-weight:400}
  input[type=text],input[type=email],input[type=tel],input[type=url],input[type=number],select,textarea{
    width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;
    padding:11px 13px;border-radius:9px;font-size:16px;font-family:inherit;box-sizing:border-box;
  }
  textarea{min-height:90px;resize:vertical}
  input:focus,select:focus,textarea:focus{outline:none;border-color:#6d28d9;box-shadow:0 0 0 3px rgba(109,40,217,.10)}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:520px){.row2{grid-template-columns:1fr}}
  .help{font-size:12px;color:#6b6877;margin-top:4px;line-height:1.4}
  .nav{display:flex;justify-content:space-between;align-items:center;margin-top:22px;gap:10px}
  button.primary,a.primary{background:#6d28d9;color:#fff;border:0;padding:12px 24px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
  button.primary:hover,a.primary:hover{background:#5b21b6}
  a.back,button.back{background:#fff;color:#6b6877;border:1px solid #e7e4ee;padding:12px 18px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
  .check-row{display:flex;align-items:center;gap:10px;margin:14px 0;font-size:15px}
  .check-row input{width:auto}
  .tile-grid{display:grid;grid-template-columns:1fr;gap:12px;margin-top:14px}
  .tile{border:2px solid #e7e4ee;border-radius:12px;padding:18px;cursor:pointer;background:#fff}
  .tile.sel{border-color:#6d28d9;background:#faf5ff}
  .tile h3{margin:0 0 6px;font-size:17px}
  .tile p{margin:0;color:#6b6877;font-size:13px;line-height:1.5}
  .tile .for{font-size:11px;color:#6d28d9;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-top:8px}
  .repeat-row{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-bottom:6px;align-items:center}
  .repeat-row input{margin:0}
  .repeat-row button.del{background:#fee2e2;color:#991b1b;border:0;padding:9px 12px;border-radius:8px;font-size:13px;cursor:pointer}
  button.add{background:#fff;border:1.5px dashed #c7c2d6;color:#6d28d9;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;width:100%;margin-top:6px}
  button.add:hover{background:#f3f1f8;border-color:#6d28d9}
  .hours-grid{display:grid;grid-template-columns:80px 1fr 1fr;gap:8px;margin-bottom:6px;align-items:center;font-size:14px}
  .err{background:#fee2e2;color:#991b1b;padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:14px}
  .ok{background:#dcfce7;color:#166534;padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:14px}
</style>
</head>
<body>
<main>
  <div class="top">
    <div>
      <div class="step">Step <?= $step ?> of <?= CRM_INTAKE_TOTAL_STEPS ?> · <?= intake_h($stepLabel) ?></div>
      <h1 style="margin-top:4px">Kickoff for <?= $clientName ?></h1>
    </div>
  </div>
  <div class="progress"><div style="width:<?= $progress ?>%"></div></div>
<?php
}

function intake_renderShellClose(): void {
    ?>
</main>
</body>
</html><?php
}

// Each step renderer takes the form action + intake row + (for magic-link
// path) the token to round-trip on the back button.
function intake_renderStep(int $step, array $intake, string $action, ?string $token, string $context): void {
    $back = $step > 1
        ? ($context === 'magic' ? '/kickoff.php?t=' . urlencode((string)$token) . '&step=' . ($step - 1)
                                : '/crm/client-kickoff.php?id=' . (int)$intake['client_id'] . '&step=' . ($step - 1))
        : null;
    ?>
  <form class="card" method="post" action="<?= intake_h($action) ?>" id="stepForm">
    <input type="hidden" name="step" value="<?= $step ?>">
    <?php if ($token !== null): ?><input type="hidden" name="t" value="<?= intake_h($token) ?>"><?php endif; ?>
    <?php if ($context === 'crm'): ?>
      <input type="hidden" name="mode" value="intake_save">
      <input type="hidden" name="csrf" value="<?= intake_h(crm_csrfToken()) ?>">
      <input type="hidden" name="client_id" value="<?= (int)$intake['client_id'] ?>">
    <?php endif; ?>

    <?php
    switch ($step) {
        case 1: intake_step1($intake); break;
        case 2: intake_step2($intake); break;
        case 3: intake_step3($intake); break;
        case 4: intake_step4($intake); break;
        case 5: intake_step5($intake); break;
        case 6: intake_step6($intake); break;
        case 7: intake_step7($intake); break;
        case 8: intake_step8($intake); break;
    }
    ?>

    <div class="nav">
      <?php if ($back): ?><a class="back" href="<?= intake_h($back) ?>">← Back</a><?php else: ?><span></span><?php endif; ?>
      <button type="submit" class="primary">
        <?= $step < CRM_INTAKE_TOTAL_STEPS ? 'Continue →' : 'Submit kickoff' ?>
      </button>
    </div>
  </form>
<?php
}

// ─── STEP 1: Business basics ────────────────────────────────────────────
function intake_step1(array $intake): void {
    ?>
    <p class="lede">A few one-liners about the business so we can write the right copy. Don't overthink it — we'll tighten it together later.</p>

    <label>How should the business name appear on the website? <span class="req">*</span></label>
    <input type="text" name="display_name" required maxlength="160" value="<?= intake_h($intake['display_name'] ?? '') ?>">
    <div class="help">Sometimes branded different than the legal name. E.g. "Joe's Plumbing" instead of "Joe Plumbing LLC".</div>

    <label>Tagline (one line under the business name)</label>
    <input type="text" name="tagline" maxlength="255" value="<?= intake_h($intake['tagline'] ?? '') ?>">
    <div class="help">Examples: "Family-owned, Houston, since 2003" · "Same-day plumbing repairs in DFW" · "24/7 emergency electricians".</div>

    <label>Short story (2–3 sentences)</label>
    <textarea name="story_short" maxlength="500"><?= intake_h($intake['story_short'] ?? '') ?></textarea>
    <div class="help">Goes into the hero/intro. What you do + who you serve + 1 thing that makes you different.</div>

    <label>Long story (about page) <span class="opt">(optional)</span></label>
    <textarea name="story_long" maxlength="2000" style="min-height:140px"><?= intake_h($intake['story_long'] ?? '') ?></textarea>
    <div class="help">Background, why you started, values. Skip if you'd rather we draft it from the short version.</div>
<?php
}

// ─── STEP 2: Service area ──────────────────────────────────────────────
function intake_step2(array $intake): void {
    $sa = $intake['service_area_decoded'] ?? [];
    $type = $sa['type'] ?? 'cities';
    $cities = is_array($sa['cities'] ?? null) ? implode("\n", $sa['cities']) : '';
    $zip = (string)($sa['zip'] ?? '');
    $radius = (int)($sa['radius_mi'] ?? 0);
    ?>
    <p class="lede">Where do you take jobs? Either list cities or give us a center ZIP + radius.</p>

    <div class="check-row">
      <input type="radio" name="sa_type" id="sa_cities" value="cities" <?= $type==='cities'?'checked':'' ?>>
      <label for="sa_cities" style="margin:0;font-weight:600">List of cities</label>
    </div>
    <textarea name="sa_cities" placeholder="Houston&#10;Sugar Land&#10;Pearland&#10;Katy" style="min-height:110px"><?= intake_h($cities) ?></textarea>
    <div class="help">One per line. We'll create city-specific pages from these.</div>

    <div class="check-row" style="margin-top:18px">
      <input type="radio" name="sa_type" id="sa_radius" value="radius" <?= $type==='radius'?'checked':'' ?>>
      <label for="sa_radius" style="margin:0;font-weight:600">Or radius from a ZIP</label>
    </div>
    <div class="row2">
      <div>
        <label>Center ZIP</label>
        <input type="text" name="sa_zip" maxlength="20" value="<?= intake_h($zip) ?>">
      </div>
      <div>
        <label>Miles</label>
        <input type="number" name="sa_radius_mi" min="1" max="200" value="<?= $radius ?: '' ?>">
      </div>
    </div>
<?php
}

// ─── STEP 3: Services + hours ─────────────────────────────────────────
function intake_step3(array $intake): void {
    $services = $intake['services_decoded'] ?? [];
    if (!is_array($services)) $services = [];
    if (empty($services)) $services = [['name'=>'','description'=>'']];
    $hours = $intake['hours_regular_decoded'] ?? [];
    $days = ['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'];
    ?>
    <p class="lede">List the services you actually offer (5–10 is ideal). Don't include things you'd refuse.</p>

    <label>Services <span class="req">*</span></label>
    <div id="services-list">
      <?php foreach ($services as $i => $s): ?>
        <div class="repeat-row">
          <input type="text" name="services[<?= $i ?>][name]" placeholder="Drain cleaning" value="<?= intake_h($s['name'] ?? '') ?>">
          <input type="text" name="services[<?= $i ?>][description]" placeholder="Emergency unclog · 24/7" value="<?= intake_h($s['description'] ?? '') ?>">
          <button type="button" class="del" onclick="this.parentElement.remove()">✕</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="add" onclick="(function(){var i=document.querySelectorAll('#services-list .repeat-row').length;var d=document.createElement('div');d.className='repeat-row';d.innerHTML='<input type=text name=services['+i+'][name] placeholder=Service> <input type=text name=services['+i+'][description] placeholder=Short description> <button type=button class=del onclick=this.parentElement.remove()>✕</button>';document.getElementById('services-list').appendChild(d);})();return false">+ Add a service</button>

    <label style="margin-top:24px">Regular hours</label>
    <?php foreach ($days as $k => $label): ?>
      <div class="hours-grid">
        <div><strong><?= $label ?></strong></div>
        <input type="text" name="hours[<?= $k ?>][open]"  placeholder="8:00 AM" value="<?= intake_h($hours[$k]['open']  ?? '') ?>">
        <input type="text" name="hours[<?= $k ?>][close]" placeholder="6:00 PM" value="<?= intake_h($hours[$k]['close'] ?? '') ?>">
      </div>
    <?php endforeach; ?>
    <div class="help" style="margin-top:6px">Leave a row empty if closed that day.</div>

    <div class="check-row" style="margin-top:18px">
      <input type="checkbox" name="emergency_24_7" id="em247" value="1" <?= !empty($intake['emergency_24_7']) ? 'checked' : '' ?>>
      <label for="em247" style="margin:0;font-weight:600">Offer 24/7 emergency service</label>
    </div>
<?php
}

// ─── STEP 4: Trust signals ────────────────────────────────────────────
function intake_step4(array $intake): void {
    $certs = $intake['certifications_decoded'] ?? [];
    $reviews = $intake['reviews_links_decoded'] ?? [];
    $certsStr = is_array($certs) ? implode(', ', $certs) : '';
    ?>
    <p class="lede">Anything that builds trust on the page. Skip what you don't have.</p>

    <div class="row2">
      <div>
        <label>Years in business</label>
        <input type="number" name="years_in_business" min="0" max="100" value="<?= intake_h((string)($intake['years_in_business'] ?? '')) ?>">
      </div>
      <div>
        <label>License number</label>
        <input type="text" name="license_number" maxlength="80" value="<?= intake_h($intake['license_number'] ?? '') ?>">
      </div>
    </div>

    <label>Insurance carrier</label>
    <input type="text" name="insurance_carrier" maxlength="160" value="<?= intake_h($intake['insurance_carrier'] ?? '') ?>">
    <div class="help">E.g. "State Farm" or "Hiscox". We just say you're insured — we don't post policy numbers.</div>

    <label>Certifications (comma-separated)</label>
    <input type="text" name="certifications" placeholder="NATE certified, EPA 608, BBB Accredited" value="<?= intake_h($certsStr) ?>">

    <label>Google review URL <span class="opt">(profile or share link)</span></label>
    <input type="url" name="reviews_google" maxlength="500" placeholder="https://g.page/..." value="<?= intake_h($reviews['google'] ?? '') ?>">

    <div class="row2">
      <div>
        <label>Yelp page</label>
        <input type="url" name="reviews_yelp" maxlength="500" placeholder="https://yelp.com/biz/..." value="<?= intake_h($reviews['yelp'] ?? '') ?>">
      </div>
      <div>
        <label>BBB page</label>
        <input type="url" name="reviews_bbb" maxlength="500" placeholder="https://bbb.org/..." value="<?= intake_h($reviews['bbb'] ?? '') ?>">
      </div>
    </div>
<?php
}

// ─── STEP 5: Visual ────────────────────────────────────────────────────
function intake_step5(array $intake): void {
    $colors = $intake['brand_colors_decoded'] ?? [];
    ?>
    <p class="lede">Photos and brand. Both optional — if you skip, we use sensible defaults that look great for your trade.</p>

    <label>Photos of your work, team, or trucks <span class="opt">(optional)</span></label>
    <input type="url" name="photos_drive_url" maxlength="500" placeholder="https://drive.google.com/drive/folders/..." value="<?= intake_h($intake['photos_drive_url'] ?? '') ?>">
    <div class="help">Paste a Google Drive, Dropbox, or any folder link — make sure it's set to "anyone with the link can view". Or just email photos to <strong>assets@adverton.net</strong> anytime — our AI sorts them automatically.</div>

    <label style="margin-top:24px">Brand colors <span class="opt">(optional — leave blank if you don't have any)</span></label>
    <div class="row2">
      <div>
        <label style="margin:0;font-weight:400;font-size:12px;color:#6b6877">Main color</label>
        <input type="text" name="color_primary" placeholder="#1a73e8" maxlength="9" value="<?= intake_h($colors['primary'] ?? '') ?>">
      </div>
      <div>
        <label style="margin:0;font-weight:400;font-size:12px;color:#6b6877">Accent color</label>
        <input type="text" name="color_accent" placeholder="#f59e0b" maxlength="9" value="<?= intake_h($colors['accent'] ?? '') ?>">
      </div>
    </div>
    <div class="help">Hex codes like <code style="background:#f3f1f8;padding:1px 5px;border-radius:3px;font-size:12px">#1a73e8</code>. <strong>No idea what hex is?</strong> Leave both blank — we'll pick something on-brand for your trade automatically.</div>
<?php
}

// ─── STEP 6: Tone & goals ─────────────────────────────────────────────
function intake_step6(array $intake): void {
    $comps = $intake['competitors_admired_decoded'] ?? [];
    if (!is_array($comps)) $comps = [];
    while (count($comps) < 3) $comps[] = '';
    $goal = (string)($intake['primary_goal'] ?? '');
    $goals = [
        'more_calls'      => 'More calls coming in',
        'more_bookings'   => 'More online bookings',
        'more_reviews'    => 'More Google reviews',
        'brand_awareness' => 'Brand awareness',
    ];
    ?>
    <p class="lede">A bit about the voice you want, plus the #1 outcome.</p>

    <label>Up to 3 sites/competitors you like the tone of</label>
    <?php for ($i = 0; $i < 3; $i++): ?>
      <input type="url" name="competitors[]" placeholder="https://example.com" maxlength="500" value="<?= intake_h($comps[$i] ?? '') ?>" style="margin-bottom:6px">
    <?php endfor; ?>
    <div class="help">We use this to match the voice — not to copy. "Friendly + concise" or "professional + technical", etc.</div>

    <label style="margin-top:18px">Primary goal for the website</label>
    <select name="primary_goal">
      <option value="">— pick one —</option>
      <?php foreach ($goals as $v => $label): ?>
        <option value="<?= intake_h($v) ?>" <?= $goal===$v?'selected':'' ?>><?= intake_h($label) ?></option>
      <?php endforeach; ?>
    </select>
<?php
}

// ─── STEP 7: Pick a template ──────────────────────────────────────────
function intake_step7(array $intake): void {
    $sel = (string)($intake['template_choice'] ?? '');
    $tiles = [
        'trust_first' => [
            'title' => 'Trust-First',
            'desc'  => 'Reviews + ratings front and center. Social proof above the fold.',
            'for'   => 'Best for: established contractors with 4.5+ stars',
        ],
        'speed_first' => [
            'title' => 'Speed-First',
            'desc'  => 'Big click-to-call hero. 24/7 messaging. Built to capture emergencies.',
            'for'   => 'Best for: emergency services (plumbing, locksmith, electrical, HVAC)',
        ],
        'story_first' => [
            'title' => 'Story-First',
            'desc'  => 'Project gallery + family story. For higher-consideration jobs.',
            'for'   => 'Best for: landscaping, painting, remodeling, hardscaping',
        ],
    ];
    // Page menu order — must match the template nav: Home, About, Services,
    // Service Area, Contact. The preview thumbnails are rendered in this order.
    $pageOrder = [
        'home'         => 'Home',
        'about'        => 'About',
        'services'     => 'Services',
        'service-area' => 'Service Area',
        'contact'      => 'Contact',
    ];
    ?>
    <p class="lede">Each option is a 5-page website. Pick the layout that fits the business — we can swap later if it doesn't feel right.</p>

    <style>
      .tpl-tile{border:2px solid #e7e4ee;border-radius:12px;padding:18px;cursor:pointer;background:#fff;margin-bottom:14px;display:block}
      .tpl-tile.sel{border-color:#6d28d9;background:#faf5ff}
      .tpl-tile h3{margin:0 0 4px;font-size:17px}
      .tpl-tile .desc{margin:0 0 12px;color:#6b6877;font-size:13px;line-height:1.5}
      .tpl-tile .pages{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin:14px 0 10px}
      @media(max-width:600px){.tpl-tile .pages{grid-template-columns:repeat(3,1fr)}}
      @media(max-width:380px){.tpl-tile .pages{grid-template-columns:repeat(2,1fr)}}
      .tpl-tile .page-thumb{display:flex;flex-direction:column;align-items:center;gap:4px}
      .tpl-tile .page-thumb img{width:100%;aspect-ratio:4/5;object-fit:cover;object-position:top center;border:1px solid #e7e4ee;border-radius:6px;background:#faf9ff}
      .tpl-tile .page-thumb span{font-size:11px;color:#6b6877;font-weight:600;text-align:center}
      .tpl-tile .for{font-size:11px;color:#6d28d9;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-top:8px}
    </style>

    <?php foreach ($tiles as $key => $t): ?>
      <label class="tpl-tile <?= $sel===$key?'sel':'' ?>">
        <input type="radio" name="template_choice" value="<?= intake_h($key) ?>" <?= $sel===$key?'checked':'' ?> style="display:none">
        <h3><?= intake_h($t['title']) ?></h3>
        <p class="desc"><?= intake_h($t['desc']) ?></p>
        <div class="pages">
          <?php foreach ($pageOrder as $slug => $label): ?>
            <div class="page-thumb">
              <img src="/crm/web-templates/previews/<?= intake_h($key) ?>_<?= intake_h($slug) ?>.png" alt="<?= intake_h($label) ?> preview" loading="lazy">
              <span><?= intake_h($label) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="for"><?= intake_h($t['for']) ?></div>
      </label>
    <?php endforeach; ?>

    <script>
    document.querySelectorAll('.tpl-tile input').forEach(function(r){
      r.addEventListener('change', function(){
        document.querySelectorAll('.tpl-tile').forEach(function(t){t.classList.remove('sel')});
        r.closest('.tpl-tile').classList.add('sel');
      });
    });
    </script>
<?php
}

// Convert raw $_POST into the per-step payload that crm_saveIntakeStep wants.
// Used by both kickoff.php (magic-link path) and crm/update.php
// (intake_save case from the operator path).
function crm_intakeNormalizePost(array $post, int $step): array {
    switch ($step) {
        case 1:
            return [
                'display_name' => trim((string)($post['display_name'] ?? '')),
                'tagline'      => trim((string)($post['tagline']      ?? '')),
                'story_short'  => trim((string)($post['story_short']  ?? '')),
                'story_long'   => trim((string)($post['story_long']   ?? '')),
            ];
        case 2:
            $type = ($post['sa_type'] ?? 'cities');
            $type = in_array($type, ['cities','radius'], true) ? $type : 'cities';
            $cities = array_values(array_filter(array_map('trim',
                preg_split('/\r?\n/', (string)($post['sa_cities'] ?? '')))));
            return ['service_area_json' => [
                'type'      => $type,
                'cities'    => $cities,
                'zip'       => trim((string)($post['sa_zip'] ?? '')),
                'radius_mi' => max(0, (int)($post['sa_radius_mi'] ?? 0)),
            ]];
        case 3:
            $svcRaw = is_array($post['services'] ?? null) ? $post['services'] : [];
            $services = [];
            foreach ($svcRaw as $row) {
                if (!is_array($row)) continue;
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') continue;
                $services[] = [
                    'name'        => $name,
                    'description' => trim((string)($row['description'] ?? '')),
                ];
            }
            $hoursRaw = is_array($post['hours'] ?? null) ? $post['hours'] : [];
            $hours = [];
            foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
                $row = $hoursRaw[$d] ?? null;
                if (!is_array($row)) continue;
                $open  = trim((string)($row['open']  ?? ''));
                $close = trim((string)($row['close'] ?? ''));
                if ($open === '' && $close === '') continue;
                $hours[$d] = ['open' => $open, 'close' => $close];
            }
            return [
                'services_json'      => $services,
                'hours_regular_json' => $hours,
                'emergency_24_7'     => !empty($post['emergency_24_7']),
            ];
        case 4:
            $certs = array_values(array_filter(array_map('trim',
                explode(',', (string)($post['certifications'] ?? '')))));
            return [
                'license_number'      => trim((string)($post['license_number']    ?? '')),
                'insurance_carrier'   => trim((string)($post['insurance_carrier'] ?? '')),
                'years_in_business'   => $post['years_in_business'] ?? null,
                'certifications_json' => $certs,
                'reviews_links_json'  => [
                    'google' => trim((string)($post['reviews_google'] ?? '')),
                    'yelp'   => trim((string)($post['reviews_yelp']   ?? '')),
                    'bbb'    => trim((string)($post['reviews_bbb']    ?? '')),
                ],
            ];
        case 5:
            $out = [
                'photos_drive_url'  => trim((string)($post['photos_drive_url'] ?? '')),
                'brand_colors_json' => [
                    'primary' => trim((string)($post['color_primary'] ?? '')),
                    'accent'  => trim((string)($post['color_accent']  ?? '')),
                ],
            ];
            // brand_logo_path is now operator-only (set later via /crm/client.php
            // after photos are processed) — only write if explicitly POSTed,
            // never overwrite with empty.
            if (isset($post['brand_logo_path'])) {
                $out['brand_logo_path'] = trim((string)$post['brand_logo_path']);
            }
            return $out;
        case 6:
            $comps = array_values(array_filter(array_map('trim',
                $post['competitors'] ?? [])));
            return [
                'competitors_admired_json' => $comps,
                'primary_goal'             => (string)($post['primary_goal'] ?? ''),
            ];
        case 7:
            return ['template_choice' => (string)($post['template_choice'] ?? '')];
        case 8:
            return [];
    }
    return [];
}

// ─── STEP 8: Review & submit ──────────────────────────────────────────
function intake_step8(array $intake): void {
    $rows = [
        ['Business name',        $intake['display_name']],
        ['Tagline',              $intake['tagline']],
        ['Short story',          $intake['story_short']],
        ['Years in business',    $intake['years_in_business']],
        ['License number',       $intake['license_number']],
        ['Insurance carrier',    $intake['insurance_carrier']],
        ['Photos folder',        $intake['photos_drive_url']],
        ['Primary goal',         $intake['primary_goal']],
        ['Template',             $intake['template_choice']],
    ];
    $services = $intake['services_decoded'] ?? [];
    ?>
    <p class="lede">Here's what we have. Edit anything by stepping back. When you submit, we'll start drafting your site.</p>

    <table style="width:100%;border-collapse:collapse;margin-bottom:14px">
      <?php foreach ($rows as [$label, $val]): if ($val === null || $val === '') continue; ?>
        <tr>
          <td style="padding:8px 0;color:#6b6877;font-size:13px;width:160px;vertical-align:top"><?= intake_h($label) ?></td>
          <td style="padding:8px 0;color:#0e0d12;font-size:14px;border-bottom:1px solid #f3f1f8"><?= intake_h((string)$val) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!empty($services)): ?>
        <tr>
          <td style="padding:8px 0;color:#6b6877;font-size:13px;vertical-align:top">Services</td>
          <td style="padding:8px 0;font-size:14px;border-bottom:1px solid #f3f1f8">
            <?php foreach ($services as $s):
              if (empty($s['name'])) continue; ?>
              <div><strong><?= intake_h($s['name']) ?></strong><?= !empty($s['description']) ? ' — ' . intake_h($s['description']) : '' ?></div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endif; ?>
    </table>

    <p style="font-size:13px;color:#6b6877;line-height:1.55">
      After you submit, we'll generate a draft of the website using AI + your answers, then run it by you for approval before anything goes live.
    </p>
<?php
}
