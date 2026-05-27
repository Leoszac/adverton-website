<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

$db = crm_db();
$row = $db->query("SELECT service_area_json, ai_drafts_json FROM client_intake WHERE client_id=6 LIMIT 1")->fetch();

// Remove MI cities
$area = json_decode($row['service_area_json'] ?? '{}', true) ?: [];
$before = count($area['cities'] ?? []);
$area['cities'] = array_values(array_filter(
    (array)($area['cities'] ?? []),
    fn($c) => !str_ends_with(trim((string)$c), ', MI')
));
$after = count($area['cities']);

$db->prepare("UPDATE client_intake SET service_area_json=? WHERE client_id=6")
   ->execute([json_encode($area)]);

// Check FAQ count
$copy = json_decode($row['ai_drafts_json'] ?? '{}', true) ?: [];
$faqCount = count($copy['faq'] ?? []);

// Also remove MI testimonials reference (none exist, but check)
$testimonialsCount = count($copy['testimonials'] ?? []);

unlink(__FILE__);

header('Content-Type: text/plain');
echo "cities_before={$before} cities_after={$after} mi_removed=" . ($before - $after) . " faq_total={$faqCount} testimonials={$testimonialsCount}\n";
echo "Cities: " . implode(', ', $area['cities']) . "\n";
