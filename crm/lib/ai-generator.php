<?php
// AI copy generator for client websites — wraps the Anthropic Messages API.
//
// One entry point: crm_aiGenerateClientCopy($intake) → returns a structured
// `copy` array consumed by every web template (trust-first / speed-first /
// story-first). The AI fills in hero, about, services, FAQ, footer blurb;
// the template fills in the structure.
//
// Cached on client_intake.ai_drafts_json so re-renders don't burn API quota.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/intake.php';

const CRM_AI_MODEL    = 'claude-sonnet-4-6';
const CRM_AI_MAXTOK   = 4096;
const CRM_AI_TIMEOUT  = 90;     // base budget; scaled up to 300s for large (seo_local) generations

// Run the generation. Returns ['ok'=>bool, 'copy'=>array|null, 'error'=>?string].
// Persists `copy` into client_intake.ai_drafts_json + sets ai_generated_at and
// status='ai_generated' on success. Idempotent on the DB side: re-running
// overwrites the cache.
function crm_aiGenerateClientCopy(int $clientId): array {
    $apiKey = crm_config('ANTHROPIC_API_KEY');
    if (!$apiKey) return ['ok' => false, 'copy' => null, 'error' => 'ANTHROPIC_API_KEY not set'];

    $intake = crm_getIntake($clientId);
    if (!$intake) return ['ok' => false, 'copy' => null, 'error' => 'No intake row for client'];

    // The seo_local template asks for a unique blurb per city + per-service
    // detail copy, so its output is much larger — scale max_tokens with the
    // city/service counts (capped) instead of the flat default.
    $tc = (string)($intake['template_choice'] ?? '');
    $maxTok = CRM_AI_MAXTOK;
    if ($tc === 'seo_local') {
        $area    = (array)($intake['service_area_decoded'] ?? []);
        $nCities = ($area['type'] ?? '') === 'cities' ? count((array)($area['cities'] ?? [])) : 0;
        $nSvcs   = count((array)($intake['services_decoded'] ?? []));
        $maxTok  = min(16000, 4096 + $nCities * 230 + $nSvcs * 160);
    }

    // Scale the HTTP + PHP time budget with output size. A 16k-token seo_local
    // page-set legitimately takes 2–3 min; the flat 60s cap was cutting it off
    // ("Operation timed out after 60002 ms"). ~45 tok/s → 16k ≈ 300s ceiling.
    $timeout = max(CRM_AI_TIMEOUT, min(300, (int)($maxTok / 45)));
    @set_time_limit($timeout + 30);

    $payload = [
        'model'      => CRM_AI_MODEL,
        'max_tokens' => $maxTok,
        'system'     => crm_aiSystemPrompt($tc),
        'messages'   => [
            ['role' => 'user', 'content' => crm_aiUserPrompt($intake)],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'copy' => null,
                'error' => "Anthropic HTTP {$code}: " . substr((string)($resp ?: $err), 0, 400)];
    }

    $data = json_decode((string)$resp, true) ?: [];
    $textOut = '';
    foreach ((array)($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $textOut .= (string)($block['text'] ?? '');
    }
    if ($textOut === '') return ['ok' => false, 'copy' => null, 'error' => 'AI returned no text'];

    $copy = crm_aiExtractJson($textOut);
    if (!$copy) {
        return ['ok' => false, 'copy' => null,
                'error' => 'AI output was not parseable JSON: ' . substr($textOut, 0, 300)];
    }

    // Persist to cache. Status flips to 'ai_generated' so the operator-review
    // dashboard knows to show the preview.
    try {
        $stmt = crm_db()->prepare(
            "UPDATE client_intake
             SET ai_drafts_json = ?, ai_generated_at = NOW(), status = 'ai_generated'
             WHERE client_id = ?"
        );
        $stmt->execute([json_encode($copy), $clientId]);

        // Adopt the AI-picked palette when the client hasn't set brand colors,
        // so every template renders a professional, trade-appropriate theme
        // instead of a generic default. Client-supplied colors always win.
        $existing = (array)($intake['brand_colors_decoded'] ?? []);
        $tp = trim((string)($copy['theme']['primary'] ?? ''));
        $ta = trim((string)($copy['theme']['accent']  ?? ''));
        if (empty($existing['primary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $tp)) {
            crm_db()->prepare('UPDATE client_intake SET brand_colors_json = ? WHERE client_id = ?')
                ->execute([json_encode(['primary' => $tp, 'accent' => (preg_match('/^#[0-9a-fA-F]{6}$/', $ta) ? $ta : '')]), $clientId]);
        }
    } catch (Throwable $e) {
        error_log('[crm_aiGenerateClientCopy persist] ' . $e->getMessage());
        return ['ok' => false, 'copy' => $copy, 'error' => 'DB persist failed: ' . $e->getMessage()];
    }

    return ['ok' => true, 'copy' => $copy, 'error' => null];
}

// System prompt: persona + format + safety. Keep this short; per-client info
// lives in the user prompt.
function crm_aiSystemPrompt(string $templateChoice = ''): string {
    $base = <<<SYS
You are a senior copywriter for U.S. home-service contractors writing
production website copy. Tone: plain English, contractor-language, never
agency-speak. Short sentences. Concrete claims. No buzzwords ("synergy",
"seamless", "innovative", "leverage", "elevate"). No promises we can't
verify ("#1 in your city", "guaranteed savings"). Active voice.

Output STRICTLY valid JSON matching the schema below — nothing before or
after the JSON object, no markdown fences, no commentary. Every value is a
plain string unless the field name says otherwise. HTML is allowed only in
fields suffixed `_html` (use <p>, <strong>, <em>, <ul>, <li>; nothing else).

JSON schema:
{
  "hero": {
    "headline":     "string  // 4–9 words, includes the trade",
    "subheadline":  "string  // 1 sentence, 12–22 words",
    "cta_primary":  "string  // 2–3 words, action verb (e.g. 'Get a free quote')",
    "cta_secondary":"string  // 2–3 words"
  },
  "trust_strip": ["string", "string", "string"],
  "about": {
    "title":     "string  // 3–6 words",
    "body_html": "string  // <p>…</p><p>…</p>, 2–3 short paragraphs"
  },
  "services": [
    {
      "name":             "string  // exactly the service name from the input",
      "description_html": "string  // 1 short paragraph in <p>…</p>",
      "icon_emoji":       "string  // a single emoji that matches the service"
    }
  ],
  "faq": [
    {
      "question":   "string  // ≤ 90 chars, contractor-style",
      "answer_html":"string  // <p>…</p>, 1 short paragraph"
    }
  ],
  "footer_blurb": "string  // 1 sentence describing the business + service area",
  "theme": {
    "primary": "string  // hex (e.g. #1e3a5f). A professional, trustworthy
                primary color that fits THIS trade — deep, confident tones:
                navy, slate, forest green, deep teal, charcoal, oxblood.
                NEVER purple / violet / lilac / lavender / pastels.",
    "accent":  "string  // hex. A complementary CTA/button color with strong
                contrast on white — usually a warm amber/orange or a clean
                green. Must look professional next to the primary."
  }
}

services[] must contain ONE entry per service from the input (same order,
exact `name`). faq[] should contain 5 items. Pick `theme` colors that suit a
licensed home-service contractor — sober and trustworthy, never trendy or
pastel.
SYS;

    if ($templateChoice !== 'seo_local') {
        return $base;
    }

    // The seo_local template builds one landing page per service and one per
    // city. It needs longer per-service copy AND a unique paragraph per city.
    $extra = <<<SEO

ADDITIONAL FIELDS — this site has a dedicated page per service and per city:

1. Add to EACH services[] item a field:
   "detail_html": "string  // 2–3 short <p> paragraphs for the service's own
                   page: what it covers, why it matters, what the homeowner
                   gets. Concrete, plain English. NO '#1' / unverifiable claims."

2. Add a TOP-LEVEL field:
   "locations": [
     {
       "city":       "string  // exactly one of the input cities, verbatim",
       "blurb_html": "string  // 2–4 <p> paragraphs, 130–200 words, written
                      ONLY for this city and genuinely different from every
                      other city's. Open with something real about THIS place
                      (regional climate, terrain, the age/style of local
                      housing, seasonal conditions) and tie it to the specific
                      kinds of jobs that matter there. Vary the angle, opening,
                      length and structure city to city. Plain, concrete
                      contractor English — no buzzwords."
     }
   ]

locations[] must contain ONE entry per city in the input list (verbatim names),
in the same order. Do not invent cities.

CRITICAL — these pages must NOT look mass-produced. Each blurb has to differ
substantially in content, structure AND length, so Google never flags them as
thin or duplicate content. Do NOT write the same paragraph with the city name
swapped. At the same time, use ONLY generally-true local context (regional
climate, typical housing stock, seasonal patterns, the work that's common in
that area) — never invent specific neighborhood names, street names, building
codes, or statistics.
SEO;

    return $base . $extra;
}

// User prompt: feed the intake row as a clean structured snapshot.
function crm_aiUserPrompt(array $intake): string {
    $services = $intake['services_decoded']    ?? [];
    $area     = $intake['service_area_decoded']?? [];
    $hours    = $intake['hours_regular_decoded'] ?? [];
    $reviews  = $intake['reviews_links_decoded'] ?? [];
    $colors   = $intake['brand_colors_decoded'] ?? [];
    $certs    = $intake['certifications_decoded'] ?? [];
    $compete  = $intake['competitors_admired_decoded'] ?? [];

    // Compact area description. The seo_local template renders one page per
    // city, so it needs the FULL list (not capped at 12) to emit a blurb each.
    $isSeoLocal = (string)($intake['template_choice'] ?? '') === 'seo_local';
    $cityCap = $isSeoLocal ? 60 : 12;
    $areaText = '';
    if (($area['type'] ?? '') === 'cities' && !empty($area['cities'])) {
        $areaText = 'Cities: ' . implode(', ', array_slice((array)$area['cities'], 0, $cityCap));
    } elseif (($area['type'] ?? '') === 'radius' && !empty($area['zip'])) {
        $areaText = 'Within ' . (int)$area['radius_mi'] . ' miles of ' . (string)$area['zip'];
    }

    $svcLines = [];
    foreach ((array)$services as $s) {
        $name = trim((string)($s['name'] ?? ''));
        if ($name === '') continue;
        $desc = trim((string)($s['description'] ?? ''));
        $svcLines[] = '- ' . $name . ($desc !== '' ? ' — ' . $desc : '');
    }

    $hoursLines = [];
    foreach ($hours as $day => $h) {
        $open  = trim((string)($h['open']  ?? ''));
        $close = trim((string)($h['close'] ?? ''));
        if ($open === '' && $close === '') continue;
        $hoursLines[] = strtoupper($day) . ': ' . $open . ' – ' . $close;
    }

    $blocks = [];
    $blocks[] = "Business name: " . (string)($intake['display_name'] ?? '');
    if (!empty($intake['tagline']))     $blocks[] = "Tagline: "       . $intake['tagline'];
    if (!empty($intake['story_short'])) $blocks[] = "Short story: "   . $intake['story_short'];
    if (!empty($intake['story_long']))  $blocks[] = "Long story: "    . $intake['story_long'];
    if ($areaText !== '')               $blocks[] = "Service area: "  . $areaText;
    if ($svcLines)                      $blocks[] = "Services:\n"     . implode("\n", $svcLines);
    if ($hoursLines)                    $blocks[] = "Regular hours:\n" . implode("\n", $hoursLines);
    if (!empty($intake['emergency_24_7'])) $blocks[] = "24/7 emergency service: yes";

    $trust = [];
    if (!empty($intake['years_in_business']))   $trust[] = $intake['years_in_business'] . ' years in business';
    if (!empty($intake['license_number']))      $trust[] = 'Licensed (#' . $intake['license_number'] . ')';
    if (!empty($intake['insurance_carrier']))   $trust[] = 'Insured (' . $intake['insurance_carrier'] . ')';
    if (!empty($certs) && is_array($certs))     $trust[] = 'Certifications: ' . implode(', ', $certs);
    if ($trust) $blocks[] = "Trust signals:\n- " . implode("\n- ", $trust);

    $reviewLines = [];
    foreach (['google','yelp','bbb'] as $k) {
        if (!empty($reviews[$k])) $reviewLines[] = strtoupper($k) . ': ' . $reviews[$k];
    }
    if ($reviewLines) $blocks[] = "Review profile URLs:\n" . implode("\n", $reviewLines);

    if (!empty($compete) && is_array($compete)) {
        $blocks[] = "Sites/competitors with a tone we like:\n- " . implode("\n- ", array_slice($compete, 0, 3));
    }
    if (!empty($intake['primary_goal']))   $blocks[] = "Primary goal of the website: " . $intake['primary_goal'];
    if (!empty($intake['template_choice']))$blocks[] = "Chosen layout: " . $intake['template_choice'];

    $head = "Generate the website copy JSON for this contractor. Match the trade vocabulary "
          . "and area in the answers. Return ONLY the JSON object — no preface, no fence.";
    if ($isSeoLocal) {
        $head .= " This is a local-SEO site: include `detail_html` on every service AND a "
               . "`locations` array with one UNIQUE blurb per city listed above (verbatim city names).";
    }
    return $head . "\n\n--- INTAKE ---\n" . implode("\n\n", $blocks);
}

// The Anthropic API sometimes wraps the JSON in markdown fences or includes
// chatter despite the instruction. Strip those + parse defensively.
function crm_aiExtractJson(string $text): ?array {
    $text = trim($text);
    // Strip a single leading fence (```json or ```)
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
        $text = $m[1];
    } else {
        // Or extract the first {...} block if there's prose around it
        if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    }
    $j = json_decode($text, true);
    return is_array($j) ? $j : null;
}
