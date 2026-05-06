<?php
// Daily — re-pitch leads lost by 'timing' or 'no_response' at day 60.
// Other lost reasons (price, not_a_fit, competitor) are respected.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/activities.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

$db = crm_db();
$pitched = 0;

$rows = $db->query(
    "SELECT id, owner_user_id, first_name, business_name, lost_reason, updated_at
     FROM leads
     WHERE status = 'lost'
       AND lost_reason IN ('timing','no_response')
       AND updated_at <= DATE_SUB(NOW(), INTERVAL 60 DAY)
       AND updated_at >= DATE_SUB(NOW(), INTERVAL 75 DAY)"
)->fetchAll();

foreach ($rows as $l) {
    $leadId = (int)$l['id'];
    $title  = "Re-engage {$l['business_name']} (lost by {$l['lost_reason']} 60d ago)";

    // Skip if already a re-engage task is open
    $stmt = $db->prepare(
        'SELECT 1 FROM tasks WHERE lead_id = ? AND title LIKE "Re-engage%" AND done_at IS NULL LIMIT 1'
    );
    $stmt->execute([$leadId]);
    if ($stmt->fetch()) continue;

    crm_createTask([
        'lead_id'     => $leadId,
        'assigned_to' => $l['owner_user_id'] ?? null,
        'title'       => $title,
        'due_at'      => date('Y-m-d 10:00:00', strtotime('+1 day')),
    ]);
    crm_addTagToLead($leadId, 'second-chance');
    crm_logActivity($leadId, null, 'system', 'reengage_scheduled',
        "60-day re-engagement window opened (lost: {$l['lost_reason']})");
    $pitched++;
}

echo "Lost re-engage · pitched={$pitched}\n";
