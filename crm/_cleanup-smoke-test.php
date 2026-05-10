<?php
// One-shot: delete smoke-test lead (test@example-cold.com).
// DELETE after first run.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const ONE_SHOT_TOKEN = 'cleanup-9k7m2q';

header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== ONE_SHOT_TOKEN) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')      { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$db = crm_db();

// Find + delete the smoke-test lead and its activities/etc (cascade)
$stmt = $db->prepare("SELECT id, email FROM leads WHERE email = ?");
$stmt->execute(['test@example-cold.com']);
$lead = $stmt->fetch();

if (!$lead) {
    echo json_encode(['deleted' => false, 'reason' => 'not found']);
    exit;
}

$leadId = (int)$lead['id'];

// Cascade delete (FK should handle most, but be explicit)
$db->prepare('DELETE FROM lead_activities WHERE lead_id = ?')->execute([$leadId]);
$db->prepare('DELETE FROM sequence_enrollments WHERE lead_id = ?')->execute([$leadId]);
$db->prepare('DELETE FROM leads WHERE id = ?')->execute([$leadId]);

echo json_encode(['deleted' => true, 'lead_id' => $leadId]);
