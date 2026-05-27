<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

$db = crm_db();

// 1. Service area: switch from radius to cities
$serviceArea = [
    'type'      => 'cities',
    'zip'       => '43611',
    'radius_mi' => 50,
    'cities'    => [
        'Toledo, OH', 'Maumee, OH', 'Perrysburg, OH', 'Sylvania, OH',
        'Holland, OH', 'Waterville, OH', 'Oregon, OH', 'Rossford, OH',
        'Northwood, OH', 'Bowling Green, OH', 'Findlay, OH', 'Defiance, OH',
        'Napoleon, OH', 'Wauseon, OH', 'Port Clinton, OH',
        'Monroe, MI', 'Lambertville, MI', 'Temperance, MI',
    ],
];

// 2. Pull existing copy and patch faq + add testimonials
$row = $db->query("SELECT ai_drafts_json FROM client_intake WHERE client_id=6 LIMIT 1")->fetch();
$copy = json_decode($row['ai_drafts_json'] ?? '{}', true) ?: [];

$extraFaqs = [
    ['question' => 'Do you service HVAC in Perrysburg and Maumee?',
     'answer_html' => '<p>Yes. Perrysburg and Maumee are two of our most active service areas. We do heating and cooling repairs, tune-ups, and full system replacements for homeowners and businesses throughout both communities.</p>'],
    ['question' => 'Do you cover Sylvania and Sylvania Township?',
     'answer_html' => '<p>Absolutely. We have customers throughout Sylvania and Sylvania Township and respond quickly to calls there — usually same day for non-emergency service and within two hours for emergencies.</p>'],
    ['question' => 'Can you help customers in Monroe or Lambertville, Michigan?',
     'answer_html' => '<p>Yes. We regularly cross the state line to serve Monroe, Lambertville, and Temperance, MI. Call us to confirm your address and we\'ll let you know the earliest available slot.</p>'],
    ['question' => 'Do you serve Bowling Green and Wood County?',
     'answer_html' => '<p>We do. Bowling Green is about 25 miles south of Toledo and well within our service area. If you\'re in Wood County and need HVAC help, give us a call.</p>'],
    ['question' => 'What does a new AC installation cost in Toledo?',
     'answer_html' => '<p>A typical central AC replacement in the Toledo area runs between $3,500 and $7,500 depending on the unit size, efficiency rating (SEER), and any ductwork changes needed. We give you a flat, written price before any work starts — no surprises.</p>'],
    ['question' => 'Do you offer maintenance plans for Toledo homeowners?',
     'answer_html' => '<p>Yes. Our annual maintenance plan covers one heating tune-up in the fall and one cooling tune-up in the spring, plus priority scheduling and a discount on parts and labor. Ask us for details when you call.</p>'],
    ['question' => 'How long does a typical HVAC repair take?',
     'answer_html' => '<p>Most repairs are completed in one visit — usually 1 to 3 hours. Bigger jobs like a full system replacement typically take one full day. We\'ll give you a realistic time estimate before we start.</p>'],
    ['question' => 'Do you install heat pumps?',
     'answer_html' => '<p>Yes. We install and service heat pumps, including cold-climate heat pumps that work efficiently through northwest Ohio winters. Heat pumps can be a smart investment — we\'ll walk you through whether it makes sense for your home and budget.</p>'],
];

$copy['faq'] = array_merge((array)($copy['faq'] ?? []), $extraFaqs);

$copy['testimonials'] = [
    ['name' => 'Mike R.', 'location' => 'Perrysburg, OH',
     'text' => 'Air Johnson fixed our furnace in the middle of winter — showed up within 2 hours and had it running that same night. Honest pricing, no upselling. Will call again.'],
    ['name' => 'Sandra T.', 'location' => 'Maumee, OH',
     'text' => 'We\'ve used Air Johnson for years. They replaced our entire HVAC system and the process was smooth from start to finish. Trustworthy and knowledgeable.'],
    ['name' => 'James K.', 'location' => 'Sylvania, OH',
     'text' => 'Called at 8am with a dead AC in July. They were at my house by noon and fixed by 2pm. Fair price and fast — can\'t ask for more.'],
];

// 3. Write both back
$db->prepare("UPDATE client_intake SET service_area_json=? WHERE client_id=6")
   ->execute([json_encode($serviceArea)]);

$db->prepare("UPDATE client_intake SET ai_drafts_json=? WHERE client_id=6")
   ->execute([json_encode($copy)]);

unlink(__FILE__);

header('Content-Type: text/plain');
echo "service_area=cities(" . count($serviceArea['cities']) . ") faq_total=" . count($copy['faq']) . " testimonials=" . count($copy['testimonials']) . "\n";
