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
        // Sprint 5 will fill these in:
        // 'speed_first' => ['file' => …, 'fn' => 'crm_renderTemplate_speed_first', …],
        // 'story_first' => ['file' => …, 'fn' => 'crm_renderTemplate_story_first', …],
    ];
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

// Render the preview HTML for a client. Returns ['ok'=>bool, 'html'=>?string,
// 'error'=>?string]. Falls back to the default template if the chosen one
// isn't registered yet.
function crm_renderPreviewHtml(int $clientId): array {
    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'html' => null, 'error' => 'Client not found'];
    $intake = crm_getIntake($clientId);
    if (!$intake) return ['ok' => false, 'html' => null, 'error' => 'No intake — run kickoff first'];
    $copy = is_array($intake['ai_drafts_decoded'] ?? null) ? $intake['ai_drafts_decoded'] : null;
    if (!$copy) return ['ok' => false, 'html' => null, 'error' => 'No AI copy yet — run "Generate" first'];

    $registry = crm_templateRegistry();
    $choice = (string)($intake['template_choice'] ?? '') ?: CRM_TEMPLATE_DEFAULT;
    if (!isset($registry[$choice])) {
        // Pending template — fall back so the operator sees something.
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
        $html = call_user_func($entry['fn'], $client, $intake, $copy, $assets);
        return ['ok' => true, 'html' => (string)$html, 'error' => null];
    } catch (Throwable $e) {
        error_log('[crm_renderPreviewHtml] ' . $e->getMessage());
        return ['ok' => false, 'html' => null, 'error' => 'Render exception: ' . $e->getMessage()];
    }
}
