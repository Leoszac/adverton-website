<?php
if (!defined('AUDIT_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/website-check.php';

// Score a Place Details payload. 10 checks × 10 pts = 0..100.
// Tips are personalized by trade where it adds real signal.

const GENERIC_TYPES = [
    'establishment', 'point_of_interest', 'general_contractor',
    'business', 'service', 'store', 'food', 'health',
];

function scoreAudit(array $place, string $trade = 'Other'): array {
    $checks = [];

    $tradeNoun     = tradeNoun($trade);          // "plumber", "HVAC tech", etc.
    $tradePhotoTip = tradePhotoExamples($trade); // "repairs, your trucks, your team"

    // 1. Profile claimed & operational
    $status = $place['businessStatus'] ?? '';
    $checks[] = mkCheck(
        'profile_operational',
        'Profile is claimed and operational',
        $status === 'OPERATIONAL',
        "Your profile is marked closed or non-operational. Sign in at business.google.com and reopen — every day it's flagged is calls walking to a competitor."
    );

    // 2. Phone listed
    $phone = $place['nationalPhoneNumber'] ?? $place['internationalPhoneNumber'] ?? '';
    $checks[] = mkCheck(
        'phone_listed',
        'Phone number listed',
        !empty($phone),
        "There's no phone number on your profile. About 6 in 10 calls from Google come from the call button — without it, customers go to whoever's next on the list."
    );

    // 3. Website listed AND alive
    $websiteUri = $place['websiteUri'] ?? '';
    $hasWebsite = !empty($websiteUri);
    $websiteAlive = false;
    $websiteCheck = null;
    if ($hasWebsite) {
        $websiteCheck = checkWebsiteAlive($websiteUri);
        $websiteAlive = $websiteCheck['alive'];
    }
    $websiteCheckPasses = $hasWebsite && $websiteAlive;

    if (!$hasWebsite) {
        $tip = "There's no website link on your profile. Even a one-page site lifts conversion 30%+ — Google's local pack favors profiles that link out.";
    } elseif (!$websiteAlive) {
        $code = $websiteCheck['http_code'] ?? 0;
        $reason = $code > 0 ? "is returning HTTP {$code}" : "isn't loading";
        $tip = "Your website link {$reason}. Customers clicking from Google see a broken page — that's worse than no link at all. Fix the URL on your profile or rebuild the site.";
    } else {
        $tip = '';
    }
    $checks[] = mkCheck('website_alive', 'Website link added and live', $websiteCheckPasses, $tip);

    // 4. Hours filled in
    $hours = $place['regularOpeningHours'] ?? null;
    $checks[] = mkCheck(
        'hours_filled',
        'Business hours set',
        !empty($hours),
        "Your hours aren't set. Google demotes profiles without hours — and customers searching at 9pm don't want to guess if you're open."
    );

    // 5. ≥10 photos
    $photoCount = isset($place['photos']) ? count($place['photos']) : 0;
    $checks[] = mkCheck(
        'photos_10',
        '10+ photos uploaded',
        $photoCount >= 10,
        "You have {$photoCount} photo(s). Profiles with 10+ photos get 42% more direction requests. Add {$tradePhotoTip}."
    );

    // 6. Rating ≥ 4.5
    $rating = (float)($place['rating'] ?? 0);
    $ratingFmt = $rating > 0 ? number_format($rating, 1) : '—';
    $checks[] = mkCheck(
        'rating_45',
        'Rating ≥ 4.5 stars',
        $rating >= 4.5,
        "Your rating is {$ratingFmt}. Anything below 4.5 and customers scroll past you to the competitor with the gold-star strip in their result."
    );

    // 7. ≥25 reviews
    $reviewCount = (int)($place['userRatingCount'] ?? 0);
    $checks[] = mkCheck(
        'reviews_25',
        '25+ Google reviews',
        $reviewCount >= 25,
        "You have {$reviewCount} review(s). 25+ is the credibility threshold — until you cross it, Google ranks you below 'thin' competitors. Text every customer for 30 days."
    );

    // 8. Recent review (≤60 days)
    $reviews = $place['reviews'] ?? [];
    $latestReviewTs = 0;
    foreach ($reviews as $r) {
        $t = isset($r['publishTime']) ? strtotime($r['publishTime']) : 0;
        if ($t > $latestReviewTs) $latestReviewTs = $t;
    }
    $hasRecent = $latestReviewTs > 0 && $latestReviewTs >= strtotime('-60 days');
    $latestFmt = $latestReviewTs > 0 ? date('M Y', $latestReviewTs) : 'none on record';
    $checks[] = mkCheck(
        'recent_review',
        'Reviewed in the last 60 days',
        $hasRecent,
        "Your most recent review is from {$latestFmt}. Google's local algo weighs review velocity heavily — even one review/week keeps you ranked. Ask 3 happy customers this week."
    );

    // 9. Review depth — written content vs. star-only
    $withText = 0;
    foreach ($reviews as $r) {
        $t = $r['text']['text'] ?? $r['text'] ?? null;
        if (is_string($t) && strlen(trim($t)) >= 25) $withText++;
    }
    $reviewSampleN = max(1, count($reviews));
    $hasDepth = $reviewSampleN >= 3 && ($withText / $reviewSampleN) >= 0.6;
    $checks[] = mkCheck(
        'review_depth',
        'Reviews include written detail',
        $hasDepth,
        "Most of your recent reviews are star-only with no text. Reviews with detail rank higher and convert browsers into callers. When you ask for a review, prompt them to mention what you fixed and what city they're in."
    );

    // 10. Specific trade category
    $primary = strtolower($place['primaryType'] ?? '');
    $isSpecific = $primary !== '' && !in_array($primary, GENERIC_TYPES, true);
    $primaryDisplay = $primary !== '' ? str_replace('_', ' ', $primary) : 'not set';
    $checks[] = mkCheck(
        'specific_category',
        'Specific primary category set',
        $isSpecific,
        "Your primary category is generic (\"{$primaryDisplay}\"). Switch it to a trade-specific Google category (e.g. \"{$tradeNoun}\" or the most specific match) — this single change can lift your local rank fast."
    );

    // Compute score
    $passed = 0;
    foreach ($checks as $c) if ($c['pass']) $passed++;
    $score = $passed * 10;

    return [
        'score'        => $score,
        'checks'       => $checks,
        'passed_count' => $passed,
        'total_count'  => count($checks),
        'rating'       => $rating,
        'review_count' => $reviewCount,
        'photo_count'  => $photoCount,
    ];
}

function mkCheck(string $key, string $label, bool $pass, string $tip): array {
    return ['key' => $key, 'label' => $label, 'pass' => $pass, 'tip' => $tip];
}

// Top N failed checks ranked by impact.
function topFailedChecks(array $auditResult, int $n = 3): array {
    $priority = [
        'profile_operational', 'specific_category', 'reviews_25', 'rating_45',
        'website_alive', 'photos_10', 'recent_review', 'review_depth',
        'phone_listed', 'hours_filled',
    ];
    $byKey = [];
    foreach ($auditResult['checks'] as $c) $byKey[$c['key']] = $c;
    $failed = [];
    foreach ($priority as $key) {
        if (isset($byKey[$key]) && !$byKey[$key]['pass']) $failed[] = $byKey[$key];
        if (count($failed) >= $n) break;
    }
    return $failed;
}

// Lead temperature for the notification email subject.
function classifyLead(array $auditResult, array $form): string {
    $score = $auditResult['score'];
    $trade = $form['trade'] ?? 'Other';
    $phone = preg_replace('/\D/', '', $form['phone'] ?? '');
    $phoneOk = strlen($phone) >= 10;

    $coreTrades = ['HVAC', 'Plumbing', 'Roofing', 'Electrical'];

    if ($score < 50 && $phoneOk && in_array($trade, $coreTrades, true)) return 'HOT';
    if ($score <= 75) return 'WARM';
    return 'COLD';
}

// Trade-specific helpers — used to personalize tips.

function tradeNoun(string $trade): string {
    return [
        'HVAC'         => 'HVAC contractor',
        'Plumbing'     => 'Plumber',
        'Roofing'      => 'Roofing contractor',
        'Electrical'   => 'Electrician',
        'Solar'        => 'Solar installer',
        'Restoration'  => 'Water damage restoration service',
        'Garage Door'  => 'Garage door service',
        'Pest Control' => 'Pest control service',
        'Landscaping'  => 'Landscaper',
        'Other'        => 'home service contractor',
    ][$trade] ?? 'home service contractor';
}

function tradePhotoExamples(string $trade): string {
    return [
        'HVAC'         => 'recent installs, your service trucks, branded uniforms, and the team on a job',
        'Plumbing'     => 'before/after of repairs, your trucks, branded uniforms, and the team',
        'Roofing'      => 'before/after roof shots, drone overheads, your crew, and finished jobs',
        'Electrical'   => 'panel upgrades, your trucks, the team, and finished installs',
        'Solar'        => 'completed installs, drone shots of arrays, your trucks, and the crew',
        'Restoration'  => 'before/after of jobs, your equipment, your trucks, and the team',
        'Garage Door'  => 'finished installs, your trucks, the team, and showroom shots',
        'Pest Control' => 'your trucks, branded uniforms, the team, and the equipment you use',
        'Landscaping'  => 'before/after yard transformations, drone shots, your equipment, and the crew',
        'Other'        => 'your trucks, your team, branded uniforms, and finished work',
    ][$trade] ?? 'your trucks, your team, branded uniforms, and finished work';
}

// Custom intro paragraph for the email — adds personality + trade fluency.
function tradeIntroLine(string $trade, int $score): string {
    $hot = "Quick truth: contractors who win their market on Google aren't necessarily better at the work — they just take their profile seriously. Here's what we'd fix first.";

    $custom = [
        'HVAC'         => "We've audited a lot of HVAC profiles. The pattern is the same: techs who own service trucks, run good crews, and still lose calls because their Google profile is stuck in 2019.",
        'Plumbing'     => "We've audited a lot of plumbers. The story repeats: solid work, loyal customers, and a Google profile that's leaking calls because a few easy fixes never got done.",
        'Roofing'      => "We've audited a lot of roofers. Most do good work but never feed Google the photos and reviews it needs to push them up the local pack — that's where the storm-chasers steal calls.",
        'Electrical'   => "We've audited a lot of electricians. Most of them are technically sharp but their profile sells them short — generic categories, sparse photos, dated reviews.",
        'Solar'        => "We've audited a lot of solar installers. The pattern: technical credentials are strong, but the profile reads like an afterthought — and homeowners decide on visuals.",
        'Restoration'  => "We've audited a lot of restoration shops. When a pipe bursts at 11pm, the homeowner picks the first profile that looks legit. If yours doesn't, you don't get the call.",
        'Garage Door'  => "We've audited a lot of garage-door services. People decide in 10 seconds — phone, photos, rating. Miss any of those and the next listing wins.",
        'Pest Control' => "We've audited a lot of pest control companies. Reviews and recency are everything in this trade — fall behind and the franchise next door eats your share.",
        'Landscaping'  => "We've audited a lot of landscaping outfits. Photo-driven trade, but most profiles have 4 stale photos and call it a day. Fix that and you compound clicks.",
    ];
    return $custom[$trade] ?? $hot;
}

// CTA framing varies by lead temperature.
function tradeCtaCopy(string $temperature): array {
    if ($temperature === 'HOT') {
        return [
            'headline' => "These fixes are 1-2 weeks of focused work — or a 15-minute call.",
            'body'     => "We bundle website + Google ads + reviews + GBP management at <strong>$799/month flat</strong>. If your score is in the red, we'll show you exactly what we'd change in week 1.",
            'button'   => "Show me what you'd fix",
        ];
    }
    if ($temperature === 'WARM') {
        return [
            'headline' => "Want us to take this from \"okay\" to \"top of the pack\"?",
            'body'     => "We run the website, Google ads, GBP, and review automation for <strong>$799/month flat</strong> — one team, one bill. If you'd rather DIY the fixes, this audit is yours to keep.",
            'button'   => "Book a 15-min call",
        ];
    }
    return [
        'headline' => "You're in good shape. Want to compound?",
        'body'     => "Most contractors with your score plateau because they stop pushing the system that got them here. We keep it running for <strong>$799/month flat</strong> so you don't have to think about it.",
        'button'   => "See what's included",
    ];
}
