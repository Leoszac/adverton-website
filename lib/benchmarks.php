<?php
if (!defined('AUDIT_ENTRY')) { http_response_code(404); exit; }

// Trade-level benchmarks used in the audit email to create urgency.
// Numbers are conservative estimates of "what a top-performing GBP in this
// trade typically looks like in a U.S. metro." Not pulled from a live
// source — these are calibrated from public industry studies + practitioner
// experience. Refresh annually.

function getBenchmark(string $trade): array {
    $defaults = [
        'top_score'       => 85,
        'avg_rating'      => 4.7,
        'avg_review_ct'   => 60,
        'review_velocity' => 4,   // reviews/month a top performer earns
        'photo_count'     => 25,
        'lost_calls_pct'  => 30,  // headline used in email copy
    ];

    $byTrade = [
        'HVAC'         => ['avg_review_ct' => 80,  'review_velocity' => 6, 'photo_count' => 30],
        'Plumbing'     => ['avg_review_ct' => 75,  'review_velocity' => 5, 'photo_count' => 28],
        'Roofing'      => ['avg_review_ct' => 50,  'review_velocity' => 3, 'photo_count' => 35],
        'Electrical'   => ['avg_review_ct' => 60,  'review_velocity' => 4, 'photo_count' => 22],
        'Solar'        => ['avg_review_ct' => 45,  'review_velocity' => 3, 'photo_count' => 25],
        'Restoration'  => ['avg_review_ct' => 40,  'review_velocity' => 2, 'photo_count' => 20],
        'Garage Door'  => ['avg_review_ct' => 55,  'review_velocity' => 4, 'photo_count' => 18],
        'Pest Control' => ['avg_review_ct' => 70,  'review_velocity' => 5, 'photo_count' => 20],
        'Landscaping'  => ['avg_review_ct' => 50,  'review_velocity' => 3, 'photo_count' => 35],
        'Other'        => [],
    ];

    $key = isset($byTrade[$trade]) ? $trade : 'Other';
    return array_merge($defaults, $byTrade[$key]);
}

function benchmarkLine(string $trade, int $score, int $reviewCount): string {
    $b = getBenchmark($trade);
    $tradeLabel = $trade === 'Other' ? 'top contractors' : "top {$trade} businesses";

    if ($score >= $b['top_score']) {
        return "Your profile is in the top tier for {$tradeLabel} — keep doing what you're doing. Most of the score comes down to consistency from here.";
    }

    $gap = $b['top_score'] - $score;
    $reviewGap = max(0, $b['avg_review_ct'] - $reviewCount);

    if ($reviewGap > 0) {
        return "{$tradeLabel} that win in their market score {$b['top_score']}+ and have {$b['avg_review_ct']}+ Google reviews. You're {$gap} points behind, with {$reviewGap} fewer reviews than the leaders. Conservatively, that's costing you ~{$b['lost_calls_pct']}% of the calls Google could send you.";
    }

    return "{$tradeLabel} that win in their market score {$b['top_score']}+ on this audit. You're {$gap} points behind. Conservatively, that's costing you ~{$b['lost_calls_pct']}% of the calls Google could send you.";
}
