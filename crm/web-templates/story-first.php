<?php
// Story-First template — for higher-consideration trades (landscaping,
// painting, remodeling, hardscaping). Project gallery is the hero. Family
// story has its own section. Soft CTA — they're shopping, not panicking.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_renderTemplate_story_first(array $client, array $intake, array $copy, array $assets): string {
    $h = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $name      = (string)($intake['display_name']  ?? $client['business_name'] ?? '');
    $tagline   = (string)($intake['tagline']       ?? '');
    $phone     = (string)($client['primary_phone'] ?? '');
    $phoneTel  = preg_replace('/[^0-9+]/', '', $phone);
    $hero      = (array)($copy['hero']     ?? []);
    $about     = (array)($copy['about']    ?? []);
    $services  = (array)($copy['services'] ?? []);
    $faq       = (array)($copy['faq']      ?? []);

    $colors  = (array)($intake['brand_colors_decoded'] ?? []);
    $primary = $colors['primary'] ?? '#0f766e';
    $accent  = $colors['accent']  ?? '#a78bfa';

    // Story-First hinges on photos. Pull job + before_after + exterior, up to 8.
    $gallery = [];
    foreach ($assets as $a) {
        if (!empty($a['approved']) && in_array(($a['category'] ?? ''), ['job','before_after','exterior','interior'], true)) {
            $gallery[] = $a;
            if (count($gallery) >= 8) break;
        }
    }

    $area = (array)($intake['service_area_decoded'] ?? []);
    $areaSummary = '';
    if (($area['type'] ?? '') === 'cities' && !empty($area['cities'])) {
        $cities = array_slice((array)$area['cities'], 0, 6);
        $areaSummary = 'Serving ' . implode(', ', $cities) . (count($area['cities']) > 6 ? ' and surrounding areas.' : '.');
    } elseif (($area['type'] ?? '') === 'radius' && !empty($area['zip'])) {
        $areaSummary = 'Serving ' . (int)$area['radius_mi'] . ' miles around ' . $area['zip'] . '.';
    }

    $yearsInBiz = (int)($intake['years_in_business'] ?? 0);

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
$_googleUrl = (string)(($intake['reviews_links_decoded'] ?? [])['google'] ?? '');
$_schema = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name, 'description' => (string)($hero['subheadline'] ?? '')];
if ($phone) $_schema['telephone'] = $phone;
if ($_schemaAddr) $_schema['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $_schemaAddr];
$_schemaSvcs = array_values(array_filter(array_map(fn($s) => (string)($s['name'] ?? ''), (array)$services)));
if ($_schemaSvcs) $_schema['makesOffer'] = array_map(fn($s) => ['@type' => 'Offer', 'name' => $s], $_schemaSvcs);
if ($_googleUrl) $_schema['sameAs'] = [$_googleUrl];
?>
<script type="application/ld+json"><?= json_encode($_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<style>
  :root{--primary:<?= $h($primary) ?>;--accent:<?= $h($accent) ?>;--ink:#1f2937;--ink-2:#374151;--ink-3:#6b7280;--bg:#fafaf9;--card:#fff;--line:#e7e5e4}
  *{box-sizing:border-box}
  body{margin:0;font-family:Georgia,Cambria,"Times New Roman",serif;color:var(--ink);background:var(--bg);line-height:1.65;font-size:18px}
  a{color:var(--primary);text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:0 22px}
  /* Header */
  header.site{background:var(--bg);padding:18px 0;border-bottom:1px solid var(--line)}
  header.site .row{display:flex;justify-content:space-between;align-items:center;gap:14px}
  header.site .name{font-weight:700;font-size:20px;letter-spacing:-0.01em;color:var(--ink);font-family:Georgia,serif}
  header.site .cta{background:transparent;color:var(--primary);padding:8px 16px;border:1.5px solid var(--primary);border-radius:9px;font-weight:600;font-size:14px;font-family:-apple-system,sans-serif}
  /* Hero — gallery dominates */
  .hero{padding:50px 0 40px;background:var(--bg)}
  .hero h1{margin:0 0 16px;font-size:46px;letter-spacing:-0.015em;line-height:1.15;font-weight:400;font-family:Georgia,serif}
  .hero .sub{font-size:19px;color:var(--ink-2);margin:0 0 28px;max-width:680px}
  .hero .ctas{display:flex;gap:14px;flex-wrap:wrap;font-family:-apple-system,sans-serif}
  .btn-primary{display:inline-block;background:var(--primary);color:#fff;padding:13px 24px;border-radius:9px;font-weight:600;font-size:15px}
  .btn-secondary{display:inline-block;background:transparent;color:var(--primary);padding:12px 24px;border-radius:9px;font-weight:600;font-size:15px;border:1.5px solid var(--primary)}
  /* Gallery — story-first signature */
  .gallery{padding:30px 0 60px}
  .g-grid{display:grid;grid-template-columns:repeat(4, 1fr);gap:14px}
  @media(max-width:980px){.g-grid{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:520px){.g-grid{grid-template-columns:1fr}}
  .g-grid img{width:100%;height:240px;object-fit:cover;border-radius:10px;display:block;background:#e7e5e4}
  .g-grid .placeholder{aspect-ratio:1;border-radius:10px;background:linear-gradient(135deg,var(--primary) 0%,var(--accent) 100%);display:flex;align-items:center;justify-content:center;color:#fff;text-align:center;padding:18px;font-family:-apple-system,sans-serif;font-weight:600}
  /* About / story */
  .about{padding:80px 0;background:#fff}
  .about .grid{display:grid;grid-template-columns:1fr 1fr;gap:50px;align-items:start}
  @media(max-width:780px){.about .grid{grid-template-columns:1fr}}
  .about h2{margin:0 0 18px;font-size:34px;font-weight:400;font-family:Georgia,serif;letter-spacing:-0.01em}
  .about p{font-size:17px;color:var(--ink-2);line-height:1.7}
  .stat{font-family:Georgia,serif;font-size:64px;font-weight:400;color:var(--primary);line-height:1}
  .stat-label{color:var(--ink-3);font-size:14px;margin-top:8px;font-family:-apple-system,sans-serif}
  /* Services + process */
  .services{padding:60px 0;background:var(--bg)}
  .services h2{margin:0 0 8px;font-size:32px;text-align:center;font-family:Georgia,serif;font-weight:400}
  .services .sub{text-align:center;color:var(--ink-3);margin:0 0 36px;font-size:16px}
  .svc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}
  .svc{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}
  .svc .icon{font-size:30px;margin-bottom:10px}
  .svc h3{margin:0 0 10px;font-size:19px;font-family:Georgia,serif}
  .svc p{margin:0;color:var(--ink-2);font-size:15px}
  /* Process */
  .process{padding:60px 0;background:#fff}
  .process h2{margin:0 0 32px;font-size:30px;text-align:center;font-family:Georgia,serif;font-weight:400}
  .steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;max-width:980px;margin:0 auto}
  .step{padding:22px;background:var(--bg);border-radius:12px}
  .step .num{font-family:Georgia,serif;font-size:42px;color:var(--primary);line-height:1;margin-bottom:10px}
  .step h3{margin:0 0 6px;font-size:17px;font-family:Georgia,serif}
  .step p{margin:0;color:var(--ink-2);font-size:14px}
  /* Area + FAQ + CTA */
  .area{padding:32px 0;background:var(--bg);text-align:center;color:var(--ink-2);font-size:16px;font-style:italic;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
  .faq{padding:60px 0;background:#fff}
  .faq h2{text-align:center;margin:0 0 32px;font-size:30px;font-family:Georgia,serif;font-weight:400}
  .faq-item{max-width:780px;margin:0 auto 14px;border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden}
  .faq-item summary{padding:18px 22px;font-weight:600;cursor:pointer;list-style:none;font-size:16px}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item .answer{padding:0 22px 20px;color:var(--ink-2);font-size:16px}
  .final-cta{padding:64px 0;background:var(--primary);color:#fff;text-align:center}
  .final-cta h2{margin:0 0 14px;font-size:32px;font-family:Georgia,serif;font-weight:400}
  .final-cta p{margin:0 0 26px;color:#fff;opacity:0.9;font-size:17px}
  .final-cta a{display:inline-block;background:#fff;color:var(--primary);padding:14px 28px;border-radius:9px;font-weight:700;font-size:16px;font-family:-apple-system,sans-serif}
  /* Contact form */
  .contact{padding:64px 0;background:var(--bg)}
  .contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:start}
  @media(max-width:780px){.contact-grid{grid-template-columns:1fr}}
  .contact h2{margin:0 0 14px;font-size:30px;font-family:Georgia,serif;font-weight:400}
  .form{display:flex;flex-direction:column;gap:12px;font-family:-apple-system,sans-serif;font-size:16px}
  .form input,.form textarea{width:100%;padding:13px 15px;border:1px solid var(--line);border-radius:9px;font-family:inherit;font-size:16px;box-sizing:border-box;background:#fff}
  .form input:focus,.form textarea:focus{outline:none;border-color:var(--primary)}
  .form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:520px){.form .row2{grid-template-columns:1fr}}
  footer.site{background:var(--ink);color:#9ca3af;padding:30px 0;font-size:14px}
  footer.site .row{display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px}
  footer.site a{color:#d1d5db;margin-right:14px}
  @media(max-width:520px){.hero h1{font-size:32px}.about h2,.services h2,.process h2,.faq h2,.final-cta h2{font-size:24px}.stat{font-size:48px}}
</style>
</head>
<body>

<header class="site"><div class="wrap row">
  <div class="name"><?= $h($name) ?></div>
  <?php if ($phone): ?><a class="cta" href="tel:<?= $h($phoneTel) ?>"><?= $h($phone) ?></a><?php endif; ?>
</div></header>

<section class="hero"><div class="wrap">
  <?php if ($tagline): ?><div style="font-size:13px;color:var(--primary);font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;font-family:-apple-system,sans-serif"><?= $h($tagline) ?></div><?php endif; ?>
  <h1><?= $h($hero['headline'] ?? $name) ?></h1>
  <p class="sub"><?= $h($hero['subheadline'] ?? '') ?></p>
  <div class="ctas">
    <a class="btn-primary" href="#contact"><?= $h($hero['cta_primary'] ?? 'See our work') ?></a>
    <?php if ($phone): ?><a class="btn-secondary" href="tel:<?= $h($phoneTel) ?>"><?= $h($hero['cta_secondary'] ?? 'Call us') ?></a><?php endif; ?>
  </div>
</div></section>

<section class="gallery"><div class="wrap">
  <div class="g-grid">
    <?php if ($gallery): foreach ($gallery as $g): ?>
      <img src="/clients/<?= (int)$client['id'] ?>/photos/<?= $h($g['category']) ?>/<?= $h($g['stored_name']) ?>" alt="<?= $h((string)($g['ai_description'] ?? '')) ?>" loading="lazy">
    <?php endforeach; else: for ($i = 0; $i < 4; $i++): ?>
      <div class="placeholder">Photos coming soon</div>
    <?php endfor; endif; ?>
  </div>
</div></section>

<?php if (!empty($about['body_html'])): ?>
<section class="about"><div class="wrap">
  <div class="grid">
    <div>
      <h2><?= $h((string)($about['title'] ?? 'Our story')) ?></h2>
      <?= $about['body_html'] ?>
    </div>
    <?php if ($yearsInBiz > 0): ?>
      <div style="text-align:center;padding-top:30px">
        <div class="stat"><?= $yearsInBiz ?></div>
        <div class="stat-label">years building in <?= $h(($area['cities'][0] ?? 'this community')) ?></div>
      </div>
    <?php endif; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if (!empty($services)): ?>
<section class="services"><div class="wrap">
  <h2>What we do</h2>
  <p class="sub">Crafted with care, finished on time.</p>
  <div class="svc-grid">
    <?php foreach ($services as $svc): ?>
      <div class="svc">
        <div class="icon"><?= $h((string)($svc['icon_emoji'] ?? '🌿')) ?></div>
        <h3><?= $h((string)($svc['name'] ?? '')) ?></h3>
        <div><?= $svc['description_html'] ?? '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="process"><div class="wrap">
  <h2>How we work</h2>
  <div class="steps">
    <div class="step"><div class="num">1</div><h3>Free consultation</h3><p>We come out, look at the space, listen. No pressure.</p></div>
    <div class="step"><div class="num">2</div><h3>Detailed proposal</h3><p>Plain-English scope + price. No surprises later.</p></div>
    <div class="step"><div class="num">3</div><h3>The work begins</h3><p>Same crew start to finish. We protect your home.</p></div>
    <div class="step"><div class="num">4</div><h3>Final walkthrough</h3><p>You sign off when it's right. We don't disappear.</p></div>
  </div>
</div></section>

<?php if ($areaSummary): ?>
<section class="area"><div class="wrap"><?= $h($areaSummary) ?></div></section>
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
  <h2>Ready to start?</h2>
  <p>Free estimates. We'll come out, listen, and tell you straight if it's a fit.</p>
  <?php if ($phone): ?><a href="tel:<?= $h($phoneTel) ?>"><?= $h($phone) ?></a><?php endif; ?>
</div></section>

<!-- Contact -->
<section class="contact" id="contact"><div class="wrap"><div class="contact-grid">
  <div>
    <h2>Tell us about your project</h2>
    <p style="color:var(--ink-2);font-size:17px">A few sentences about what you're picturing. We'll come out for a free walkthrough.</p>
    <?php if ($phone): ?>
      <p style="margin-top:18px;color:var(--ink-2);font-family:-apple-system,sans-serif"><strong>Or call:</strong> <a href="tel:<?= $h($phoneTel) ?>" style="color:var(--primary);font-weight:700"><?= $h($phone) ?></a></p>
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
    <textarea name="message" placeholder="What are you thinking of building / fixing / changing?" rows="4"></textarea>
    <button type="submit" style="background:var(--primary);color:#fff;padding:14px 22px;border-radius:9px;font-weight:700;font-size:16px;cursor:pointer;border:0;font-family:inherit">Send →</button>
  </form>
</div></div></section>

<!-- Map placeholder — operator: uncomment + paste Google Maps Embed API key + address at onboarding
<section style="padding:0"><iframe width="100%" height="320" frameborder="0" loading="lazy" src="https://www.google.com/maps/embed/v1/place?key=YOUR_KEY&q=YOUR_ADDRESS"></iframe></section>
-->

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
