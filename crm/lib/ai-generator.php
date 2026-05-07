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
const CRM_AI_TIMEOUT  = 60;     // generation can take 30–45s with reasoning

// Run the generation. Returns ['ok'=>bool, 'copy'=>array|null, 'error'=>?string].
// Persists `copy` into client_intake.ai_drafts_json + sets ai_generated_at and
// status='ai_generated' on success. Idempotent on the DB side: re-running
// overwrites the cache.
function crm_aiGenerateClientCopy(int $clientId): array {
    $apiKey = crm_config('ANTHROPIC_API_KEY');
    if (!$apiKey) return ['ok' => false, 'copy' => null, 'error' => 'ANTHROPIC_API_KEY not set'];

    $intake = crm_getIntake($clientId);
    if (!$intake) return ['ok' => false, 'copy' => null, 'error' => 'No intake row for client'];

    $payload = [
        'model'      => CRM_AI_MODEL,
        'max_tokens' => CRM_AI_MAXTOK,
        'system'     => crm_aiSystemPrompt(),
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
        CURLOPT_TIMEOUT        => CRM_AI_TIMEOUT,
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
    } catch (Throwable $e) {
        error_log('[crm_aiGenerateClientCopy persist] ' . $e->getMessage());
        return ['ok' => false, 'copy' => $copy, 'error' => 'DB persist failed: ' . $e->getMessage()];
    }

    return ['ok' => true, 'copy' => $copy, 'error' => null];
}

// System prompt: persona + format + safety. Keep this short; per-client info
// lives in the user prompt.
function crm_aiSystemPrompt(): string {
    return <<<SYS
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
  "footer_blurb": "string  // 1 sentence describing the business + service area"
}

services[] must contain ONE entry per service from the input (same order,
exact `name`). faq[] should contain 5 items.
SYS;
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

    // Compact area description
    $areaText = '';
    if (($area['type'] ?? '') === 'cities' && !empty($area['cities'])) {
        $areaText = 'Cities: ' . implode(', ', array_slice((array)$area['cities'], 0, 12));
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
