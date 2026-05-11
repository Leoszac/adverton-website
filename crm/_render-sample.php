<?php
// One-shot — renders one of the 3 web-templates with GENERIC sample data
// (Acme Home Services) so we can screenshot it for the kickoff wizard tiles.
// Token-gated. Manual delete after use.
//   GET /crm/_render-sample.php?go=SEED_TOKEN&which=trust_first|speed_first|story_first

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['go'] ?? ''))) {
    http_response_code(403);
    exit("forbidden\n");
}

require_once __DIR__ . '/web-templates/trust-first.php';
require_once __DIR__ . '/web-templates/speed-first.php';
require_once __DIR__ . '/web-templates/story-first.php';

$client = [
    'id'              => 99999,
    'business_name'   => 'Acme Home Services',
    'primary_phone'   => '(555) 123-4567',
    'billing_address' => '123 Main Street',
    'billing_city'    => 'Anytown',
    'billing_state'   => 'ST',
    'billing_zip'     => '12345',
];

$intake = [
    'display_name'  => 'Acme Home Services',
    'tagline'       => 'Trusted home services since 2010',
    'service_area_decoded' => [
        'type'   => 'cities',
        'cities' => ['Anytown', 'Springfield', 'Riverdale', 'Greenwood', 'Maplewood'],
    ],
    'reviews_links_decoded' => ['google' => 'https://example.com/reviews'],
    'years_in_business'     => 15,
    'emergency_24_7'        => true,
    'brand_colors_decoded'  => [],
];

$copy = [
    'hero' => [
        'headline'      => 'Reliable Home Services, Done Right',
        'subheadline'   => 'Same-day service across Anytown and surrounding areas. Family-owned and operated since 2010.',
        'cta_primary'   => 'Call now',
        'cta_secondary' => 'Get a quote',
    ],
    'trust_strip' => [
        'Licensed & insured',
        '5-star reviews',
        'Same-day service',
    ],
    'about' => [
        'title'     => 'About Acme Home Services',
        'body_html' => '<p>Founded in 2010, Acme Home Services has been the trusted name for home repairs and installations across the region. We\'re family-owned, fully licensed, and stand behind every job.</p><p>Our crew is paid hourly, not on commission — so the recommendation you get is the one that\'s actually right for your situation.</p>',
    ],
    'services' => [
        ['name' => 'Inspections',   'description_html' => '<p>Thorough on-site assessment with a written report. Honest pricing.</p>',           'icon_emoji' => '🔍'],
        ['name' => 'Repairs',       'description_html' => '<p>Same-day diagnosis and repair. Most jobs done in one visit.</p>',                 'icon_emoji' => '🔧'],
        ['name' => 'Installations', 'description_html' => '<p>Professional installation with a 10-year parts warranty included.</p>',           'icon_emoji' => '📦'],
        ['name' => 'Maintenance',   'description_html' => '<p>Annual service plans to keep everything running smoothly.</p>',                   'icon_emoji' => '🛠️'],
    ],
    'faq' => [
        ['question' => 'How much do you charge?',         'answer_html' => '<p>Free estimates on all jobs. Most repairs land between $200-800. We give you a flat price up front.</p>'],
        ['question' => 'Do you offer financing?',         'answer_html' => '<p>Yes — flexible payment plans on installations over $3,000.</p>'],
        ['question' => 'How fast can you come out?',      'answer_html' => '<p>Same day for emergency calls; next day for scheduled service.</p>'],
        ['question' => 'Are you licensed and insured?',   'answer_html' => '<p>Yes — fully licensed, bonded, and insured.</p>'],
        ['question' => 'What areas do you serve?',        'answer_html' => '<p>Anytown and surrounding areas within a 25-mile radius.</p>'],
    ],
    'footer_blurb' => 'Trusted home services in Anytown and surrounding areas since 2010.',
];

$assets = [];

$which = (string)($_GET['which'] ?? 'trust_first');
$page  = (string)($_GET['page']  ?? 'home');
$allowedPages = ['home', 'about', 'services', 'service-area', 'contact'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(400);
    exit("page must be one of: " . implode('|', $allowedPages) . "\n");
}
header('Content-Type: text/html; charset=utf-8');
switch ($which) {
    case 'trust_first': echo crm_renderTemplate_trust_first($client, $intake, $copy, $assets, $page); break;
    case 'speed_first': echo crm_renderTemplate_speed_first($client, $intake, $copy, $assets, $page); break;
    case 'story_first': echo crm_renderTemplate_story_first($client, $intake, $copy, $assets, $page); break;
    default:
        http_response_code(400);
        exit("which must be trust_first|speed_first|story_first\n");
}
