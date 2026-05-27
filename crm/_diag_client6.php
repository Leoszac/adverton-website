<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

$db = crm_db();
$row = $db->query("SELECT id, service_area_json, copy_json, reviews_links_json FROM clients WHERE id=6")->fetch();

header('Content-Type: application/json');
echo json_encode([
    'service_area' => json_decode($row['service_area_json'] ?? 'null', true),
    'copy_keys'    => array_keys((array)json_decode($row['copy_json'] ?? '{}', true)),
    'faq_count'    => count((array)(json_decode($row['copy_json'] ?? '{}', true)['faq'] ?? [])),
    'reviews'      => json_decode($row['reviews_links_json'] ?? 'null', true),
], JSON_PRETTY_PRINT);
