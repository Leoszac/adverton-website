<?php
// Trust-First template — for established contractors with strong reviews.
// Reviews + ratings get hero-adjacent placement.
//
// Multi-page: renders 5 pages (home / about / services / service-area /
// contact) via the $page parameter. Default 'home' preserves backward
// compatibility with single-page callers.
//
// Contract: crm_renderTemplate_trust_first($client, $intake, $copy, $assets,
// $page='home'): string. Returns complete HTML document. No DB. No globals.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_renderTemplate_trust_first(array $client, array $intake, array $copy, array $assets, string $page = 'home'): string {
    $h = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $name      = (string)($intake['display_name']  ?? $client['business_name'] ?? '');
    $tagline   = (string)($intake['tagline']       ?? '');
    $phone     = (string)($client['primary_phone'] ?? '');
    $phoneTel  = preg_replace('/[^0-9+]/', '', $phone);
    $hero      = (array)($copy['hero']        ?? []);
    $about     = (array)($copy['about']       ?? []);
    $services  = (array)($copy['services']    ?? []);
    $faq       = (array)($copy['faq']         ?? []);
    $trustStrip= (array)($copy['trust_strip'] ?? []);
    $footerBlurb = (string)($copy['footer_blurb'] ?? '');

    $colors = (array)($intake['brand_colors_decoded'] ?? []);
    // ?? only handles null/unset; the wizard saves "" for blank inputs, so
    // fall back when empty too — otherwise `--primary:;` renders invalid CSS
    // and the whole color system unravels.
    $primary = !empty($colors['primary']) ? $colors['primary'] : '#6d28d9';
    $accent  = !empty($colors['accent'])  ? $colors['accent']  : '#f59e0b';

    $reviews    = (array)($intake['reviews_links_decoded'] ?? []);
    $googleUrl  = (string)($reviews['google'] ?? '');
    $yearsInBiz = (int)($intake['years_in_business'] ?? 0);
    $licenseNum = (string)($intake['license_number'] ?? '');
    $insurance  = (string)($intake['insurance_carrier'] ?? '');

    // Hours for contact page
    $hours = (array)($intake['hours_regular_decoded'] ?? []);

    // Service area
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

    // Hero image from assets
    $heroImage = '';
    foreach ($assets as $a) {
        if (!empty($a['approved']) && in_array(($a['category'] ?? ''), ['exterior','job','team'], true)) {
            $heroImage = '/clients/' . (int)$client['id'] . '/photos/' . $h($a['category']) . '/' . $h($a['stored_name']);
            break;
        }
    }

    // Page meta (title + description per page)
    $pageMeta = [
        'home'         => [($hero['headline'] ?? $name), ($hero['subheadline'] ?? '')],
        'about'        => ["About — {$name}", "Family-owned, locally trusted. Get to know {$name}."],
        'services'     => ["Services — {$name}", "What we do, how we do it, and how to get started."],
        'service-area' => ["Service Area — {$name}", $areaSummary ?: "Where we work."],
        'contact'      => ["Contact — {$name}", "Reach out for a free estimate or to book a job."],
    ];
    [$pageTitle, $pageDesc] = $pageMeta[$page] ?? $pageMeta['home'];

    // Schema.org address (used in JSON-LD)
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

    // Nav items
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
  :root{--primary:<?= $h($primary) ?>;--accent:<?= $h($accent) ?>;--ink:#0e0d12;--ink-2:#383640;--ink-3:#6b6877;--bg:#fff;--card:#fff;--line:#e7e4ee;--soft:#faf9ff}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif;color:var(--ink);background:var(--bg);line-height:1.55;font-size:17px}
  a{color:var(--primary);text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:0 22px}
  /* Header + nav */
  header.site{position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid var(--line);padding:12px 0}
  header.site .row{display:flex;justify-content:space-between;align-items:center;gap:14px}
  header.site .brand{font-weight:800;font-size:18px;letter-spacing:-0.01em;color:var(--ink)}
  header.site nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  header.site nav a{padding:8px 14px;border-radius:8px;font-size:14px;font-weight:600;color:var(--ink-2)}
  header.site nav a:hover{background:var(--soft);color:var(--primary)}
  header.site nav a.active{background:var(--soft);color:var(--primary)}
  header.site .cta{background:var(--primary);color:#fff;padding:9px 18px;border-radius:8px;font-weight:600;font-size:14px}
  header.site .cta:hover{background:var(--ink)}
  /* Hamburger — CSS-only checkbox hack, no JS */
  header.site .nav-burger-input{display:none}
  header.site .nav-burger{display:none;cursor:pointer;padding:8px 6px;user-select:none;-webkit-tap-highlight-color:transparent}
  header.site .nav-burger span{display:block;width:26px;height:2.5px;background:var(--ink);margin:5px 0;border-radius:2px;transition:transform .25s,opacity .2s}
  @media(max-width:760px){
    header.site .row{flex-wrap:wrap;position:relative}
    header.site .nav-burger{display:inline-flex;flex-direction:column;margin-left:auto}
    header.site nav{display:none !important;width:100%;flex-direction:column;align-items:stretch;gap:0;padding:8px 0 12px;border-top:1px solid var(--line);margin-top:10px}
    header.site .nav-burger-input:checked ~ nav{display:flex !important}
    header.site nav a{padding:12px 14px;font-size:15px;text-align:left;width:100%;border-radius:8px}
    header.site nav a.cta{text-align:center;margin-top:6px}
    header.site .nav-burger-input:checked ~ .nav-burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}
    header.site .nav-burger-input:checked ~ .nav-burger span:nth-child(2){opacity:0}
    header.site .nav-burger-input:checked ~ .nav-burger span:nth-child(3){transform:translateY(-8px) rotate(-45deg)}
    /* Tap targets ≥44px (Apple HIG) — audit found brand/footer/phone <44px */
    header.site .brand{display:inline-flex;align-items:center;min-height:44px;padding:8px 0}
    footer.site a{display:inline-block;padding:14px 14px 14px 0;min-height:44px;line-height:1.2}
    .contact .info dd a{display:inline-block;padding:8px 0;line-height:1.5;min-height:32px}
  }
  /* Page header (non-home) */
  .pagehead{background:linear-gradient(180deg,var(--soft) 0%,#fff 100%);padding:50px 0 40px;text-align:center;border-bottom:1px solid var(--line)}
  .pagehead h1{margin:0 0 8px;font-size:38px;letter-spacing:-0.02em;line-height:1.1;font-weight:800}
  .pagehead .lead{margin:0;color:var(--ink-2);font-size:17px;max-width:720px;margin-left:auto;margin-right:auto}
  /* Hero */
  .hero{background:linear-gradient(180deg,var(--soft) 0%,#fff 100%);padding:48px 0 56px}
  .hero-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:36px;align-items:center}
  @media(max-width:780px){.hero-grid{grid-template-columns:1fr}}
  .hero h1{margin:0 0 12px;font-size:42px;letter-spacing:-0.02em;line-height:1.1;font-weight:800}
  .hero .sub{font-size:18px;color:var(--ink-2);margin:0 0 22px}
  .hero .ctas{display:flex;gap:10px;flex-wrap:wrap}
  .btn-primary{display:inline-block;background:var(--primary);color:#fff;padding:13px 22px;border-radius:9px;font-weight:700;font-size:15px;border:0;cursor:pointer;font-family:inherit}
  .btn-secondary{display:inline-block;background:#fff;color:var(--primary);padding:12px 22px;border-radius:9px;font-weight:700;font-size:15px;border:1.5px solid var(--primary)}
  .hero-img{width:100%;height:340px;object-fit:cover;border-radius:14px;background:#ece9f3}
  .hero-img-placeholder{width:100%;height:340px;border-radius:14px;background:linear-gradient(135deg,var(--accent) 0%,var(--primary) 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:700;text-align:center;padding:24px}
  /* Trust strip */
  .trust-strip{background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:18px 0}
  .trust-row{display:flex;justify-content:center;gap:36px;flex-wrap:wrap;align-items:center;font-size:14px;color:var(--ink-2);font-weight:600}
  .trust-row .item{display:flex;align-items:center;gap:8px}
  .trust-row .check{width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
  /* Reviews */
  .reviews{padding:60px 0;background:#fff}
  .reviews .lead{text-align:center;margin-bottom:34px}
  .reviews h2{margin:0 0 8px;font-size:32px;letter-spacing:-0.01em}
  .reviews p{margin:0;color:var(--ink-3)}
  .review-cta{text-align:center;margin-top:22px}
  .review-cta a{display:inline-block;background:#fff;color:var(--primary);border:1.5px solid var(--primary);padding:11px 22px;border-radius:9px;font-weight:700;font-size:14px}
  .stars{font-size:24px;color:var(--accent);letter-spacing:2px;margin-bottom:6px}
  /* Services */
  .services{padding:64px 0;background:var(--soft)}
  .services h2{text-align:center;margin:0 0 8px;font-size:32px;letter-spacing:-0.01em}
  .services .sub{text-align:center;color:var(--ink-3);margin:0 0 34px}
  .svc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}
  .svc{background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px}
  .svc .icon{font-size:28px;margin-bottom:8px}
  .svc h3{margin:0 0 8px;font-size:18px}
  .svc p{margin:0;color:var(--ink-2);font-size:14px}
  /* Process steps */
  .process{padding:60px 0;background:#fff}
  .process h2{text-align:center;margin:0 0 36px;font-size:30px;letter-spacing:-0.01em}
  .steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;max-width:1000px;margin:0 auto}
  .step{background:var(--soft);border-radius:12px;padding:24px}
  .step .num{display:inline-block;background:var(--primary);color:#fff;width:32px;height:32px;border-radius:50%;text-align:center;line-height:32px;font-weight:700;margin-bottom:10px}
  .step h3{margin:0 0 6px;font-size:17px}
  .step p{margin:0;color:var(--ink-2);font-size:14px}
  /* About */
  .about{padding:64px 0;background:#fff}
  .about h2{margin:0 0 16px;font-size:30px;letter-spacing:-0.01em}
  .about-content{max-width:760px;margin:0 auto;font-size:16px;color:var(--ink-2)}
  /* Trust badges (about page) */
  .trust-badges{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;max-width:880px;margin:30px auto 0}
  .badge{background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:18px;text-align:center}
  .badge .icon{font-size:24px;margin-bottom:6px}
  .badge strong{display:block;font-size:14px;color:var(--ink);margin-bottom:2px}
  .badge span{font-size:13px;color:var(--ink-3)}
  /* Service area */
  .area{padding:36px 0;background:var(--soft);text-align:center;color:var(--ink-2);font-size:15px;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
  .areagrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;max-width:920px;margin:0 auto;padding:0 22px}
  .areacity{background:#fff;border:1px solid var(--line);border-radius:8px;padding:11px 14px;text-align:center;font-weight:600;font-size:14px;color:var(--ink)}
  .mapwrap{max-width:1000px;margin:30px auto 0;padding:0 22px}
  .mapplaceholder{width:100%;height:320px;border:2px dashed var(--line);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--ink-3);font-size:14px;background:var(--soft)}
  /* FAQ */
  .faq{padding:64px 0;background:#fff}
  .faq h2{text-align:center;margin:0 0 28px;font-size:30px;letter-spacing:-0.01em}
  .faq-item{max-width:760px;margin:0 auto 12px;border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden}
  .faq-item summary{padding:16px 20px;font-weight:600;cursor:pointer;list-style:none;font-size:15px;display:flex;justify-content:space-between;align-items:center}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item summary::after{content:'+';font-size:20px;color:var(--ink-3)}
  .faq-item[open] summary::after{content:'−'}
  .faq-item .answer{padding:0 20px 18px;color:var(--ink-2);font-size:15px}
  /* Contact form */
  .contact{padding:64px 0;background:var(--soft);border-top:1px solid var(--line)}
  .contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:start}
  @media(max-width:780px){.contact-grid{grid-template-columns:1fr}}
  .contact h2{margin:0 0 12px;font-size:30px;letter-spacing:-0.01em}
  .contact .info{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px;font-size:15px}
  .contact .info dt{font-weight:700;color:var(--ink);margin-top:14px}
  .contact .info dt:first-child{margin-top:0}
  .contact .info dd{margin:4px 0 0;color:var(--ink-2)}
  .form{display:flex;flex-direction:column;gap:12px}
  .form input,.form textarea{width:100%;padding:13px 15px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:16px;box-sizing:border-box;background:#fff}
  .form input:focus,.form textarea:focus{outline:none;border-color:var(--primary)}
  .form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:520px){.form .row2{grid-template-columns:1fr}}
  /* Final CTA banner */
  .cta-banner{padding:50px 0;background:var(--primary);color:#fff;text-align:center}
  .cta-banner h2{margin:0 0 12px;font-size:28px;letter-spacing:-0.01em}
  .cta-banner p{margin:0 0 22px;color:rgba(255,255,255,0.9);font-size:16px}
  .cta-banner .btn{display:inline-block;background:#fff;color:var(--primary);padding:14px 26px;border-radius:9px;font-weight:700;font-size:16px}
  /* Footer */
  footer.site{background:#fff;border-top:1px solid var(--line);padding:24px 0;color:var(--ink-3);font-size:13px}
  footer.site .row{display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;align-items:center}
  footer.site a{color:inherit;margin-right:14px}
  @media(max-width:520px){.hero h1{font-size:32px}.hero .sub{font-size:16px}.hero-img,.hero-img-placeholder{height:240px}.pagehead h1{font-size:28px}.services h2,.reviews h2,.about h2,.faq h2,.contact h2,.cta-banner h2,.process h2{font-size:24px}}
</style>
</head>
<body>

<!-- Top nav (every page) -->
<header class="site"><div class="wrap row">
  <a href="/" class="brand"><?= $h($name) ?></a>
  <input type="checkbox" id="nav-burger" class="nav-burger-input" aria-hidden="true">
  <label for="nav-burger" class="nav-burger" aria-label="Toggle menu"><span></span><span></span><span></span></label>
  <nav>
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= $h($item['href']) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= $h($item['label']) ?></a>
    <?php endforeach; ?>
    <?php if ($phone): ?><a class="cta" href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
  </nav>
</div></header>

<?php switch ($page):
    case 'about': ?>

<section class="pagehead"><div class="wrap">
  <h1><?= $h($about['title'] ?? "About {$name}") ?></h1>
  <p class="lead">Family-owned and locally trusted<?= $yearsInBiz ? " for {$yearsInBiz}+ years" : "" ?>.</p>
</div></section>

<section class="about"><div class="wrap about-content">
  <?= $about['body_html'] ?? '<p>Our story is coming soon.</p>' ?>
  <div class="trust-badges">
    <?php if ($yearsInBiz): ?><div class="badge"><div class="icon">🏆</div><strong><?= $yearsInBiz ?>+ years</strong><span>In business</span></div><?php endif; ?>
    <?php if ($licenseNum): ?><div class="badge"><div class="icon">📜</div><strong>Licensed</strong><span><?= $h($licenseNum) ?></span></div><?php endif; ?>
    <?php if ($insurance): ?><div class="badge"><div class="icon">🛡️</div><strong>Insured</strong><span><?= $h($insurance) ?></span></div><?php endif; ?>
    <?php if ($googleUrl): ?><div class="badge"><div class="icon">⭐</div><strong>Top reviews</strong><span>On Google</span></div><?php endif; ?>
  </div>
</div></section>

<section class="cta-banner"><div class="wrap">
  <h2>Let's talk about your project</h2>
  <p>We'll come out, listen, and tell you straight if it's a fit.</p>
  <a class="btn" href="/contact.html">Get a free estimate →</a>
</div></section>

<?php break; case 'services': ?>

<section class="pagehead"><div class="wrap">
  <h1>What we do</h1>
  <p class="lead">Honest pricing, on-time service, and we clean up after ourselves.</p>
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

<section class="process"><div class="wrap">
  <h2>How we work</h2>
  <div class="steps">
    <div class="step"><div class="num">1</div><h3>Free estimate</h3><p>We come out, look at the job, listen. No pressure.</p></div>
    <div class="step"><div class="num">2</div><h3>Detailed scope</h3><p>Plain-English plan + flat price. No surprises later.</p></div>
    <div class="step"><div class="num">3</div><h3>The work begins</h3><p>Same crew start to finish. We protect your space.</p></div>
    <div class="step"><div class="num">4</div><h3>Final walkthrough</h3><p>You sign off when it's right. We stand behind every job.</p></div>
  </div>
</div></section>

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

<section class="cta-banner"><div class="wrap">
  <h2>Ready to get started?</h2>
  <p>Free estimates. Same-day response on most requests.</p>
  <a class="btn" href="/contact.html">Get a free estimate →</a>
</div></section>

<?php break; case 'service-area': ?>

<section class="pagehead"><div class="wrap">
  <h1>Where we work</h1>
  <p class="lead"><?= $h($areaSummary) ?: 'We serve the local area.' ?></p>
</div></section>

<?php if ($areaCities): ?>
<section style="padding:50px 0"><div class="wrap">
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
  <h2>Don't see your city?</h2>
  <p>We sometimes take jobs outside our usual area. Give us a call — if it's not the right fit, we'll point you to someone who is.</p>
</div></section>

<section class="cta-banner"><div class="wrap">
  <h2>Need service in your area?</h2>
  <?php if ($phone): ?><p>Call us directly or send a message.</p><?php endif; ?>
  <a class="btn" href="/contact.html">Get in touch →</a>
</div></section>

<?php break; case 'contact': ?>

<section class="pagehead"><div class="wrap">
  <h1>Get in touch</h1>
  <p class="lead">Free estimates. We'll get back to you within one business day.</p>
</div></section>

<section class="contact"><div class="wrap"><div class="contact-grid">
  <div>
    <h2>Send us a message</h2>
    <form action="https://adverton.net/crm/client-form-submit.php?client_id=<?= (int)$client['id'] ?>" method="post" class="form">
      <input type="hidden" name="redirect" value="1">
      <input type="text" name="hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <input type="text" name="name" placeholder="Your name" required>
      <div class="row2">
        <input type="tel" name="phone" placeholder="Phone" required>
        <input type="email" name="email" placeholder="Email">
      </div>
      <textarea name="message" placeholder="What do you need help with?" rows="4"></textarea>
      <button type="submit" class="btn-primary">Send →</button>
    </form>
  </div>
  <div>
    <h2>Or reach us directly</h2>
    <dl class="info">
      <?php if ($phone): ?><dt>📞 Phone</dt><dd><a href="tel:<?= $h($phoneTel) ?>" style="font-weight:700"><?= $h($phone) ?></a></dd><?php endif; ?>
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

<section class="hero"><div class="wrap hero-grid">
  <div>
    <?php if ($tagline): ?><div style="font-size:13px;color:var(--primary);font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px"><?= $h($tagline) ?></div><?php endif; ?>
    <h1><?= $h($hero['headline'] ?? $name) ?></h1>
    <p class="sub"><?= $h($hero['subheadline'] ?? '') ?></p>
    <div class="ctas">
      <?php if ($phone): ?><a class="btn-primary" href="tel:<?= $h($phoneTel) ?>"><?= $h($hero['cta_primary'] ?? 'Call now') ?></a><?php endif; ?>
      <a class="btn-secondary" href="/contact.html"><?= $h($hero['cta_secondary'] ?? 'Get a quote') ?></a>
    </div>
  </div>
  <div>
    <?php if ($heroImage): ?>
      <img class="hero-img" src="<?= $h($heroImage) ?>" alt="<?= $h($name) ?>">
    <?php else: ?>
      <div class="hero-img-placeholder"><?= $h($name) ?></div>
    <?php endif; ?>
  </div>
</div></section>

<?php if (!empty($trustStrip)): ?>
<section class="trust-strip"><div class="wrap"><div class="trust-row">
  <?php foreach ($trustStrip as $t): ?>
    <div class="item"><span class="check">✓</span><?= $h((string)$t) ?></div>
  <?php endforeach; ?>
</div></div></section>
<?php endif; ?>

<?php if ($googleUrl || $yearsInBiz): ?>
<section class="reviews"><div class="wrap">
  <div class="lead">
    <div class="stars">★★★★★</div>
    <h2>What our customers say</h2>
    <p><?= $yearsInBiz ? $yearsInBiz . ' years of trusted service' : 'Real reviews from local customers' ?>.</p>
  </div>
  <?php if ($googleUrl): ?>
  <div class="review-cta">
    <a href="<?= $h($googleUrl) ?>" target="_blank" rel="noopener">See reviews on Google →</a>
  </div>
  <?php endif; ?>
</div></section>
<?php endif; ?>

<?php if (!empty($services)): ?>
<section class="services"><div class="wrap">
  <h2>What we do</h2>
  <p class="sub">Honest pricing, on-time service, and we clean up after ourselves.</p>
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
    <div style="text-align:center;margin-top:24px"><a href="/services.html" class="btn-secondary">See all services →</a></div>
  <?php endif; ?>
</div></section>
<?php endif; ?>

<?php if (!empty($about['body_html'])): ?>
<section class="about"><div class="wrap about-content">
  <h2><?= $h((string)($about['title'] ?? 'About us')) ?></h2>
  <?= $about['body_html'] ?>
  <div style="margin-top:20px"><a href="/about.html">Read our full story →</a></div>
</div></section>
<?php endif; ?>

<?php if ($areaSummary): ?>
<section class="area"><div class="wrap"><?= $h($areaSummary) ?> · <a href="/service-area.html">See full area →</a></div></section>
<?php endif; ?>

<section class="cta-banner"><div class="wrap">
  <h2>Ready to get started?</h2>
  <p>Free estimates. Same-day response on most requests.</p>
  <?php if ($phone): ?><a class="btn" href="tel:<?= $h($phoneTel) ?>">📞 Call <?= $h($phone) ?></a><?php endif; ?>
</div></section>

<?php endswitch; ?>

<!-- Footer (every page) -->
<footer class="site"><div class="wrap row">
  <div><strong><?= $h($name) ?></strong> · <?= $h($footerBlurb) ?></div>
  <div>
    <a href="/privacy">Privacy</a>
    <a href="/terms">Terms</a>
    © <?= date('Y') ?> <?= $h($name) ?>
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
