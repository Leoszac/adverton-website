<?php
if (!defined('AUDIT_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/places-api.php';

// Resolve any user-pasted Google URL → place_id.
//
// Accepted formats (UNAMBIGUOUS):
//   - https://www.google.com/maps/place/{slug}/@{lat},{lng},{z}/data=...
//   - https://maps.app.goo.gl/{short}     (resolved via redirect)
//   - https://g.page/{short}              (resolved via redirect)
//   - https://goo.gl/maps/{short}         (resolved via redirect)
//
// Rejected formats (AMBIGUOUS — multiple businesses could match):
//   - /maps?q=... search-output URLs
//   - /search?q=... google search URLs without a specific listing context
//
// Returns ['place_id' => ..., 'matched_name' => ..., 'final_url' => ...]
// Throws GbpResolverException on any failure.

class GbpResolverException extends RuntimeException {
    // One of: 'invalid_url', 'ambiguous_url', 'not_found'
    public string $kind;
    public string $userMessage;
    public function __construct(string $kind, string $devMsg, string $userMsg) {
        parent::__construct($devMsg);
        $this->kind = $kind;
        $this->userMessage = $userMsg;
    }
}

const GBP_AMBIGUOUS_MSG = "That URL is a search — it points to multiple businesses. Open Google Maps, click your specific listing, then click Share → Copy link and paste that.";
const GBP_INVALID_MSG   = "That doesn't look like a Google Maps link. Open Google Maps, search your business, click your listing, then click Share → Copy link.";
const GBP_NOTFOUND_MSG  = "We couldn't find that business in Google Places. Make sure your Google Business Profile is published, then try again.";

function resolveGbpUrl(string $url): array {
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        throw new GbpResolverException('invalid_url', "not a URL: $url", GBP_INVALID_MSG);
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!gbpIsAllowedHost($host)) {
        throw new GbpResolverException('invalid_url', "disallowed host: $host", GBP_INVALID_MSG);
    }

    // Follow redirects for short-link forms
    if (gbpIsShortHost($host)) {
        $url = gbpFollowRedirects($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!gbpIsAllowedHost($host)) {
            throw new GbpResolverException('invalid_url', "redirected to disallowed host: $host", GBP_INVALID_MSG);
        }
    }

    $path  = (string) parse_url($url, PHP_URL_PATH);
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // Reject ambiguous search URLs early
    if (strpos($path, '/maps/place/') === false) {
        $hasSearchOutput = isset($query['output']) && $query['output'] === 'search';
        $hasOnlyQuery    = isset($query['q']) && !isset($query['kgmid']);
        if ($hasSearchOutput || $hasOnlyQuery) {
            throw new GbpResolverException('ambiguous_url', "ambiguous search URL: $url", GBP_AMBIGUOUS_MSG);
        }
        throw new GbpResolverException('invalid_url', "no /maps/place/ in path: $path", GBP_INVALID_MSG);
    }

    // Extract slug + (lat, lng) from /maps/place/{slug}/@lat,lng,zoom/...
    if (!preg_match('#/maps/place/([^/]+)(?:/@(-?\d+\.\d+),(-?\d+\.\d+),)?#', $url, $m)) {
        throw new GbpResolverException('invalid_url', "could not parse /maps/place/ segment: $url", GBP_INVALID_MSG);
    }
    $slug = urldecode($m[1]);
    $slug = str_replace('+', ' ', $slug);
    $slug = trim($slug);
    if ($slug === '') {
        throw new GbpResolverException('invalid_url', "empty slug", GBP_INVALID_MSG);
    }

    $bias = null;
    if (!empty($m[2]) && !empty($m[3])) {
        $bias = ['lat' => (float)$m[2], 'lng' => (float)$m[3], 'radius_m' => 500.0];
    }

    // Text Search with locationBias (if available) — should yield 1 high-confidence match
    $results = placesTextSearch($slug, $bias, 5);
    if (empty($results)) {
        // Try without bias as a last resort
        if ($bias) $results = placesTextSearch($slug, null, 5);
    }
    if (empty($results)) {
        throw new GbpResolverException('not_found', "no results for: $slug", GBP_NOTFOUND_MSG);
    }

    // Pick best match: closest to bias center if we have it, else first result
    $best = $results[0];
    if ($bias && count($results) > 1) {
        $bestDist = PHP_FLOAT_MAX;
        foreach ($results as $r) {
            $loc = $r['location'] ?? null;
            if (!$loc) continue;
            $d = haversineMeters($bias['lat'], $bias['lng'], $loc['latitude'], $loc['longitude']);
            if ($d < $bestDist) { $bestDist = $d; $best = $r; }
        }
    }

    if (empty($best['id'])) {
        throw new GbpResolverException('not_found', "first result has no id", GBP_NOTFOUND_MSG);
    }

    // Fuzzy sanity check: does the matched name reasonably match the slug?
    $matchedName = $best['displayName']['text'] ?? '';
    if ($matchedName !== '' && !nameMatches($slug, $matchedName)) {
        // Not fatal — still return, but log so we can review.
        error_log("[gbp-resolver] name mismatch: slug=\"$slug\" matched=\"$matchedName\"");
    }

    return [
        'place_id'     => $best['id'],
        'matched_name' => $matchedName,
        'final_url'    => $url,
    ];
}

function gbpIsAllowedHost(string $host): bool {
    $allowed = ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'g.page', 'goo.gl'];
    return in_array($host, $allowed, true);
}

function gbpIsShortHost(string $host): bool {
    return in_array($host, ['maps.app.goo.gl', 'g.page', 'goo.gl'], true);
}

function gbpFollowRedirects(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOBODY         => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AdvertonAuditBot/1.0)',
    ]);
    curl_exec($ch);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err   = curl_error($ch);
    curl_close($ch);

    if (!$final) {
        throw new GbpResolverException('invalid_url', "redirect follow failed: $err", GBP_INVALID_MSG);
    }
    return $final;
}

function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return 2 * $R * asin(sqrt($a));
}

function nameMatches(string $slug, string $matched): bool {
    $norm = fn(string $s) => preg_replace('/[^a-z0-9]/', '', strtolower($s));
    $a = $norm($slug);
    $b = $norm($matched);
    if ($a === '' || $b === '') return true;
    if (strpos($b, $a) !== false || strpos($a, $b) !== false) return true;
    similar_text($a, $b, $pct);
    return $pct >= 60.0;
}
