<?php
// PandaDoc webhook — log proposal sent/viewed/completed activities + auto-create
// client + task to operator on completion (signature).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/tasks.php';

header('Content-Type: text/plain');

$secret = crm_config('PANDADOC_WEBHOOK_SECRET');
if (!$secret) { http_response_code(500); echo "PANDADOC_WEBHOOK_SECRET not configured"; exit; }

$got = $_GET['token'] ?? ($_SERVER['HTTP_X_PANDADOC_SIGNATURE'] ?? '');
if (!hash_equals((string)$secret, (string)$got)) {
    http_response_code(403); echo "bad token"; exit;
}

$payload = file_get_contents('php://input');
$events  = json_decode((string)$payload, true);

// PandaDoc sends an array of events; some integrations send a single object
if (isset($events['event'])) $events = [$events];
if (!is_array($events)) { http_response_code(400); echo "bad payload"; exit; }

foreach ($events as $ev) {
    $event = (string)($ev['event'] ?? '');
    $doc   = $ev['data'] ?? [];
    $docId = (string)($doc['id'] ?? '');
    $email = '';
    foreach (($doc['recipients'] ?? []) as $r) {
        if (!empty($r['email'])) { $email = (string)$r['email']; break; }
    }
    if ($email === '') continue;

    $leadId = crm_findDuplicateLead(strtolower($email), '');
    if (!$leadId) continue;

    if ($event === 'document_state_changed') {
        $state = strtolower((string)($doc['status'] ?? ''));
        if (str_contains($state, 'sent') || str_contains($state, '2')) {
            crm_logActivity($leadId, null, 'email', 'sent',
                'PandaDoc proposal sent · doc ' . $docId);
        } elseif (str_contains($state, 'view')) {
            crm_logActivity($leadId, null, 'email', 'opened',
                'PandaDoc proposal viewed · doc ' . $docId);
        } elseif (str_contains($state, 'complete') || str_contains($state, 'document.completed')) {
            // Signed → bump to won (which auto-promotes to client) + assign onboarding to operator
            crm_logActivity($leadId, null, 'system', 'signed',
                'PandaDoc signed · doc ' . $docId);
            crm_updateLead($leadId, ['status' => 'won'], null);

            $client = crm_getClientByLead($leadId);
            if ($client) {
                crm_updateClient((int)$client['id'], ['pandadoc_doc_id' => $docId], null);
                $operatorId = crm_findOperatorId();
                $name = trim(($client['business_name'] ?? '') ?: ('Client #' . $client['id']));
                crm_createTask([
                    'lead_id'     => $leadId,
                    'assigned_to' => $operatorId,
                    'title'       => 'Onboarding intake — ' . $name,
                    'due_at'      => date('Y-m-d H:i:s', strtotime('+1 day 09:00')),
                    'notes'       => 'Stage 6: send onboarding questionnaire + access auth forms',
                ]);
            }
        }
    }
}

http_response_code(200);
echo "ok";

// Find the operator user (role='operator'); fall back to founder.
function crm_findOperatorId(): ?int {
    try {
        $stmt = crm_db()->query("SELECT id FROM users WHERE role = 'operator' ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
        $stmt = crm_db()->query("SELECT id FROM users WHERE role = 'founder' ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) { return null; }
}
