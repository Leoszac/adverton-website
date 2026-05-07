<?php
// Speed-First template — for emergency services (plumbing, locksmith,
// electrical, HVAC). Hero is dominated by a giant click-to-call. Trust
// strip immediately under it. Job photos secondary.
//
// Same render contract as trust-first.php:
//   crm_renderTemplate_speed_first($client, $intake, $copy, $assets): string

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

function crm_renderTemplate_speed_first(array $client, array $intake, array $copy, array $assets): string {
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
    $primary = $colors['primary'] ?? '#dc2626';
    $accent  = $colors['accent']  ?? '#f59e0b';

    $area = (array)($intake['service_area_decoded'] ?? []);
    $areaSummary = '';
    if (($area['type'] ?? '') === 'cities' && !empty($area['cities'])) {
        $cities = array_slice((array)$area['cities'], 0, 6);
        $areaSummary = 'Serving ' . implode(', ', $cities) . (count($area['cities']) > 6 ? ' and surrounding areas.' : '.');
    } elseif (($area['type'] ?? '') === 'radius' && !empty($area['zip'])) {
        $areaSummary = 'Serving ' . (int)$area['radius_mi'] . ' miles around ' . $area['zip'] . '.';
    }

    ob_start();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $h($name) ?> — <?= $h($hero['headline'] ?? '') ?></title>
<meta name="description" content="<?= $h($hero['subheadline'] ?? '') ?>">
<style>
  :root{--primary:<?= $h($primary) ?>;--accent:<?= $h($accent) ?>;--ink:#0e0d12;--ink-2:#383640;--ink-3:#6b6877;--bg:#fff;--line:#e7e4ee}
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif;color:var(--ink);background:var(--bg);line-height:1.55;font-size:17px}
  a{color:var(--primary);text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:0 22px}
  /* Sticky call-now bar — speed-first signature: visible on every scroll */
  .callbar{position:sticky;top:0;z-index:20;background:var(--primary);color:#fff;padding:12px 0;text-align:center;font-weight:700;font-size:16px}
  .callbar a{color:#fff;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:10px}
  .callbar .live{display:inline-block;width:10px;height:10px;background:#22c55e;border-radius:50%;animation:pulse 1.5s infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
  /* Hero — giant click-to-call */
  .hero{padding:60px 0 80px;text-align:center;background:linear-gradient(180deg,#fff 0%,#fef2f2 100%)}
  .hero h1{margin:0 0 18px;font-size:52px;letter-spacing:-0.025em;line-height:1.05;font-weight:800}
  .hero .sub{font-size:20px;color:var(--ink-2);margin:0 0 36px;max-width:680px;margin-left:auto;margin-right:auto}
  .big-call{display:inline-block;background:var(--primary);color:#fff;padding:24px 44px;border-radius:14px;font-size:32px;font-weight:800;letter-spacing:-0.01em;box-shadow:0 10px 32px rgba(220,38,38,.3)}
  .big-call .digits{font-size:36px;display:block;margin-top:4px}
  .answer-promise{font-size:14px;color:var(--ink-3);margin-top:20px;font-weight:600}
  .answer-promise span{color:#16a34a}
  /* Trust strip */
  .trust-strip{background:#0e0d12;color:#fff;padding:20px 0;text-align:center}
  .trust-row{display:flex;justify-content:center;gap:30px;flex-wrap:wrap;align-items:center;font-size:14px;font-weight:600}
  .trust-row .item{display:flex;align-items:center;gap:8px;color:#fff}
  .trust-row .check{width:18px;height:18px;border-radius:50%;background:#16a34a;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
  /* Services */
  .services{padding:60px 0;background:#fff}
  .services h2{text-align:center;margin:0 0 8px;font-size:30px}
  .svc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:30px}
  .svc{background:#fef2f2;border:1px solid #fee2e2;border-radius:12px;padding:20px}
  .svc .icon{font-size:32px;margin-bottom:6px}
  .svc h3{margin:0 0 6px;font-size:17px;color:var(--ink)}
  .svc p{margin:0;color:var(--ink-2);font-size:14px}
  /* While-you-wait section — speed-first signature */
  .wait{padding:60px 0;background:#fef2f2}
  .wait h2{margin:0 0 14px;font-size:26px;text-align:center}
  .wait .sub{text-align:center;color:var(--ink-3);margin:0 0 28px}
  .wait-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
  .wait-card{background:#fff;border-left:4px solid var(--primary);padding:18px;border-radius:8px}
  .wait-card strong{color:var(--primary);font-size:14px;display:block;margin-bottom:4px}
  .wait-card p{margin:0;font-size:14px;color:var(--ink-2)}
  /* About + FAQ + final CTA */
  .about{padding:60px 0}
  .about h2{margin:0 0 16px;font-size:28px;text-align:center}
  .about-content{max-width:760px;margin:0 auto;color:var(--ink-2)}
  .area{padding:28px 0;background:var(--ink);color:#fff;text-align:center;font-size:15px;font-weight:600}
  .faq{padding:60px 0}
  .faq h2{text-align:center;margin:0 0 28px;font-size:28px}
  .faq-item{max-width:760px;margin:0 auto 12px;border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden}
  .faq-item summary{padding:16px 20px;font-weight:600;cursor:pointer;list-style:none;font-size:15px}
  .faq-item summary::-webkit-details-marker{display:none}
  .faq-item .answer{padding:0 20px 18px;color:var(--ink-2);font-size:15px}
  .final-cta{padding:60px 0;background:var(--primary);color:#fff;text-align:center}
  .final-cta h2{margin:0 0 14px;font-size:28px}
  .final-cta a{display:inline-block;background:#fff;color:var(--primary);padding:18px 32px;border-radius:10px;font-weight:800;font-size:22px;margin-top:14px}
  footer.site{background:#0e0d12;color:#9ca3af;padding:24px 0;font-size:13px}
  footer.site .row{display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px}
  @media(max-width:520px){.hero h1{font-size:36px}.hero .sub{font-size:17px}.big-call{padding:18px 28px;font-size:24px}.big-call .digits{font-size:26px}.services h2,.about h2,.faq h2,.final-cta h2{font-size:22px}}
</style>
</head>
<body>

<?php if ($phone): ?>
<div class="callbar"><a href="tel:<?= $h($phoneTel) ?>"><span class="live"></span> Call now: <?= $h($phone) ?></a></div>
<?php endif; ?>

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
  <div class="svc-grid">
    <?php foreach ($services as $svc): ?>
      <div class="svc">
        <div class="icon"><?= $h((string)($svc['icon_emoji'] ?? '🔧')) ?></div>
        <h3><?= $h((string)($svc['name'] ?? '')) ?></h3>
        <div><?= $svc['description_html'] ?? '' /* AI-trusted; system prompt restricts to <p><strong><em><ul><li> */ ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

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

<?php if (!empty($about['body_html'])): ?>
<section class="about"><div class="wrap about-content">
  <h2><?= $h((string)($about['title'] ?? 'About us')) ?></h2>
  <?= $about['body_html'] ?>
</div></section>
<?php endif; ?>

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

<section class="final-cta" id="contact"><div class="wrap">
  <h2>Got an emergency? Don't wait.</h2>
  <p>Real humans on the line. No robots. No "press 1".</p>
  <?php if ($phone): ?><a href="tel:<?= $h($phoneTel) ?>">📞 <?= $h($phone) ?></a><?php endif; ?>
</div></section>

<footer class="site"><div class="wrap row">
  <div><strong style="color:#fff"><?= $h($name) ?></strong> · <?= $h((string)($copy['footer_blurb'] ?? '')) ?></div>
  <div>© <?= date('Y') ?></div>
</div></footer>

</body>
</html>
<?php
    return (string)ob_get_clean();
}
