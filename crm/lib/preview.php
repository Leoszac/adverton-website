<?php
// Preview orchestrator — picks the right template, hands it the
// ($client, $intake, $copy, $assets) bundle, returns the rendered HTML.
//
// This is the single entry-point that BOTH /preview.php (public client
// view via magic link) AND /crm/client-review.php (operator iframe)
// call. Same render path, same output — no surprises.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/intake.php';

const CRM_TEMPLATE_DEFAULT = 'trust_first';

// Map template-choice slug → renderer file + function name. Adding a new
// template means adding a row here + a file in crm/web-templates/.
function crm_templateRegistry(): array {
    return [
        'trust_first' => [
            'file' => __DIR__ . '/../web-templates/trust-first.php',
            'fn'   => 'crm_renderTemplate_trust_first',
            'label'=> 'Trust-First',
        ],
        'speed_first' => [
            'file' => __DIR__ . '/../web-templates/speed-first.php',
            'fn'   => 'crm_renderTemplate_speed_first',
            'label'=> 'Speed-First',
        ],
        'story_first' => [
            'file' => __DIR__ . '/../web-templates/story-first.php',
            'fn'   => 'crm_renderTemplate_story_first',
            'label'=> 'Story-First',
        ],
        'seo_local' => [
            'file' => __DIR__ . '/../web-templates/seo-local.php',
            'fn'   => 'crm_renderTemplate_seo_local',
            'label'=> 'SEO Local',
        ],
    ];
}

// URL-safe slug from a city or service name. Shared by the file-map builder
// in crm_renderAllPages() AND by seo-local.php's internal hrefs so the two
// agree exactly (a mismatch = a 404 on the deployed site).
// "Monsey, NY" → "monsey-ny", "New City" → "new-city".
function crm_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? $s : 'item';
}

// Slugify a list of names, de-duplicating collisions ("St. John" and
// "St John" both → "st-john" → second becomes "st-john-2"). Returns an
// ordered map [slug => original name] so callers can iterate deterministically.
function crm_slugifyList(array $names): array {
    $out = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $slug = crm_slugify($name);
        $base = $slug; $n = 2;
        while (isset($out[$slug])) { $slug = $base . '-' . $n; $n++; }
        $out[$slug] = $name;
    }
    return $out;
}

// Best-effort asset listing for the renderer. Returns rows from client_assets
// that are approved (or all if no approval has happened yet — better to
// preview SOMETHING than an empty page). Sprint 3 fills in the source
// pipeline; for now this just queries the table.
function crm_listClientAssets(int $clientId, bool $approvedOnly = true): array {
    try {
        $sql = 'SELECT id, client_id, source, category, original_name, stored_name,
                       mime, ai_description, ai_tags_json, approved
                FROM client_assets
                WHERE client_id = ?'
             . ($approvedOnly ? ' AND approved = TRUE' : '')
             . ' ORDER BY category ASC, uploaded_at DESC';
        $stmt = crm_db()->prepare($sql);
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[crm_listClientAssets] ' . $e->getMessage());
        return [];
    }
}

// Render one page of the preview HTML for a client.
// Returns ['ok'=>bool, 'html'=>?string, 'error'=>?string].
// $page = home | about | services | service-area | contact (default: home).
function crm_renderPreviewHtml(int $clientId, string $page = 'home'): array {
    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'html' => null, 'error' => 'Client not found'];
    $intake = crm_getIntake($clientId);
    if (!$intake) return ['ok' => false, 'html' => null, 'error' => 'No intake — run kickoff first'];
    $copy = is_array($intake['ai_drafts_decoded'] ?? null) ? $intake['ai_drafts_decoded'] : null;
    if (!$copy) return ['ok' => false, 'html' => null, 'error' => 'No AI copy yet — run "Generate" first'];

    $registry = crm_templateRegistry();
    $choice = (string)($intake['template_choice'] ?? '') ?: CRM_TEMPLATE_DEFAULT;
    if (!isset($registry[$choice])) {
        $choice = CRM_TEMPLATE_DEFAULT;
    }
    $entry = $registry[$choice];
    if (!is_readable($entry['file'])) {
        return ['ok' => false, 'html' => null, 'error' => 'Template file missing: ' . basename($entry['file'])];
    }
    require_once $entry['file'];
    if (!function_exists($entry['fn'])) {
        return ['ok' => false, 'html' => null, 'error' => 'Template function missing: ' . $entry['fn']];
    }

    try {
        $assets = crm_listClientAssets($clientId, true);
        $html = call_user_func($entry['fn'], $client, $intake, $copy, $assets, $page);
        return ['ok' => true, 'html' => (string)$html, 'error' => null];
    } catch (Throwable $e) {
        error_log('[crm_renderPreviewHtml] ' . $e->getMessage());
        return ['ok' => false, 'html' => null, 'error' => 'Render exception: ' . $e->getMessage()];
    }
}

// Render all pages of a client site for deploy.
// Returns ['ok'=>bool, 'pages'=>['index.html'=>'<html>', ...], 'error'=>?string].
//
// Most templates emit a FIXED 5-page set. The 'seo_local' template instead
// emits a VARIABLE set: the core pages PLUS one page per service
// (services/{slug}.html) and one per city (locations/{slug}.html). The cPanel
// and SFTP adapters already create nested dirs on upload (CURLFTP_CREATE_DIR),
// so no adapter change is needed — only the page→filename map below.
function crm_renderAllPages(int $clientId): array {
    // Page-key → output filename. Page keys for programmatic pages carry a
    // "service:" / "location:" prefix that crm_renderPreviewHtml passes through
    // to the template (which parses it).
    $filenameMap = crm_pageFilenameMap($clientId);

    $pages = [];
    foreach ($filenameMap as $pageKey => $filename) {
        $r = crm_renderPreviewHtml($clientId, $pageKey);
        if (!$r['ok']) {
            return ['ok' => false, 'pages' => [], 'error' => "render {$pageKey}: " . (string)($r['error'] ?? 'unknown')];
        }
        $pages[$filename] = $r['html'];
    }

    // ── Localize image assets for the client's OWN host ──────────────────
    // Templates emit /crm/asset.php?id=N (served by adverton.net — fine for the
    // preview). A deployed client site must be SELF-CONTAINED, so copy each
    // referenced asset into /assets/img/ on the client host and rewrite the URL
    // to a root-relative path. Scalable: every client serves its own images,
    // no runtime dependency on adverton.net. Image bytes ride along in $pages
    // (the FTP adapters upload any filename→bytes; the WP adapter skips them).
    require_once __DIR__ . '/photos.php';
    $ids = [];
    foreach ($pages as $html) {
        if (preg_match_all('#/crm/asset\.php\?id=(\d+)#', (string)$html, $m)) {
            foreach ($m[1] as $id) { $ids[(int)$id] = true; }
        }
    }
    $idToUrl = [];
    $imgFiles = [];
    foreach (array_keys($ids) as $id) {
        $asset = crm_getAsset($id);
        if (!$asset || empty($asset['disk_path']) || !is_readable($asset['disk_path'])) continue;
        $ext   = crm_assetExt((string)($asset['mime'] ?? ''), (string)($asset['stored_name'] ?? ''));
        $local = 'assets/img/' . $id . '.' . $ext;
        $idToUrl[$id]    = '/' . $local;                       // root-relative
        $imgFiles[$local] = (string) file_get_contents($asset['disk_path']);
    }
    if ($idToUrl) {
        foreach ($pages as $fn => $html) {
            $pages[$fn] = preg_replace_callback(
                '#/crm/asset\.php\?id=(\d+)#',
                static fn($mm) => $idToUrl[(int)$mm[1]] ?? $mm[0],
                (string)$html
            );
        }
        foreach ($imgFiles as $local => $bytes) { $pages[$local] = $bytes; }
    }

    return ['ok' => true, 'pages' => $pages, 'error' => null];
}

// Pick a file extension for a deployed asset from its MIME (fallback: the
// stored filename's extension, else jpg).
function crm_assetExt(string $mime, string $storedName): string {
    static $map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/gif'  => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg',
    ];
    $mime = strtolower(trim($mime));
    if (isset($map[$mime])) return $map[$mime];
    $e = strtolower((string) pathinfo($storedName, PATHINFO_EXTENSION));
    $e = preg_replace('/[^a-z0-9]/', '', $e);
    return ($e !== '' && strlen($e) <= 5) ? $e : 'jpg';
}

// Build the [pageKey => filename] map for a client, branching on the chosen
// template. Exposed separately so the seo-local template can reuse the SAME
// city/service slug lists to build matching internal hrefs.
function crm_pageFilenameMap(int $clientId): array {
    $intake = crm_getIntake($clientId);
    $choice = (string)($intake['template_choice'] ?? '') ?: CRM_TEMPLATE_DEFAULT;

    if ($choice !== 'seo_local') {
        return [
            'home'         => 'index.html',
            'about'        => 'about.html',
            'services'     => 'services.html',
            'service-area' => 'service-area.html',
            'contact'      => 'contact.html',
        ];
    }

    // seo_local: core pages + one page per service + one per city.
    $map = [
        'home'     => 'index.html',
        'services' => 'services.html',
        'locations'=> 'locations.html',
        'reviews'  => 'reviews.html',
        'contact'  => 'contact.html',
    ];

    foreach (crm_seoLocalServiceSlugs($intake) as $slug => $_name) {
        $map['service:' . $slug] = 'services/' . $slug . '.html';
    }
    foreach (crm_seoLocalCitySlugs($intake) as $slug => $_name) {
        $map['location:' . $slug] = 'locations/' . $slug . '.html';
    }
    return $map;
}

// [slug => service name] for the seo_local template. Source: intake services.
function crm_seoLocalServiceSlugs(?array $intake): array {
    $services = is_array($intake['services_decoded'] ?? null) ? $intake['services_decoded'] : [];
    $names = [];
    foreach ($services as $s) {
        $n = trim((string)($s['name'] ?? ''));
        if ($n !== '') $names[] = $n;
    }
    return crm_slugifyList($names);
}

// [slug => city name] for the seo_local template. Source: intake service area
// (only the "cities" mode produces per-city pages; a radius has no city list).
function crm_seoLocalCitySlugs(?array $intake): array {
    $area = is_array($intake['service_area_decoded'] ?? null) ? $intake['service_area_decoded'] : [];
    $cities = ($area['type'] ?? '') === 'cities' && !empty($area['cities']) ? (array)$area['cities'] : [];
    return crm_slugifyList($cities);
}
