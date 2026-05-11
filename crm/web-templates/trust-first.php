<?php
// Trust-First template — for established contractors with strong reviews.
// Reviews + ratings + Google-Guaranteed badge get hero-adjacent placement.
//
// Contract: every web template under crm/web-templates/ exposes ONE function
//   crm_renderTemplate_<key>($client, $intake, $copy, $assets): string
// Returns a complete HTML document. No DB calls. No globals. Pure render.
//
// `$assets` is an array of client_assets rows (same shape as crm_listAssets
// when that lib lands in Sprint 3). For Sprint 2 we accept it empty and
// degrade gracefully.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_renderTemplate_trust_first(array $client, array $intake, array $copy, array $assets): string {
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
    $primary = $colors['primary'] ?? '#6d28d9';
    $accent  = $colors['accent']  ?? '#f59e0b';

    $reviews = (array)($intake['reviews_links_decoded'] ?? []);
    $googleUrl = (string)($reviews['google'] ?? '');
    $yearsInBiz = (int)($intake['years_in_business'] ?? 0);

    // Service area summary
    $area = (array)($intake['service_area_decoded'] ?? []);
    $areaSummary = '';
    if (($area['type'] ?? '') === 'cities' && !empty($area['cities'])) {
        $cities = array_slice((array)$area['cities'], 0, 6);
        $areaSummary = 'Serving ' . implode(', ', $cities) . (count($area['cities']) > 6 ? ' and surrounding areas.' : '.');
    } elseif (($area['type'] ?? '') === 'radius' && !empty($area['zip'])) {
        $areaSummary = 'Serving ' . (int)$area['radius_mi'] . ' miles around ' . $area['zip'] . '.';
    }

    // Pick a hero image from assets (category=exterior or job, first approved one)
    $heroImage = '';
    foreach ($assets as $a) {
        if (!empty($a['approved']) && in_array(($a['category'] ?? ''), ['exterior','job','team'], true)) {
            $heroImage = '/clients/' . (int)$client['id'] . '/photos/' . $h($a['category']) . '/' . $h($a['stored_name']);
            break;
        }
    }

    ob_start();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $h($name) ?> — <?= $h($hero['headline'] ?? '') ?></title>
<meta name="description" content="<?= $h($hero['subheadline'] ?? '') ?>">
<?php
$_schemaAddr = trim(implode(', ', array_filter([
    (string)($client['billing_address'] ?? ''),
    (string)($client['billing_city'] ?? ''),
    trim((string)($client['billing_state'] ?? '') . ' ' . (string)($client['billing_zip'] ?? ''))
])));
$_schema = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name, 'description' => (string)($hero['subheadline'] ?? '')];
if ($phone) $_schema['telephone'] = $phone;
if ($_schemaAddr) $_schema['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $_schemaAddr];
$_schemaSvcs = array_values(array_filter(array_map(fn($s) => (string)($s['name'] ?? ''), (array)$services)));
if ($_schemaSvcs) $_schema['makesOffer'] = array_map(fn($s) => ['@type' => 'Offer', 'name' => $s], $_schemaSvcs);
if ($googleUrl) $_schema['sameAs'] = [$googleUrl];
?>
<script type="application/ld+json"><?= json_encode($_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<style>
  :root{--primary:<?= $h($primary) ?>;--accent:<?= $h($accent) ?>;--ink:#0e0d12;--ink-2:#383640;--ink-3:#6b6877;--bg:#fff;--card:#fff;--line:#e7e4ee;--soft:#faf9ff}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif;color:var(--ink);background:var(--bg);line-height:1.55;font-size:17px}
  a{color:var(--primary);text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:0 22px}
  /* Header */
  header.site{position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid var(--line);padding:14px 0}
  header.site .row{display:flex;justify-content:space-between;align-items:center;gap:14px}
  header.site .name{font-weight:800;font-size:18px;letter-spacing:-0.01em;color:var(--ink)}
  header.site .cta{background:var(--primary);color:#fff;padding:9px 18px;border-radius:8px;font-weight:600;font-size:14px}
  /* Hero with reviews-first layout */
  .hero{background:linear-gradient(180deg,var(--soft) 0%,#fff 100%);padding:48px 0 56px}
  .hero-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:36px;align-items:center}
  @media(max-width:780px){.hero-grid{grid-template-columns:1fr}}
  .hero h1{margin:0 0 12px;font-size:42px;letter-spacing:-0.02em;line-height:1.1;font-weight:800}
  .hero .sub{font-size:18px;color:var(--ink-2);margin:0 0 22px}
  .hero .ctas{display:flex;gap:10px;flex-wrap:wrap}
  .btn-primary{display:inline-block;background:var(--primary);color:#fff;padding:13px 22px;border-radius:9px;font-weight:700;font-size:15px}
  .btn-secondary{display:inline-block;background:#fff;color:var(--primary);padding:12px 22px;border-radius:9px;font-weight:700;font-size:15px;border:1.5px solid var(--primary)}
  .hero-img{width:100%;height:340px;object-fit:cover;border-radius:14px;background:#ece9f3}
  .hero-img-placeholder{width:100%;height:340px;border-radius:14px;background:linear-gradient(135deg,var(--accent) 0%,var(--primary) 100%);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:700;text-align:center;padding:24px}
  /* Trust strip — front-of-fold for trust-first layout */
  .trust-strip{background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:18px 0}
  .trust-row{display:flex;justify-content:center;gap:36px;flex-wrap:wrap;align-items:center;font-size:14px;color:var(--ink-2);font-weight:600}
  .trust-row .item{display:flex;align-items:center;gap:8px}
  .trust-row .check{width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
  /* Reviews block */
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
  /* About */
  .about{padding:64px 0;background:#fff}
  .about h2{margin:0 0 16px;font-size:30px;letter-spacing:-0.01em}
  .about-content{max-width:760px;margin:0 auto;font-size:16px;color:var(--ink-2)}
  /* Service area */
  .area{padding:36px 0;background:var(--soft);text-align:center;color:var(--ink-2);font-size:15px;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
  /* FAQ */
  .faq{padding:64px 0;background:#fff}
  .faq h2{text-align:center;margin:0 0 28px;font-size:30px;letter-spacing:-0.01em}
  .faq-item{max-width:760px;margin:0 auto 12px;border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden}
  .faq-item summary{padding:16px 20px;font-weight:600;cursor:pointer;list-style:none;font-size:15px;display:flex;justify-content:space-between;align-items:center}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item summary::after{content:'+';font-size:20px;color:var(--ink-3)}
  .faq-item[open] summary::after{content:'−'}
  .faq-item .answer{padding:0 20px 18px;color:var(--ink-2);font-size:15px}
  /* Footer CTA */
  .final-cta{padding:64px 0;background:var(--primary);color:#fff;text-align:center}
  .final-cta h2{margin:0 0 14px;font-size:30px;letter-spacing:-0.01em}
  .final-cta p{margin:0 0 22px;color:rgba(255,255,255,0.9);font-size:16px}
  .final-cta .btn{display:inline-block;background:#fff;color:var(--primary);padding:14px 26px;border-radius:9px;font-weight:700;font-size:16px}
  /* Contact form */
  .contact{padding:64px 0;background:var(--soft);border-top:1px solid var(--line)}
  .contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:start}
  @media(max-width:780px){.contact-grid{grid-template-columns:1fr}}
  .contact h2{margin:0 0 12px;font-size:30px;letter-spacing:-0.01em}
  .form{display:flex;flex-direction:column;gap:12px}
  .form input,.form textarea{width:100%;padding:13px 15px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:16px;box-sizing:border-box;background:#fff}
  .form input:focus,.form textarea:focus{outline:none;border-color:var(--primary)}
  .form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:520px){.form .row2{grid-template-columns:1fr}}
  /* Footer */
  footer.site{background:#fff;border-top:1px solid var(--line);padding:24px 0;color:var(--ink-3);font-size:13px}
  footer.site .row{display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;align-items:center}
  footer.site a{color:inherit;margin-right:14px}
  @media(max-width:520px){.hero h1{font-size:32px}.hero .sub{font-size:16px}.hero-img,.hero-img-placeholder{height:240px}.services h2,.reviews h2,.about h2,.faq h2,.final-cta h2{font-size:26px}}
</style>
</head>
<body>

<!-- Header -->
<header class="site"><div class="wrap row">
  <div class="name"><?= $h($name) ?></div>
  <?php if ($phone): ?><a class="cta" href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
</div></header>

<!-- Hero -->
<section class="hero"><div class="wrap hero-grid">
  <div>
    <?php if ($tagline): ?><div style="font-size:13px;color:var(--primary);font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px"><?= $h($tagline) ?></div><?php endif; ?>
    <h1><?= $h($hero['headline'] ?? $name) ?></h1>
    <p class="sub"><?= $h($hero['subheadline'] ?? '') ?></p>
    <div class="ctas">
      <?php if ($phone): ?><a class="btn-primary" href="tel:<?= $h($phoneTel) ?>"><?= $h($hero['cta_primary'] ?? 'Call now') ?></a><?php endif; ?>
      <a class="btn-secondary" href="#contact"><?= $h($hero['cta_secondary'] ?? 'Get a quote') ?></a>
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

<!-- Trust strip — Trust-First's signature element -->
<?php if (!empty($trustStrip)): ?>
<section class="trust-strip"><div class="wrap"><div class="trust-row">
  <?php foreach ($trustStrip as $t): ?>
    <div class="item"><span class="check">✓</span><?= $h((string)$t) ?></div>
  <?php endforeach; ?>
</div></div></section>
<?php endif; ?>

<!-- Reviews CTA — link out to Google profile if available -->
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

<!-- Services -->
<?php if (!empty($services)): ?>
<section class="services"><div class="wrap">
  <h2>What we do</h2>
  <p class="sub">Honest pricing, on-time service, and we clean up after ourselves.</p>
  <div class="svc-grid">
    <?php foreach ($services as $svc): ?>
      <div class="svc">
        <div class="icon"><?= $h((string)($svc['icon_emoji'] ?? '🔧')) ?></div>
        <h3><?= $h((string)($svc['name'] ?? '')) ?></h3>
        <div><?= $svc['description_html'] ?? '' /* AI-generated trusted HTML, only <p><strong><em><ul><li> per system prompt */ ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<!-- About -->
<?php if (!empty($about['body_html'])): ?>
<section class="about"><div class="wrap about-content">
  <h2><?= $h((string)($about['title'] ?? 'About us')) ?></h2>
  <?= $about['body_html'] ?>
</div></section>
<?php endif; ?>

<!-- Service area -->
<?php if ($areaSummary): ?>
<section class="area"><div class="wrap"><?= $h($areaSummary) ?></div></section>
<?php endif; ?>

<!-- FAQ -->
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

<!-- Contact -->
<section class="contact" id="contact"><div class="wrap"><div class="contact-grid">
  <div>
    <h2>Get a free estimate</h2>
    <p style="color:var(--ink-2)">We'll get back to you within one business day.</p>
    <?php if ($phone): ?>
      <p style="margin-top:18px;color:var(--ink-2)"><strong>Or call:</strong> <a href="tel:<?= $h($phoneTel) ?>" style="color:var(--primary);font-weight:700"><?= $h($phone) ?></a></p>
    <?php endif; ?>
  </div>
  <form action="https://adverton.net/crm/client-form-submit.php?client_id=<?= (int)$client['id'] ?>" method="post" class="form">
    <input type="hidden" name="redirect" value="1">
    <input type="text" name="hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
    <input type="text" name="name" placeholder="Your name" required>
    <div class="row2">
      <input type="tel" name="phone" placeholder="Phone" required>
      <input type="email" name="email" placeholder="Email">
    </div>
    <textarea name="message" placeholder="What do you need help with?" rows="3"></textarea>
    <button type="submit" style="background:var(--primary);color:#fff;padding:14px 22px;border-radius:9px;font-weight:700;font-size:16px;cursor:pointer;border:0;font-family:inherit">Send →</button>
  </form>
</div></div></section>

<!-- Map placeholder — operator: uncomment + paste Google Maps Embed API key + address at onboarding
<section style="padding:0"><iframe width="100%" height="320" frameborder="0" loading="lazy" src="https://www.google.com/maps/embed/v1/place?key=YOUR_KEY&q=YOUR_ADDRESS"></iframe></section>
-->

<!-- Footer -->
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
