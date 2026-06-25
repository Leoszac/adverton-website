<?php
// SEO-Local template — programmatic local-SEO site modeled on the
// rocklandcountygenerac.com structure: one landing page per service AND one
// per city, each with unique copy, for maximum "{service} in {city}" reach.
//
// Multi-page via the $page parameter. Page keys:
//   home | services | service:<slug> | locations | location:<slug>
//   reviews | contact
// The "service:" / "location:" prefix carries the slug of the individual
// page; crm_renderAllPages (lib/preview.php) builds the matching file map
// (services/<slug>.html, locations/<slug>.html) from the SAME slug helpers,
// so hrefs and uploaded filenames always agree.
//
// Contract: crm_renderTemplate_seo_local($client, $intake, $copy, $assets,
// $page='home'): string. Returns a complete HTML document. No DB. No globals.
//
// PHP 7.4-safe (no match/nullsafe/str_contains) — mirrors the other templates;
// some render paths are reachable from CLI/cron loaders.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_renderTemplate_seo_local(array $client, array $intake, array $copy, array $assets, string $page = 'home'): string {
    $h = static function (?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    // Local slug helper — prefer the shared one from lib/preview.php so the
    // hrefs here match the deployed filenames exactly; fall back if loaded
    // in isolation.
    $slug = static function (string $s): string {
        if (function_exists('crm_slugify')) return crm_slugify($s);
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string)$s, '-');
    };

    $name      = (string)($intake['display_name']  ?? $client['business_name'] ?? '');
    $tagline   = (string)($intake['tagline']       ?? '');
    $phone     = (string)($client['primary_phone'] ?? '');
    $phoneTel  = preg_replace('/[^0-9+]/', '', $phone);
    $hero      = (array)($copy['hero']         ?? []);
    $about     = (array)($copy['about']        ?? []);
    $services  = (array)($copy['services']     ?? []);
    $faq       = (array)($copy['faq']          ?? []);
    $trustStrip= (array)($copy['trust_strip']  ?? []);
    $footerBlurb = (string)($copy['footer_blurb'] ?? '');
    $aiLocations = (array)($copy['locations']  ?? []);  // [{city, blurb_html}]

    // Brand colors: default to the reference palette (navy primary + green
    // accent). Client-supplied colors win when present (same guard as the
    // other templates — "" must fall back, not just null).
    $colors  = (array)($intake['brand_colors_decoded'] ?? []);
    $primary = !empty($colors['primary']) ? $colors['primary'] : 'hsl(215,70%,18%)';
    $accent  = !empty($colors['accent'])  ? $colors['accent']  : 'hsl(142,55%,35%)';

    $reviewsLinks = (array)($intake['reviews_links_decoded'] ?? []);
    $googleUrl    = (string)($reviewsLinks['google'] ?? '');
    $reviewCards  = (array)($reviewsLinks['cards'] ?? []);   // client-pasted real reviews
    $yearsInBiz   = (int)($intake['years_in_business'] ?? 0);
    $licenseNum   = (string)($intake['license_number'] ?? '');
    $insurance    = (string)($intake['insurance_carrier'] ?? '');
    $hours        = (array)($intake['hours_regular_decoded'] ?? []);

    // Service area cities (only the "cities" mode yields per-city pages).
    $area = (array)($intake['service_area_decoded'] ?? []);
    $areaCities = ($area['type'] ?? '') === 'cities' && !empty($area['cities'])
        ? array_values(array_filter(array_map('trim', (array)$area['cities']), 'strlen'))
        : [];

    // Deduped slug maps [slug => name] — MUST mirror crm_pageFilenameMap().
    $citySlugs = (function_exists('crm_seoLocalCitySlugs'))
        ? crm_seoLocalCitySlugs($intake)
        : array_combine(array_map($slug, $areaCities), $areaCities);
    $serviceSlugs = (function_exists('crm_seoLocalServiceSlugs'))
        ? crm_seoLocalServiceSlugs($intake)
        : [];
    if (!$serviceSlugs) {
        foreach ($services as $s) {
            $n = trim((string)($s['name'] ?? ''));
            if ($n !== '') $serviceSlugs[$slug($n)] = $n;
        }
    }

    // Lookups: service copy by name; per-city AI blurb by slugified city.
    $svcByName = [];
    foreach ($services as $s) {
        $n = trim((string)($s['name'] ?? ''));
        if ($n !== '') $svcByName[$n] = $s;
    }
    $cityBlurbBySlug = [];
    foreach ($aiLocations as $loc) {
        $city = trim((string)($loc['city'] ?? ''));
        if ($city === '') continue;
        $cityBlurbBySlug[$slug($city)] = (string)($loc['blurb_html'] ?? '');
    }

    // Primary service name (used in headlines on city pages).
    $firstServiceName = '';
    foreach ($serviceSlugs as $sName) { $firstServiceName = (string)$sName; break; }
    $offering = $firstServiceName !== '' ? $firstServiceName : 'Professional Service';

    // Resolve programmatic page: "location:monsey-ny" → type=location slug=monsey-ny
    $pageType = $page;
    $pageSlug = '';
    $colon = strpos($page, ':');
    if ($colon !== false) {
        $pageType = substr($page, 0, $colon);
        $pageSlug = substr($page, $colon + 1);
    }
    $currentCity    = ($pageType === 'location' && isset($citySlugs[$pageSlug])) ? (string)$citySlugs[$pageSlug] : '';
    $currentSvcName = ($pageType === 'service'  && isset($serviceSlugs[$pageSlug])) ? (string)$serviceSlugs[$pageSlug] : '';
    $currentSvc     = ($currentSvcName !== '' && isset($svcByName[$currentSvcName])) ? $svcByName[$currentSvcName] : [];

    // Hero image from approved assets.
    $heroImage = '';
    foreach ($assets as $a) {
        if (!empty($a['approved']) && in_array(($a['category'] ?? ''), ['exterior', 'job', 'team'], true)) {
            $heroImage = '/crm/asset.php?id=' . (int)$a['id'];
            break;
        }
    }

    // Logo from approved assets (category 'logo'). Shown in the header instead
    // of the text business name when present.
    $logoImage = '';
    foreach ($assets as $a) {
        if (!empty($a['approved']) && ($a['category'] ?? '') === 'logo') {
            $logoImage = '/crm/asset.php?id=' . (int)$a['id'];
            break;
        }
    }

    // Href helpers
    $svcHref = static function (string $s) { return '/services/' . $s . '.html'; };
    $cityHref = static function (string $s) { return '/locations/' . $s . '.html'; };

    // Per-page <title>/description.
    $metaTitle = $name; $metaDesc = (string)($hero['subheadline'] ?? '');
    if ($pageType === 'services')      { $metaTitle = "Services — {$name}"; $metaDesc = "Everything {$name} does, with a dedicated page for each service."; }
    elseif ($pageType === 'service')   { $metaTitle = "{$currentSvcName} — {$name}"; $metaDesc = strip_tags((string)($currentSvc['description_html'] ?? "Professional {$currentSvcName} from {$name}.")); }
    elseif ($pageType === 'locations') { $metaTitle = "Service Areas — {$name}"; $metaDesc = "The towns and cities {$name} serves."; }
    elseif ($pageType === 'location')  { $metaTitle = "{$offering} in {$currentCity} | {$name}"; $metaDesc = "Local {$offering} in {$currentCity}. " . ($phone ? "Call {$phone} for a free quote." : ''); }
    elseif ($pageType === 'reviews')   { $metaTitle = "Reviews — {$name}"; $metaDesc = "What local customers say about {$name}."; }
    elseif ($pageType === 'contact')   { $metaTitle = "Contact — {$name}"; $metaDesc = "Get a free quote from {$name}."; }
    $metaDesc = trim($metaDesc);

    // Schema.org address + LocalBusiness JSON-LD.
    $schemaAddr = trim(implode(', ', array_filter([
        (string)($client['billing_address'] ?? ''),
        (string)($client['billing_city'] ?? ''),
        trim((string)($client['billing_state'] ?? '') . ' ' . (string)($client['billing_zip'] ?? '')),
    ])));
    $_schema = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name, 'description' => $metaDesc];
    if ($phone)      $_schema['telephone'] = $phone;
    if ($schemaAddr) $_schema['address']   = ['@type' => 'PostalAddress', 'streetAddress' => $schemaAddr];
    if ($currentCity) {
        $_schema['areaServed'] = ['@type' => 'City', 'name' => $currentCity];
    } elseif ($areaCities) {
        $_schema['areaServed'] = array_map(static function ($c) { return ['@type' => 'City', 'name' => $c]; }, $areaCities);
    }
    $_svcNames = array_values($serviceSlugs);
    if ($_svcNames) $_schema['makesOffer'] = array_map(static function ($s) { return ['@type' => 'Offer', 'name' => $s]; }, $_svcNames);
    if ($googleUrl) $_schema['sameAs'] = [$googleUrl];

    // Top nav (Service Areas omitted from nav if there are no cities).
    $nav = ['home' => ['Home', '/'], 'services' => ['Services', '/services.html']];
    if ($areaCities) $nav['locations'] = ['Service Areas', '/locations.html'];
    $nav['reviews'] = ['Reviews', '/reviews.html'];
    $nav['contact'] = ['Contact', '/contact.html'];
    $navActive = $pageType;  // home/services/locations/reviews/contact; service+location highlight Services/Service Areas
    if ($pageType === 'service')  $navActive = 'services';
    if ($pageType === 'location') $navActive = 'locations';

    ob_start();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $h($metaTitle) ?></title>
<meta name="description" content="<?= $h($metaDesc) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet">
<script type="application/ld+json"><?= json_encode($_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<style>
  :root{
    --primary:<?= $h($primary) ?>;--accent:<?= $h($accent) ?>;
    --navy-2:hsl(215,75%,28%);--amber:#f59e0b;--amber-l:#fbbf24;--red:#dc2626;
    --ink:#1f2937;--ink-2:#4b5563;--ink-3:#6b7280;--bg:#fff;--soft:#f6f8fb;--line:#e5e7eb;--navy-soft:#eef2f8;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:var(--ink);background:var(--bg);line-height:1.6;font-size:17px}
  h1,h2,h3,.brand{font-family:'Montserrat',sans-serif}
  a{color:var(--primary);text-decoration:none}
  .wrap{max-width:1120px;margin:0 auto;padding:0 22px}
  /* Header + nav */
  header.site{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--line);padding:12px 0;box-shadow:0 1px 3px rgba(16,24,40,.04)}
  header.site .row{display:flex;justify-content:space-between;align-items:center;gap:14px}
  header.site .brand{font-weight:800;font-size:19px;letter-spacing:-0.01em;color:var(--primary)}
  header.site .brand-logo{height:58px;width:auto;max-width:300px;display:block;object-fit:contain}
  @media(max-width:600px){ header.site .brand-logo{height:46px;max-width:200px} }
  header.site nav{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
  header.site nav a{padding:9px 14px;border-radius:8px;font-size:14px;font-weight:600;color:var(--ink-2)}
  header.site nav a:hover,header.site nav a.active{background:var(--navy-soft);color:var(--primary)}
  header.site .cta{background:var(--accent);color:#fff !important;padding:10px 18px;border-radius:8px;font-weight:700;font-size:14px}
  header.site .cta:hover{filter:brightness(.93)}
  /* Hamburger — CSS-only, no JS */
  .nav-burger-input{display:none}
  .nav-burger{display:none;cursor:pointer;padding:8px 6px;user-select:none;-webkit-tap-highlight-color:transparent}
  .nav-burger span{display:block;width:26px;height:2.5px;background:var(--primary);margin:5px 0;border-radius:2px;transition:transform .25s,opacity .2s}
  @media(max-width:820px){
    header.site .row{flex-wrap:wrap;position:relative}
    .nav-burger{display:inline-flex;flex-direction:column;margin-left:auto}
    header.site nav{display:none !important;width:100%;flex-direction:column;align-items:stretch;gap:0;padding:8px 0 12px;border-top:1px solid var(--line);margin-top:10px}
    .nav-burger-input:checked ~ nav{display:flex !important}
    header.site nav a{padding:13px 14px;font-size:15px;width:100%}
    header.site nav a.cta{text-align:center;margin-top:6px}
    .nav-burger-input:checked ~ .nav-burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}
    .nav-burger-input:checked ~ .nav-burger span:nth-child(2){opacity:0}
    .nav-burger-input:checked ~ .nav-burger span:nth-child(3){transform:translateY(-8px) rotate(-45deg)}
    header.site .brand{display:inline-flex;align-items:center;min-height:44px}
  }
  /* Hero (navy gradient, reference-style) */
  .hero{position:relative;background:linear-gradient(135deg,var(--primary) 0%,var(--navy-2) 55%,var(--accent) 140%);color:#fff;padding:72px 0 80px;overflow:hidden}
  .hero.with-img{padding:0}
  .hero .hero-inner{position:relative;z-index:2;max-width:760px}
  .hero .badge{display:inline-block;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);color:#fff;font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:7px 14px;border-radius:999px;margin-bottom:18px}
  .hero h1{margin:0 0 16px;font-size:46px;line-height:1.08;letter-spacing:-0.02em;font-weight:800}
  .hero .sub{font-size:19px;color:rgba(255,255,255,.92);margin:0 0 26px;max-width:620px}
  .hero-img-bg{position:absolute;inset:0;z-index:1;background-size:cover;background-position:center}
  .hero-img-bg::after{content:'';position:absolute;inset:0;background:linear-gradient(120deg,rgba(13,28,54,.92) 0%,rgba(13,28,54,.72) 55%,rgba(13,28,54,.45) 100%)}
  .hero.with-img .hero-pad{position:relative;z-index:2;padding:72px 0 80px}
  .ctas{display:flex;gap:12px;flex-wrap:wrap}
  .btn{display:inline-block;padding:14px 26px;border-radius:10px;font-weight:700;font-size:15.5px;border:0;cursor:pointer;font-family:inherit}
  .btn-call{background:var(--accent);color:#fff}
  .btn-call:hover{filter:brightness(.93)}
  .btn-ghost{background:rgba(255,255,255,.12);color:#fff;border:1.5px solid rgba(255,255,255,.5)}
  .btn-ghost:hover{background:rgba(255,255,255,.2)}
  .btn-primary{background:var(--primary);color:#fff}
  .btn-outline{background:#fff;color:var(--primary);border:1.5px solid var(--primary)}
  /* Trust strip */
  .trust-strip{background:var(--primary);color:#fff;padding:14px 0;font-size:14px}
  .trust-strip .row{display:flex;justify-content:center;gap:30px;flex-wrap:wrap;font-weight:600}
  .trust-strip .item{display:flex;align-items:center;gap:8px}
  .trust-strip .dot{color:var(--amber-l)}
  /* Page header (interior pages) */
  .pagehead{background:linear-gradient(135deg,var(--primary) 0%,var(--navy-2) 100%);color:#fff;padding:56px 0 48px}
  .pagehead .crumb{font-size:13px;color:rgba(255,255,255,.7);margin-bottom:10px;font-weight:600}
  .pagehead .crumb a{color:rgba(255,255,255,.85)}
  .pagehead h1{margin:0 0 10px;font-size:40px;line-height:1.1;letter-spacing:-0.02em;font-weight:800}
  .pagehead .lead{margin:0;font-size:18px;color:rgba(255,255,255,.9);max-width:720px}
  /* Sections */
  section.block{padding:64px 0}
  section.soft{background:var(--soft)}
  .sec-head{text-align:center;max-width:720px;margin:0 auto 40px}
  .sec-head h2{margin:0 0 10px;font-size:32px;letter-spacing:-0.01em}
  .sec-head p{margin:0;color:var(--ink-3);font-size:17px}
  .eyebrow{display:inline-block;color:var(--accent);font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
  /* Service grid */
  .svc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}
  .svc-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px;transition:transform .15s,box-shadow .15s;display:block;color:inherit}
  .svc-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(16,24,40,.10);border-color:transparent}
  .svc-card .ic{font-size:30px;margin-bottom:12px}
  .svc-card h3{margin:0 0 8px;font-size:19px;color:var(--ink)}
  .svc-card p{margin:0 0 14px;color:var(--ink-2);font-size:14.5px}
  .svc-card .more{color:var(--accent);font-weight:700;font-size:14px}
  /* City chips */
  .city-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
  .city-chip{background:#fff;border:1px solid var(--line);border-radius:10px;padding:13px 16px;font-weight:600;font-size:14.5px;color:var(--ink);display:flex;align-items:center;justify-content:space-between;gap:8px}
  .city-chip:hover{border-color:var(--accent);color:var(--accent)}
  .city-chip .arr{color:var(--ink-3);font-weight:700}
  .city-chip:hover .arr{color:var(--accent)}
  /* Reviews */
  .rev-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px}
  .rev-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px}
  .rev-card .stars{color:var(--amber);font-size:18px;letter-spacing:1px;margin-bottom:12px}
  .rev-card p{margin:0 0 14px;font-size:15px;line-height:1.65;color:var(--ink-2)}
  .rev-card .who{font-weight:700;font-size:14px;color:var(--ink)}
  .rev-card .loc{font-size:13px;color:var(--ink-3)}
  .center{text-align:center}
  .mt24{margin-top:24px}
  /* Article body (service/city pages) */
  .article{max-width:760px;margin:0 auto;font-size:17px;color:var(--ink-2)}
  .article h2{color:var(--ink);font-size:26px;margin:34px 0 12px}
  .article p{margin:0 0 16px}
  .article ul{margin:0 0 16px;padding-left:20px}
  /* Steps */
  .steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;max-width:1000px;margin:0 auto}
  .step{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px}
  .step .num{display:inline-flex;align-items:center;justify-content:center;background:var(--accent);color:#fff;width:36px;height:36px;border-radius:50%;font-weight:800;margin-bottom:12px}
  .step h3{margin:0 0 6px;font-size:17px}
  .step p{margin:0;color:var(--ink-2);font-size:14.5px}
  /* FAQ */
  .faq-item{max-width:780px;margin:0 auto 12px;border:1px solid var(--line);border-radius:12px;background:#fff;overflow:hidden}
  .faq-item summary{padding:18px 22px;font-weight:700;cursor:pointer;list-style:none;font-size:16px;display:flex;justify-content:space-between;gap:14px;font-family:'Montserrat',sans-serif}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item summary::after{content:'+';color:var(--accent);font-size:22px;line-height:1}
  .faq-item[open] summary::after{content:'\2212'}
  .faq-item .ans{padding:0 22px 20px;color:var(--ink-2);font-size:15.5px}
  /* Contact */
  .contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:start}
  @media(max-width:780px){.contact-grid{grid-template-columns:1fr}}
  .info-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px}
  .info-card dt{font-weight:700;color:var(--ink);margin-top:16px;font-size:14px;text-transform:uppercase;letter-spacing:.04em}
  .info-card dt:first-child{margin-top:0}
  .info-card dd{margin:4px 0 0;color:var(--ink-2)}
  .form{display:flex;flex-direction:column;gap:12px}
  .form input,.form textarea{width:100%;padding:14px 15px;border:1px solid var(--line);border-radius:10px;font-family:inherit;font-size:16px;background:#fff}
  .form input:focus,.form textarea:focus{outline:none;border-color:var(--accent)}
  .form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:520px){.form .row2{grid-template-columns:1fr}}
  .phone-big{display:inline-block;background:var(--accent);color:#fff;padding:18px 40px;border-radius:12px;font-size:26px;font-weight:800;font-family:'Montserrat',sans-serif}
  /* CTA banner */
  .cta-banner{background:linear-gradient(135deg,var(--primary) 0%,var(--navy-2) 100%);color:#fff;text-align:center;padding:56px 0}
  .cta-banner h2{margin:0 0 12px;font-size:30px}
  .cta-banner p{margin:0 0 24px;color:rgba(255,255,255,.9);font-size:17px}
  .map{width:100%;height:360px;border:0;display:block}
  /* Footer */
  footer.site{background:#0f1b2e;color:#9fb0c7;padding:42px 0 28px;font-size:14px}
  footer.site .cols{display:grid;grid-template-columns:2fr 1fr 1fr;gap:30px;margin-bottom:28px}
  @media(max-width:680px){footer.site .cols{grid-template-columns:1fr;gap:22px}}
  footer.site h4{color:#fff;font-size:15px;margin:0 0 12px;font-family:'Montserrat',sans-serif}
  footer.site a{color:#9fb0c7;display:block;padding:5px 0}
  footer.site a:hover{color:#fff}
  footer.site .brandline{color:#fff;font-weight:800;font-size:18px;font-family:'Montserrat',sans-serif;margin-bottom:8px}
  footer.site .legal{border-top:1px solid rgba(255,255,255,.1);padding-top:18px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;font-size:13px}
  @media(max-width:560px){
    .hero h1{font-size:34px}.hero .sub{font-size:17px}.pagehead h1{font-size:30px}
    .sec-head h2,.cta-banner h2{font-size:25px}
    footer.site a{padding:9px 0}
  }
</style>
</head>
<body>

<header class="site"><div class="wrap row">
  <a href="/" class="brand"><?php if ($logoImage): ?><img class="brand-logo" src="<?= $h($logoImage) ?>" alt="<?= $h($name) ?>"><?php else: ?><?= $h($name) ?><?php endif; ?></a>
  <input type="checkbox" id="nav-burger" class="nav-burger-input" aria-hidden="true">
  <label for="nav-burger" class="nav-burger" aria-label="Toggle menu"><span></span><span></span><span></span></label>
  <nav>
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= $h($item[1]) ?>" class="<?= $navActive === $key ? 'active' : '' ?>"><?= $h($item[0]) ?></a>
    <?php endforeach; ?>
    <?php if ($phone): ?><a class="cta" href="tel:<?= $h($phoneTel) ?>">&#9742; <?= $h($phone) ?></a><?php endif; ?>
  </nav>
</div></header>

<?php
// ─── Reusable closures ────────────────────────────────────────────────
$renderTrustStrip = static function () use ($trustStrip, $h) {
    if (!$trustStrip) return;
    echo '<div class="trust-strip"><div class="wrap row">';
    foreach ($trustStrip as $t) {
        echo '<div class="item"><span class="dot">&#10004;</span>' . $h((string)$t) . '</div>';
    }
    echo '</div></div>';
};
$renderServiceCards = static function (array $list, $limit = 0) use ($serviceSlugs, $svcByName, $svcHref, $h) {
    $slugs = $list;
    if ($limit > 0) $slugs = array_slice($slugs, 0, $limit, true);
    echo '<div class="svc-grid">';
    foreach ($slugs as $sg => $sName) {
        $svc = isset($svcByName[$sName]) ? $svcByName[$sName] : [];
        $icon = (string)($svc['icon_emoji'] ?? '&#128295;');
        $desc = (string)($svc['description_html'] ?? '');
        echo '<a class="svc-card" href="' . $h($svcHref($sg)) . '">';
        echo '<div class="ic">' . $h($icon) . '</div>';
        echo '<h3>' . $h((string)$sName) . '</h3>';
        if ($desc !== '') echo '<p>' . strip_tags($desc, '<strong><em>') . '</p>';
        echo '<span class="more">Learn more &rarr;</span></a>';
    }
    echo '</div>';
};
$renderCityChips = static function (array $cmap, $limit = 0) use ($cityHref, $h) {
    $items = $cmap;
    if ($limit > 0) $items = array_slice($items, 0, $limit, true);
    echo '<div class="city-grid">';
    foreach ($items as $sg => $cName) {
        echo '<a class="city-chip" href="' . $h($cityHref($sg)) . '"><span>' . $h((string)$cName) . '</span><span class="arr">&rarr;</span></a>';
    }
    echo '</div>';
};
$renderReviewCards = static function (array $cards, $limit = 0) use ($h) {
    $items = $limit > 0 ? array_slice($cards, 0, $limit) : $cards;
    echo '<div class="rev-grid">';
    foreach ($items as $r) {
        echo '<div class="rev-card"><div class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>';
        echo '<p>&ldquo;' . $h((string)($r['text'] ?? '')) . '&rdquo;</p>';
        echo '<div class="who">' . $h((string)($r['name'] ?? '')) . '</div>';
        if (!empty($r['location'])) echo '<div class="loc">' . $h((string)$r['location']) . '</div>';
        echo '</div>';
    }
    echo '</div>';
};
$mapEmbed = static function (string $q) {
    $u = 'https://maps.google.com/maps?q=' . urlencode($q) . '&output=embed&zoom=11';
    return '<iframe class="map" src="' . $u . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>';
};
$finalCta = static function (string $heading, string $sub) use ($phone, $phoneTel, $h) {
    echo '<section class="cta-banner"><div class="wrap">';
    echo '<h2>' . $h($heading) . '</h2><p>' . $h($sub) . '</p>';
    if ($phone) echo '<a class="btn btn-call" href="tel:' . $h($phoneTel) . '">&#9742; Call ' . $h($phone) . '</a>';
    else echo '<a class="btn btn-call" href="/contact.html">Get a free quote &rarr;</a>';
    echo '</div></section>';
};
?>

<?php switch ($pageType):

// ══════════════════════ SERVICE DETAIL ══════════════════════
case 'service':
    $svcDetail = (string)($currentSvc['detail_html'] ?? '');
    if ($svcDetail === '') $svcDetail = (string)($currentSvc['description_html'] ?? '<p>Contact us to learn more about this service.</p>');
    ?>
  <section class="pagehead"><div class="wrap">
    <div class="crumb"><a href="/services.html">Services</a> &rsaquo; <?= $h($currentSvcName) ?></div>
    <h1><?= $h($currentSvcName) ?></h1>
    <p class="lead"><?= $h(strip_tags((string)($currentSvc['description_html'] ?? ''))) ?: 'Professional service you can count on.' ?></p>
  </div></section>

  <section class="block"><div class="wrap"><div class="article">
    <?= $svcDetail ?>
  </div></div></section>

  <?php if ($areaCities): ?>
  <section class="block soft"><div class="wrap">
    <div class="sec-head"><h2><?= $h($currentSvcName) ?> across our service area</h2>
      <p>We bring <?= $h(strtolower($currentSvcName)) ?> to homeowners throughout the area. Find your town:</p></div>
    <?php $renderCityChips($citySlugs, 12); ?>
    <?php if (count($citySlugs) > 12): ?><div class="center mt24"><a class="btn btn-outline" href="/locations.html">See all areas &rarr;</a></div><?php endif; ?>
  </div></section>
  <?php endif; ?>

  <?php $finalCta('Ready to book ' . $currentSvcName . '?', $phone ? 'Call now for a free quote.' : 'Send us a message for a free quote.'); ?>
<?php break;

// ══════════════════════ SERVICES INDEX ══════════════════════
case 'services':
    ?>
  <section class="pagehead"><div class="wrap">
    <h1>Our Services</h1>
    <p class="lead"><?= $h((string)($about['title'] ?? '')) ?: 'Everything we do — pick a service to learn more.' ?></p>
  </div></section>
  <section class="block"><div class="wrap">
    <?php if ($serviceSlugs) { $renderServiceCards($serviceSlugs); } else { echo '<p class="center">Services coming soon.</p>'; } ?>
  </div></section>
  <?php $finalCta('Not sure what you need?', $phone ? 'Call us and we will point you the right way.' : 'Send a message and we will help.'); ?>
<?php break;

// ══════════════════════ LOCATION DETAIL ══════════════════════
case 'location':
    $blurb = isset($cityBlurbBySlug[$pageSlug]) ? $cityBlurbBySlug[$pageSlug] : '';
    if ($blurb === '') $blurb = '<p>' . $h($name) . ' proudly serves homeowners in ' . $h($currentCity) . ' with reliable, professional service. Call us for a free quote.</p>';
    // Nearby cities = others, capped.
    $others = $citySlugs;
    unset($others[$pageSlug]);
    ?>
  <section class="pagehead"><div class="wrap">
    <div class="crumb"><a href="/locations.html">Service Areas</a> &rsaquo; <?= $h($currentCity) ?></div>
    <h1><?= $h($offering) ?> in <?= $h($currentCity) ?></h1>
    <p class="lead">Local, licensed service for <?= $h($currentCity) ?> homeowners<?= $phone ? ' — call ' . $h($phone) : '' ?>.</p>
  </div></section>

  <section class="block"><div class="wrap"><div class="article">
    <?= $blurb ?>
    <div class="center" style="margin:28px 0">
      <?php if ($phone): ?><a class="btn btn-call" href="tel:<?= $h($phoneTel) ?>">&#9742; Call <?= $h($phone) ?></a><?php endif; ?>
      <a class="btn btn-outline" href="/contact.html">Get a free quote</a>
    </div>
  </div></div></section>

  <?php if ($serviceSlugs): ?>
  <section class="block soft"><div class="wrap">
    <div class="sec-head"><h2>What we do in <?= $h($currentCity) ?></h2></div>
    <?php $renderServiceCards($serviceSlugs, 6); ?>
  </div></section>
  <?php endif; ?>

  <?php if ($reviewCards): ?>
  <section class="block"><div class="wrap">
    <div class="sec-head"><span class="eyebrow">Reviews</span><h2>Trusted by neighbors near you</h2></div>
    <?php $renderReviewCards($reviewCards, 3); ?>
  </div></section>
  <?php endif; ?>

  <?= $mapEmbed($name . ' ' . $currentCity) ?>

  <?php if ($others): ?>
  <section class="block soft"><div class="wrap">
    <div class="sec-head"><h2>Nearby areas we serve</h2></div>
    <?php $renderCityChips($others, 10); ?>
  </div></section>
  <?php endif; ?>

  <?php $finalCta('Need service in ' . $currentCity . '?', $phone ? 'Call now — free quotes.' : 'Send a message for a free quote.'); ?>
<?php break;

// ══════════════════════ LOCATIONS INDEX ══════════════════════
case 'locations':
    ?>
  <section class="pagehead"><div class="wrap">
    <h1>Areas We Serve</h1>
    <p class="lead">Local service across <?= count($citySlugs) ?> communities. Find your town below.</p>
  </div></section>
  <section class="block"><div class="wrap">
    <?php if ($citySlugs) { $renderCityChips($citySlugs); } else { echo '<p class="center">Call us to check if we cover your area.</p>'; } ?>
  </div></section>
  <?php if ($citySlugs): ?><?= $mapEmbed($name . ' ' . (string)reset($citySlugs)) ?><?php endif; ?>
  <?php $finalCta("Don't see your town?", $phone ? 'Give us a call — we may still cover you.' : 'Send a message — we may still cover you.'); ?>
<?php break;

// ══════════════════════ REVIEWS ══════════════════════
case 'reviews':
    ?>
  <section class="pagehead"><div class="wrap">
    <h1>Customer Reviews</h1>
    <p class="lead"><?= $yearsInBiz ? $yearsInBiz . ' years of trusted local service.' : 'What our customers say.' ?></p>
  </div></section>
  <section class="block"><div class="wrap">
    <?php if ($reviewCards): ?>
      <?php $renderReviewCards($reviewCards); ?>
    <?php else: ?>
      <div class="sec-head"><div class="stars" style="color:var(--amber);font-size:26px">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <h2>Real reviews from local customers</h2>
        <p>We let our work speak for itself.</p></div>
    <?php endif; ?>
    <?php if ($googleUrl): ?>
      <div class="center mt24"><a class="btn btn-outline" href="<?= $h($googleUrl) ?>" target="_blank" rel="noopener">See all reviews on Google &rarr;</a></div>
    <?php endif; ?>
  </div></section>
  <?php $finalCta('Join our happy customers', $phone ? 'Call today for a free quote.' : 'Get a free quote today.'); ?>
<?php break;

// ══════════════════════ CONTACT ══════════════════════
case 'contact':
    ?>
  <section class="pagehead"><div class="wrap">
    <h1>Get in Touch</h1>
    <p class="lead">Free quotes. We pick up the phone.</p>
  </div></section>
  <section class="block"><div class="wrap"><div class="contact-grid">
    <div>
      <?php if ($phone): ?><div style="margin-bottom:22px"><a class="phone-big" href="tel:<?= $h($phoneTel) ?>">&#9742; <?= $h($phone) ?></a></div><?php endif; ?>
      <?php
        $dayLabels = ['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'];
        $hasHours = false;
        foreach ($dayLabels as $k=>$lbl) { if (!empty($hours[$k]['open']) || !empty($hours[$k]['close'])) { $hasHours = true; break; } }
      ?>
      <?php if ($schemaAddr || $hasHours): ?>
      <dl class="info-card">
        <?php if ($schemaAddr): ?><dt>Address</dt><dd><?= $h($schemaAddr) ?></dd><?php endif; ?>
        <?php if ($hasHours): ?>
        <dt>Hours</dt>
        <dd><?php foreach ($dayLabels as $k=>$lbl):
            if (empty($hours[$k]['open']) && empty($hours[$k]['close'])) continue; ?>
            <div><?= $lbl ?>: <?= $h((string)($hours[$k]['open'] ?? '')) ?> &ndash; <?= $h((string)($hours[$k]['close'] ?? '')) ?></div>
          <?php endforeach; ?></dd>
        <?php endif; ?>
      </dl>
      <?php endif; ?>
    </div>
    <form class="form" method="post" action="https://adverton.net/crm/site-lead.php?c=<?= (int)$client['id'] ?>">
      <input type="text" name="company" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <div class="row2"><input type="text" name="name" placeholder="Your name" required><input type="tel" name="phone" placeholder="Phone" required></div>
      <input type="text" name="city" placeholder="City / town">
      <textarea name="message" rows="5" placeholder="How can we help?"></textarea>
      <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.45;margin:2px 0 12px;color:#6b7280;text-align:left">
        <input type="checkbox" name="sms_consent" value="1" required style="margin-top:3px;flex:0 0 auto">
        <span>By checking this box, I agree to receive text messages from <?= $h($name) ?> about my request and service (such as appointment updates, missed-call replies, and review requests). Msg &amp; data rates may apply. Message frequency varies. Reply STOP to opt out, HELP for help. See our <a href="https://adverton.net/privacy.html" target="_blank" rel="noopener">Privacy Policy</a>.</span>
      </label>
      <button class="btn btn-primary" type="submit">Request a free quote</button>
    </form>
  </div></div></section>
  <?php if ($schemaAddr || $areaCities): ?><?= $mapEmbed($name . ' ' . ($schemaAddr ?: (string)reset($citySlugs))) ?><?php endif; ?>
<?php break;

// ══════════════════════ HOME ══════════════════════
default:
    ?>
  <?php if ($heroImage): ?>
  <section class="hero with-img">
    <div class="hero-img-bg" style="background-image:url('<?= $h($heroImage) ?>')"></div>
    <div class="hero-pad"><div class="wrap"><div class="hero-inner">
  <?php else: ?>
  <section class="hero"><div class="wrap"><div class="hero-inner">
  <?php endif; ?>
      <?php if ($tagline): ?><span class="badge"><?= $h($tagline) ?></span><?php endif; ?>
      <h1><?= $h((string)($hero['headline'] ?? $name)) ?></h1>
      <p class="sub"><?= $h((string)($hero['subheadline'] ?? '')) ?></p>
      <div class="ctas">
        <a class="btn btn-call" href="/contact.html"><?= $h((string)($hero['cta_primary'] ?? 'Get a free quote')) ?></a>
        <?php if ($phone): ?><a class="btn btn-ghost" href="tel:<?= $h($phoneTel) ?>">&#9742; <?= $h((string)($hero['cta_secondary'] ?? 'Call us now')) ?></a><?php endif; ?>
      </div>
  <?php if ($heroImage): ?>
    </div></div></div>
  </section>
  <?php else: ?>
    </div></div>
  </section>
  <?php endif; ?>

  <?php $renderTrustStrip(); ?>

  <?php if ($serviceSlugs): ?>
  <section class="block"><div class="wrap">
    <div class="sec-head"><span class="eyebrow">What we do</span><h2>Our Services</h2>
      <p>Pick a service to see the details.</p></div>
    <?php $renderServiceCards($serviceSlugs, 6); ?>
    <?php if (count($serviceSlugs) > 6): ?><div class="center mt24"><a class="btn btn-outline" href="/services.html">View all services &rarr;</a></div><?php endif; ?>
  </div></section>
  <?php endif; ?>

  <?php if ($reviewCards): ?>
  <section class="block soft"><div class="wrap">
    <div class="sec-head"><div class="stars" style="color:var(--amber);font-size:24px;margin-bottom:8px">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
      <h2>What our customers say</h2>
      <p><?= $yearsInBiz ? $yearsInBiz . ' years of trusted local service.' : 'Real reviews from local homeowners.' ?></p></div>
    <?php $renderReviewCards($reviewCards, 3); ?>
    <?php if ($googleUrl): ?><div class="center mt24"><a class="btn btn-outline" href="<?= $h($googleUrl) ?>" target="_blank" rel="noopener">See all reviews on Google &rarr;</a></div><?php endif; ?>
  </div></section>
  <?php endif; ?>

  <?php if ($areaCities): ?>
  <section class="block <?= $reviewCards ? '' : 'soft' ?>"><div class="wrap">
    <div class="sec-head"><span class="eyebrow">Service Areas</span><h2>Proudly Serving Your Neighborhood</h2>
      <p>Local service across <?= count($citySlugs) ?> communities.</p></div>
    <?php $renderCityChips($citySlugs, 12); ?>
    <?php if (count($citySlugs) > 12): ?><div class="center mt24"><a class="btn btn-outline" href="/locations.html">See all areas &rarr;</a></div><?php endif; ?>
  </div></section>
  <?php endif; ?>

  <?php if (!empty($about['body_html'])): ?>
  <section class="block soft"><div class="wrap"><div class="article">
    <h2><?= $h((string)($about['title'] ?? 'About us')) ?></h2>
    <?= $about['body_html'] ?>
  </div></div></section>
  <?php endif; ?>

  <?php if (!empty($faq)): ?>
  <section class="block"><div class="wrap">
    <div class="sec-head"><h2>Frequently Asked Questions</h2></div>
    <?php foreach ($faq as $item): ?>
      <details class="faq-item"><summary><?= $h((string)($item['question'] ?? '')) ?></summary>
        <div class="ans"><?= $item['answer_html'] ?? '' ?></div></details>
    <?php endforeach; ?>
  </div></section>
  <?php endif; ?>

  <?php $finalCta('Ready to get started?', $phone ? 'Free quotes. Fast response.' : 'Free quotes. Send us a message.'); ?>

<?php endswitch; ?>

<footer class="site"><div class="wrap">
  <div class="cols">
    <div>
      <div class="brandline"><?= $h($name) ?></div>
      <div><?= $h($footerBlurb) ?: $h($offering . ' you can trust.') ?></div>
      <?php if ($phone): ?><div style="margin-top:10px"><a href="tel:<?= $h($phoneTel) ?>">&#9742; <?= $h($phone) ?></a></div><?php endif; ?>
      <?php if ($schemaAddr): ?><div style="margin-top:4px;color:#7e90a8"><?= $h($schemaAddr) ?></div><?php endif; ?>
    </div>
    <div>
      <h4>Company</h4>
      <a href="/services.html">Services</a>
      <?php if ($areaCities): ?><a href="/locations.html">Service Areas</a><?php endif; ?>
      <a href="/reviews.html">Reviews</a>
      <a href="/contact.html">Contact</a>
    </div>
    <?php if ($serviceSlugs): ?>
    <div>
      <h4>Services</h4>
      <?php foreach (array_slice($serviceSlugs, 0, 5, true) as $sg => $sName): ?>
        <a href="<?= $h($svcHref($sg)) ?>"><?= $h((string)$sName) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="legal">
    <div>&copy; <?= date('Y') ?> <?= $h($name) ?>. All rights reserved.</div>
    <div><?php if ($licenseNum): ?>Licensed #<?= $h($licenseNum) ?><?php endif; ?><?php if ($insurance): ?> &middot; Insured<?php endif; ?></div>
  </div>
</div></footer>

<!-- CallRail tracking — operator: paste the client's swap.js tag here at onboarding
<script async src="//cdn.callrail.com/companies/YOUR_COMPANY_ID/swap.js"></script>
-->

</body>
</html>
<?php
    return (string)ob_get_clean();
}
