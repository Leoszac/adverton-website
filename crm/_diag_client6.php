<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

$db = crm_db();
$row = $db->query("SELECT * FROM client_intake WHERE client_id=6 LIMIT 1")->fetch();

$drafts = json_decode($row['ai_drafts_json'] ?? 'null', true);

header('Content-Type: application/json');
echo json_encode([
    'service_area'  => json_decode($row['service_area_json'] ?? 'null', true),
    'reviews_links' => json_decode($row['reviews_links_json'] ?? 'null', true),
    'draft_keys'    => array_keys((array)$drafts),
    'faq'           => $drafts['faq'] ?? null,
    'template'      => $row['template_choice'] ?? null,
], JSON_PRETTY_PRINT);
