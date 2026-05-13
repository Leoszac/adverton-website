<?php
// Speed-First template — emergency services (plumbing, locksmith,
// electrical, HVAC). Sticky call bar on every page. Phone is dominant CTA.
//
// Multi-page: home / about / services / service-area / contact.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_renderTemplate_speed_first(array $client, array $intake, array $copy, array $assets, string $page = 'home'): string {
    $h = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $name      = (string)($intake['display_name']  ?? $client['business_name'] ?? '');
    $phone     = (string)($client['primary_phone'] ?? '');
    $phoneTel  = preg_replace('/[^0-9+]/', '', $phone);
    $hero      = (array)($copy['hero']         ?? []);
    $about     = (array)($copy['about']        ?? []);
    $services  = (array)($copy['services']     ?? []);
    $faq       = (array)($copy['faq']          ?? []);
    $trustStrip= (array)($copy['trust_strip']  ?? []);
    $emergency = !empty($intake['emergency_24_7']);

    $colors  = (array)($intake['brand_colors_decoded'] ?? []);
    // ?? only handles null/unset; wizard saves "" for blank inputs, so check empty.
    $primary = !empty($colors['primary']) ? $colors['primary'] : '#dc2626';
    $accent  = !empty($colors['accent'])  ? $colors['accent']  : '#f59e0b';

    $reviews    = (array)($intake['reviews_links_decoded'] ?? []);
    $googleUrl  = (string)($reviews['google'] ?? '');
    $yearsInBiz = (int)($intake['years_in_business'] ?? 0);
    $licenseNum = (string)($intake['license_number'] ?? '');
    $insurance  = (string)($intake['insurance_carrier'] ?? '');

    $hours = (array)($intake['hours_regular_decoded'] ?? []);

    $area = (array)($intake['service_area_decoded'] ?? []);
    $areaCities = ($area['type'] ?? '') === 'cities' && !empty($area['cities'])
        ? (array)$area['cities'] : [];
    $areaSummary = '';
    if ($areaCities) {
        $first6 = array_slice($areaCities, 0, 6);
        $areaSummary = 'Serving ' . implode(', ', $first6) . (count($areaCities) > 6 ? ' and surrounding areas.' : '.');
    } elseif (($area['type'] ?? '') === 'radius' && !empty($area['zip'])) {
        $areaSummary = 'Serving ' . (int)$area['radius_mi'] . ' miles around ' . $area['zip'] . '.';
    }

    $pageMeta = [
        'home'         => [($hero['headline'] ?? $name), ($hero['subheadline'] ?? '')],
        'about'        => ["About — {$name}", "Real humans answering 24/7. Same crew every time."],
        'services'     => ["Services — {$name}", "What we fix and how we work."],
        'service-area' => ["Service Area — {$name}", $areaSummary ?: "Where we respond."],
        'contact'      => ["Contact — {$name}", "Don't wait — call now or send a message."],
    ];
    [$pageTitle, $pageDesc] = $pageMeta[$page] ?? $pageMeta['home'];

    $schemaAddr = trim(implode(', ', array_filter([
        (string)($client['billing_address'] ?? ''),
        (string)($client['billing_city'] ?? ''),
        trim((string)($client['billing_state'] ?? '') . ' ' . (string)($client['billing_zip'] ?? ''))
    ])));
    $_schema = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name, 'description' => (string)($hero['subheadline'] ?? '')];
    if ($phone)       $_schema['telephone'] = $phone;
    if ($schemaAddr)  $_schema['address']   = ['@type' => 'PostalAddress', 'streetAddress' => $schemaAddr];
    $_schemaSvcs = array_values(array_filter(array_map(fn($s) => (string)($s['name'] ?? ''), (array)$services)));
    if ($_schemaSvcs) $_schema['makesOffer'] = array_map(fn($s) => ['@type' => 'Offer', 'name' => $s], $_schemaSvcs);
    if ($googleUrl)   $_schema['sameAs']    = [$googleUrl];

    $nav = [
        'home'         => ['label' => 'Home',         'href' => '/'],
        'about'        => ['label' => 'About',        'href' => '/about.html'],
        'services'     => ['label' => 'Services',     'href' => '/services.html'],
        'service-area' => ['label' => 'Service Area', 'href' => '/service-area.html'],
        'contact'      => ['label' => 'Contact',      'href' => '/contact.html'],
    ];

    ob_start();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $h($name) ?> — <?= $h($pageTitle) ?></title>
<meta name="description" content="<?= $h($pageDesc) ?>">
<script type="application/ld+json"><?= json_encode($_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<style>
  :root{--primary:<?= $h($primary) ?>;--accent:<?= $h($accent) ?>;--ink:#0e0d12;--ink-2:#383640;--ink-3:#6b6877;--bg:#fff;--line:#e7e4ee;--soft:#fef2f2}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif;color:var(--ink);background:var(--bg);line-height:1.55;font-size:17px}
  a{color:var(--primary);text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:0 22px}
  /* Sticky call bar (every page) */
  .callbar{position:sticky;top:0;z-index:20;background:var(--primary);color:#fff;padding:12px 0;text-align:center;font-weight:700;font-size:16px}
  .callbar a{color:#fff;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:10px}
  .callbar .live{display:inline-block;width:10px;height:10px;background:#22c55e;border-radius:50%;animation:pulse 1.5s infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
  /* Nav below callbar */
  header.site{background:#fff;border-bottom:1px solid var(--line);padding:14px 0}
  header.site .row{display:flex;justify-content:space-between;align-items:center;gap:14px}
  header.site .brand{font-weight:800;font-size:18px;letter-spacing:-0.01em;color:var(--ink)}
  header.site nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  header.site nav a{padding:8px 14px;border-radius:8px;font-size:14px;font-weight:600;color:var(--ink-2)}
  header.site nav a:hover{background:var(--soft);color:var(--primary)}
  header.site nav a.active{background:var(--soft);color:var(--primary)}
  /* Hamburger — CSS-only checkbox hack */
  header.site .nav-burger-input{display:none}
  header.site .nav-burger{display:none;cursor:pointer;padding:8px 6px;user-select:none;-webkit-tap-highlight-color:transparent}
  header.site .nav-burger span{display:block;width:26px;height:2.5px;background:var(--ink);margin:5px 0;border-radius:2px;transition:transform .25s,opacity .2s}
  @media(max-width:760px){
    header.site .row{flex-wrap:wrap;position:relative}
    header.site .nav-burger{display:inline-flex;flex-direction:column;margin-left:auto}
    header.site nav{display:none !important;width:100%;flex-direction:column;align-items:stretch;gap:0;padding:8px 0 12px;border-top:1px solid var(--line);margin-top:10px}
    header.site .nav-burger-input:checked ~ nav{display:flex !important}
    header.site nav a{padding:12px 14px;font-size:15px;text-align:left;width:100%;border-radius:8px}
    header.site .nav-burger-input:checked ~ .nav-burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}
    header.site .nav-burger-input:checked ~ .nav-burger span:nth-child(2){opacity:0}
    header.site .nav-burger-input:checked ~ .nav-burger span:nth-child(3){transform:translateY(-8px) rotate(-45deg)}
    /* Tap targets ≥44px (Apple HIG) — audit found brand/footer/callbar/phone <44px */
    header.site .brand{display:inline-flex;align-items:center;min-height:44px;padding:8px 0}
    .callbar a{padding:14px 0;min-height:44px;display:flex}
    footer.site a{display:inline-block;padding:14px 14px 14px 0;min-height:44px;line-height:1.2}
    .contact .info dd a{display:inline-block;padding:8px 0;line-height:1.5;min-height:32px}
  }
  /* Page header */
  .pagehead{background:linear-gradient(180deg,#fff 0%,var(--soft) 100%);padding:50px 0 40px;text-align:center}
  .pagehead h1{margin:0 0 8px;font-size:38px;letter-spacing:-0.02em;line-height:1.1;font-weight:800}
  .pagehead .lead{margin:0;color:var(--ink-2);font-size:17px;max-width:720px;margin-left:auto;margin-right:auto}
  /* Hero (home) */
  .hero{padding:60px 0 80px;text-align:center;background:linear-gradient(180deg,#fff 0%,var(--soft) 100%)}
  .hero h1{margin:0 0 18px;font-size:52px;letter-spacing:-0.025em;line-height:1.05;font-weight:800}
  .hero .sub{font-size:20px;color:var(--ink-2);margin:0 auto 36px;max-width:680px}
  .big-call{display:inline-block;background:var(--primary);color:#fff;padding:24px 44px;border-radius:14px;font-size:32px;font-weight:800;letter-spacing:-0.01em;box-shadow:0 10px 32px rgba(220,38,38,.3)}
  .big-call .digits{font-size:36px;display:block;margin-top:4px}
  .answer-promise{font-size:14px;color:var(--ink-3);margin-top:20px;font-weight:600}
  .answer-promise span{color:#16a34a}
  /* Trust strip (dark) */
  .trust-strip{background:#0e0d12;color:#fff;padding:20px 0;text-align:center}
  .trust-row{display:flex;justify-content:center;gap:30px;flex-wrap:wrap;align-items:center;font-size:14px;font-weight:600}
  .trust-row .item{display:flex;align-items:center;gap:8px;color:#fff}
  .trust-row .check{width:18px;height:18px;border-radius:50%;background:#16a34a;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
  /* Services */
  .services{padding:60px 0;background:#fff}
  .services h2{text-align:center;margin:0 0 8px;font-size:30px}
  .services .sub{text-align:center;color:var(--ink-3);margin:0 0 30px}
  .svc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:30px}
  .svc{background:var(--soft);border:1px solid #fee2e2;border-radius:12px;padding:20px}
  .svc .icon{font-size:32px;margin-bottom:6px}
  .svc h3{margin:0 0 6px;font-size:17px;color:var(--ink)}
  .svc p{margin:0;color:var(--ink-2);font-size:14px}
  /* While-you-wait */
  .wait{padding:60px 0;background:var(--soft)}
  .wait h2{margin:0 0 14px;font-size:26px;text-align:center}
  .wait .sub{text-align:center;color:var(--ink-3);margin:0 0 28px}
  .wait-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
  .wait-card{background:#fff;border-left:4px solid var(--primary);padding:18px;border-radius:8px}
  .wait-card strong{color:var(--primary);font-size:14px;display:block;margin-bottom:4px}
  .wait-card p{margin:0;font-size:14px;color:var(--ink-2)}
  /* Trust badges (about) */
  .trust-badges{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;max-width:880px;margin:30px auto 0}
  .badge{background:var(--soft);border:1px solid #fee2e2;border-radius:10px;padding:18px;text-align:center}
  .badge .icon{font-size:24px;margin-bottom:6px}
  .badge strong{display:block;font-size:14px;color:var(--ink);margin-bottom:2px}
  .badge span{font-size:13px;color:var(--ink-3)}
  /* About */
  .about{padding:60px 0;background:#fff}
  .about h2{margin:0 0 16px;font-size:28px;text-align:center}
  .about-content{max-width:760px;margin:0 auto;color:var(--ink-2)}
  /* Area */
  .area{padding:28px 0;background:var(--ink);color:#fff;text-align:center;font-size:15px;font-weight:600}
  .areagrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;max-width:920px;margin:0 auto;padding:0 22px}
  .areacity{background:var(--soft);border:1px solid #fee2e2;border-radius:8px;padding:11px 14px;text-align:center;font-weight:600;font-size:14px;color:var(--ink)}
  .mapwrap{max-width:1000px;margin:30px auto 0;padding:0 22px}
  .mapplaceholder{width:100%;height:320px;border:2px dashed var(--line);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--ink-3);font-size:14px;background:var(--soft)}
  /* FAQ */
  .faq{padding:60px 0;background:#fff}
  .faq h2{text-align:center;margin:0 0 28px;font-size:28px}
  .faq-item{max-width:760px;margin:0 auto 12px;border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden}
  .faq-item summary{padding:16px 20px;font-weight:600;cursor:pointer;list-style:none;font-size:15px;display:flex;justify-content:space-between;align-items:center}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item summary::after{content:'+';font-size:20px;color:var(--ink-3)}
  .faq-item[open] summary::after{content:'−'}
  .faq-item .answer{padding:0 20px 18px;color:var(--ink-2);font-size:15px}
  /* Contact */
  .contact{padding:60px 0;background:var(--soft)}
  .contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:start}
  @media(max-width:780px){.contact-grid{grid-template-columns:1fr}}
  .contact h2{margin:0 0 12px;font-size:28px}
  .contact .info{background:#fff;border:1px solid #fee2e2;border-radius:12px;padding:24px;font-size:15px}
  .contact .info dt{font-weight:700;color:var(--ink);margin-top:14px}
  .contact .info dt:first-child{margin-top:0}
  .contact .info dd{margin:4px 0 0;color:var(--ink-2)}
  .form{display:flex;flex-direction:column;gap:12px}
  .form input,.form textarea{width:100%;padding:13px 15px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:16px;box-sizing:border-box;background:#fff}
  .form input:focus,.form textarea:focus{outline:none;border-color:var(--primary)}
  .form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:520px){.form .row2{grid-template-columns:1fr}}
  /* Final CTA */
  .final-cta{padding:60px 0;background:var(--primary);color:#fff;text-align:center}
  .final-cta h2{margin:0 0 14px;font-size:28px}
  .final-cta p{margin:0 0 22px;color:rgba(255,255,255,.9);font-size:16px}
  .final-cta a{display:inline-block;background:#fff;color:var(--primary);padding:18px 32px;border-radius:10px;font-weight:800;font-size:22px;margin-top:14px}
  footer.site{background:#0e0d12;color:#9ca3af;padding:24px 0;font-size:13px}
  footer.site .row{display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px}
  footer.site a{color:#d1d5db;margin-right:14px}
  @media(max-width:520px){.hero h1{font-size:36px}.hero .sub{font-size:17px}.big-call{padding:18px 28px;font-size:24px}.big-call .digits{font-size:26px}.services h2,.about h2,.faq h2,.final-cta h2,.contact h2{font-size:22px}.pagehead h1{font-size:28px}}
</style>
</head>
<body>

<?php if ($phone): ?>
<div class="callbar"><a href="tel:<?= $h($phoneTel) ?>"><span class="live"></span> Call now: <?= $h($phone) ?></a></div>
<?php endif; ?>

<header class="site"><div class="wrap row">
  <a href="/" class="brand"><?= $h($name) ?></a>
  <input type="checkbox" id="nav-burger" class="nav-burger-input" aria-hidden="true">
  <label for="nav-burger" class="nav-burger" aria-label="Toggle menu"><span></span><span></span><span></span></label>
  <nav>
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= $h($item['href']) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= $h($item['label']) ?></a>
    <?php endforeach; ?>
  </nav>
</div></header>

<?php switch ($page):
    case 'about': ?>

<section class="pagehead"><div class="wrap">
  <h1><?= $h($about['title'] ?? "About {$name}") ?></h1>
  <p class="lead">Real humans on the line<?= $emergency ? ' — 24/7' : '' ?>. Same crew every time.</p>
</div></section>

<section class="about"><div class="wrap about-content">
  <?= $about['body_html'] ?? '<p>Our story is coming soon.</p>' ?>
  <div class="trust-badges">
    <?php if ($yearsInBiz): ?><div class="badge"><div class="icon">🏆</div><strong><?= $yearsInBiz ?>+ years</strong><span>In business</span></div><?php endif; ?>
    <?php if ($licenseNum): ?><div class="badge"><div class="icon">📜</div><strong>Licensed</strong><span><?= $h($licenseNum) ?></span></div><?php endif; ?>
    <?php if ($insurance): ?><div class="badge"><div class="icon">🛡️</div><strong>Insured</strong><span><?= $h($insurance) ?></span></div><?php endif; ?>
    <?php if ($emergency): ?><div class="badge"><div class="icon">⏰</div><strong>24/7</strong><span>Emergency service</span></div><?php endif; ?>
  </div>
</div></section>

<section class="final-cta"><div class="wrap">
  <h2>Got a problem? Don't wait.</h2>
  <p>Real humans on the line. No robots. No "press 1".</p>
  <?php if ($phone): ?><a href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
</div></section>

<?php break; case 'services': ?>

<section class="pagehead"><div class="wrap">
  <h1>What we fix</h1>
  <p class="lead">Same-day response. Honest pricing. Real humans answering<?= $emergency ? ' 24/7' : '' ?>.</p>
</div></section>

<section class="services"><div class="wrap">
  <div class="svc-grid">
    <?php foreach ($services as $svc): ?>
      <div class="svc">
        <div class="icon"><?= $h((string)($svc['icon_emoji'] ?? '🔧')) ?></div>
        <h3><?= $h((string)($svc['name'] ?? '')) ?></h3>
        <div><?= $svc['description_html'] ?? '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div></section>

<?php if ($emergency): ?>
<section class="wait"><div class="wrap">
  <h2>While you wait — what to do</h2>
  <p class="sub">Step-by-step, until we get there.</p>
  <div class="wait-grid">
    <div class="wait-card"><strong>1. Stay safe</strong><p>If there's water or electrical hazard, shut off the main valve or breaker.</p></div>
    <div class="wait-card"><strong>2. Document</strong><p>Take photos. Helps with insurance + lets us prep the right parts.</p></div>
    <div class="wait-card"><strong>3. Clear the area</strong><p>Move valuables out of harm's way. Keep pets in another room.</p></div>
    <div class="wait-card"><strong>4. We're rolling</strong><p>Truck dispatched. Tech will text on the way.</p></div>
  </div>
</div></section>
<?php endif; ?>

<?php if (!empty($faq)): ?>
<section class="faq"><div class="wrap">
  <h2>Frequently asked</h2>
  <?php foreach ($faq as $item): ?>
    <details class="faq-item">
      <summary><?= $h((string)($item['question'] ?? '')) ?></summary>
      <div class="answer"><?= $item['answer_html'] ?? '' ?></div>
    </details>
  <?php endforeach; ?>
</div></section>
<?php endif; ?>

<section class="final-cta"><div class="wrap">
  <h2>Need it fixed? Call now.</h2>
  <?php if ($phone): ?><a href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
</div></section>

<?php break; case 'service-area': ?>

<section class="pagehead"><div class="wrap">
  <h1>Where we respond</h1>
  <p class="lead"><?= $h($areaSummary) ?: 'We respond to the local area fast.' ?></p>
</div></section>

<?php if ($areaCities): ?>
<section style="padding:50px 0;background:#fff"><div class="wrap">
  <div class="areagrid">
    <?php foreach ($areaCities as $city): ?>
      <div class="areacity"><?= $h((string)$city) ?></div>
    <?php endforeach; ?>
  </div>
  <div class="mapwrap">
    <div class="mapplaceholder">[Google Maps embed — paste your Maps Embed API key + address at onboarding]</div>
  </div>
</div></section>
<?php endif; ?>

<section class="about"><div class="wrap about-content" style="text-align:center">
  <h2>Outside the area?</h2>
  <p>Call us anyway. We sometimes take emergency jobs outside our usual range, or we can point you to someone we trust.</p>
</div></section>

<section class="final-cta"><div class="wrap">
  <h2>Get help fast.</h2>
  <?php if ($phone): ?><a href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
</div></section>

<?php break; case 'contact': ?>

<section class="pagehead"><div class="wrap">
  <h1>Don't wait — get help now</h1>
  <p class="lead">Fastest is to call. Or send a message and we'll respond same day.</p>
</div></section>

<section class="contact"><div class="wrap"><div class="contact-grid">
  <div>
    <h2>Send a message</h2>
    <form action="https://adverton.net/crm/client-form-submit.php?client_id=<?= (int)$client['id'] ?>" method="post" class="form">
      <input type="hidden" name="redirect" value="1">
      <input type="text" name="hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <input type="text" name="name" placeholder="Your name" required>
      <div class="row2">
        <input type="tel" name="phone" placeholder="Phone" required>
        <input type="email" name="email" placeholder="Email">
      </div>
      <textarea name="message" placeholder="What's going on?" rows="4"></textarea>
      <button type="submit" style="background:var(--primary);color:#fff;padding:14px 22px;border-radius:9px;font-weight:700;font-size:16px;cursor:pointer;border:0;font-family:inherit">Send →</button>
    </form>
  </div>
  <div>
    <h2>Or reach us directly</h2>
    <dl class="info">
      <?php if ($phone): ?><dt>📞 Phone</dt><dd><a href="tel:<?= $h($phoneTel) ?>" style="font-weight:700;font-size:18px"><?= $h($phone) ?></a><?= $emergency ? '<br><span style="font-size:13px;color:var(--ink-3)">Answered 24/7</span>' : '' ?></dd><?php endif; ?>
      <?php if (!empty($client['billing_address'])): ?><dt>📍 Address</dt><dd><?= $h((string)$client['billing_address']) ?><?= !empty($client['billing_city']) ? '<br>' . $h((string)$client['billing_city']) . ', ' . $h((string)($client['billing_state'] ?? '')) . ' ' . $h((string)($client['billing_zip'] ?? '')) : '' ?></dd><?php endif; ?>
      <?php if ($hours): ?>
      <dt>🕒 Hours</dt>
      <dd>
        <?php foreach (['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $k=>$lbl):
          if (empty($hours[$k]['open']) && empty($hours[$k]['close'])) continue; ?>
          <div><?= $lbl ?>: <?= $h($hours[$k]['open']) ?> – <?= $h($hours[$k]['close']) ?></div>
        <?php endforeach; ?>
      </dd>
      <?php endif; ?>
    </dl>
    <div class="mapwrap" style="margin-top:18px;padding:0">
      <div class="mapplaceholder">[Google Maps embed]</div>
    </div>
  </div>
</div></div></section>

<?php break; default: /* HOME */ ?>

<section class="hero"><div class="wrap">
  <h1><?= $h($hero['headline'] ?? $name) ?></h1>
  <p class="sub"><?= $h($hero['subheadline'] ?? '') ?></p>
  <?php if ($phone): ?>
    <a class="big-call" href="tel:<?= $h($phoneTel) ?>"><span><?= $h($hero['cta_primary'] ?? 'Call now') ?></span><span class="digits"><?= $h($phone) ?></span></a>
    <div class="answer-promise"><?= $emergency ? '<span>●</span> Answered 24/7 — even on weekends and holidays' : '<span>●</span> Real human answers, fast callback guaranteed' ?></div>
  <?php endif; ?>
</div></section>

<?php if (!empty($trustStrip)): ?>
<section class="trust-strip"><div class="wrap"><div class="trust-row">
  <?php foreach ($trustStrip as $t): ?>
    <div class="item"><span class="check">✓</span><?= $h((string)$t) ?></div>
  <?php endforeach; ?>
</div></div></section>
<?php endif; ?>

<?php if (!empty($services)): ?>
<section class="services"><div class="wrap">
  <h2>What we fix</h2>
  <p class="sub">Same-day diagnosis. Most jobs done in one visit.</p>
  <div class="svc-grid">
    <?php foreach (array_slice($services, 0, 4) as $svc): ?>
      <div class="svc">
        <div class="icon"><?= $h((string)($svc['icon_emoji'] ?? '🔧')) ?></div>
        <h3><?= $h((string)($svc['name'] ?? '')) ?></h3>
        <div><?= $svc['description_html'] ?? '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($services) > 4): ?>
    <div style="text-align:center;margin-top:24px"><a href="/services.html" style="color:var(--primary);font-weight:700">See all services →</a></div>
  <?php endif; ?>
</div></section>
<?php endif; ?>

<?php if (!empty($about['body_html'])): ?>
<section class="about"><div class="wrap about-content">
  <h2><?= $h((string)($about['title'] ?? 'About us')) ?></h2>
  <?= $about['body_html'] ?>
  <div style="text-align:center;margin-top:20px"><a href="/about.html" style="color:var(--primary);font-weight:700">Read our full story →</a></div>
</div></section>
<?php endif; ?>

<?php if ($areaSummary): ?>
<section class="area"><div class="wrap"><?= $h($areaSummary) ?> · <a href="/service-area.html" style="color:#fff;text-decoration:underline">See full area →</a></div></section>
<?php endif; ?>

<section class="final-cta"><div class="wrap">
  <h2>Got an emergency? Don't wait.</h2>
  <p>Real humans on the line. No robots. No "press 1".</p>
  <?php if ($phone): ?><a href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
</div></section>

<?php endswitch; ?>

<footer class="site"><div class="wrap row">
  <div><strong style="color:#fff"><?= $h($name) ?></strong> · <?= $h((string)($copy['footer_blurb'] ?? '')) ?></div>
  <div>
    <a href="/privacy">Privacy</a>
    <a href="/terms">Terms</a>
    © <?= date('Y') ?>
  </div>
</div></footer>

<!-- CallRail tracking — operator: paste your CallRail swap.js script tag below at onboarding
<script async src="//cdn.callrail.com/companies/YOUR_COMPANY_ID/swap.js"></script>
-->

</body>
</html>
<?php
    return (string)ob_get_clean();
}
