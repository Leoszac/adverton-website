<?php
// Process sequence enrollments — runs each due step.
// Run via cPanel cron:  */15 * * * *  /usr/local/bin/php .../crm/cron-sequences.php

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/sequences.php';
require_once __DIR__ . '/lib/templates.php';
require_once __DIR__ . '/lib/email_track.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/activities.php';

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

$db = crm_db();
$processed = 0; $unenrolled = 0;

// Pull due enrollments
$rows = $db->query(
    "SELECT e.*, s.name AS sequence_name FROM sequence_enrollments e
     JOIN sequences s ON s.id = e.sequence_id
     WHERE e.completed_at IS NULL AND e.next_run_at <= NOW() AND s.active = TRUE
     ORDER BY e.next_run_at ASC LIMIT 200"
)->fetchAll();

foreach ($rows as $enr) {
    $enrId  = (int)$enr['id'];
    $leadId = (int)$enr['lead_id'];
    $lead   = crm_getLead($leadId);
    if (!$lead) {
        $db->prepare('UPDATE sequence_enrollments SET completed_at = NOW(), unenrolled_reason = "lead_gone" WHERE id = ?')->execute([$enrId]);
        $unenrolled++; continue;
    }
    // Hard stops
    if (in_array($lead['status'], ['won','lost'], true)) {
        $db->prepare('UPDATE sequence_enrollments SET completed_at = NOW(), unenrolled_reason = ? WHERE id = ?')
           ->execute(['status_' . $lead['status'], $enrId]);
        $unenrolled++; continue;
    }
    // DNC tag check
    $stmt = $db->prepare(
        "SELECT 1 FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id
         WHERE lt.lead_id = ? AND t.name IN ('dnc','do not contact','at-risk') LIMIT 1"
    );
    $stmt->execute([$leadId]);
    if ($stmt->fetch()) {
        $db->prepare('UPDATE sequence_enrollments SET completed_at = NOW(), unenrolled_reason = "dnc" WHERE id = ?')->execute([$enrId]);
        $unenrolled++; continue;
    }

    // Get next step
    $stmt = $db->prepare(
        'SELECT * FROM sequence_steps WHERE sequence_id = ? AND step_order = ? LIMIT 1'
    );
    $stmt->execute([(int)$enr['sequence_id'], (int)$enr['current_step'] + 1]);
    $step = $stmt->fetch();
    if (!$step) {
        $db->prepare('UPDATE sequence_enrollments SET completed_at = NOW(), unenrolled_reason = "completed" WHERE id = ?')->execute([$enrId]);
        $unenrolled++; continue;
    }

    $payload = json_decode((string)$step['payload'], true) ?: [];
    $action = (string)$step['action'];
    $ok = true;

    try {
        if ($action === 'send_template') {
            $tplId = (int)($payload['template_id'] ?? 0);
            $tpl = $tplId > 0 ? crm_getTemplate($tplId) : null;
            if ($tpl && !empty($lead['email'])) {
                $subject = crm_renderTemplate($tpl['subject'], $lead);
                $body    = crm_renderTemplate($tpl['body'],    $lead);
                $r = crm_sendTrackedEmail($leadId, $lead, $tplId, null, $subject, $body);
                $ok = $r['ok'];
                if (!$ok) error_log("[seq] send failed lead={$leadId} tpl={$tplId}: " . ($r['error'] ?? '?'));
            }
        } elseif ($action === 'create_task') {
            $title = strtr((string)($payload['title'] ?? 'Follow up'), [
                '{first_name}'    => $lead['first_name'] ?? '',
                '{business_name}' => $lead['business_name'] ?? '',
            ]);
            crm_createTask([
                'lead_id'     => $leadId,
                'assigned_to' => $lead['owner_user_id'] ?? null,
                'title'       => $title,
                'due_at'      => date('Y-m-d 10:00:00', strtotime('+1 day')),
            ]);
        } elseif ($action === 'add_tag') {
            crm_addTagToLead($leadId, (string)($payload['tag'] ?? ''));
        } elseif ($action === 'remove_tag') {
            $tagId = crm_findTagId((string)($payload['tag'] ?? ''));
            if ($tagId) crm_removeTagFromLead($leadId, $tagId);
        }
    } catch (Throwable $e) {
        error_log('[seq step] ' . $e->getMessage()); $ok = false;
    }

    // Look ahead to next step's delay (for next_run_at)
    $stmt = $db->prepare('SELECT delay_days FROM sequence_steps WHERE sequence_id = ? AND step_order = ?');
    $stmt->execute([(int)$enr['sequence_id'], (int)$enr['current_step'] + 2]);
    $nextRow = $stmt->fetch();

    if ($nextRow) {
        $nextRun = date('Y-m-d H:i:s', strtotime('+' . max(0, (int)$nextRow['delay_days']) . ' days'));
        $db->prepare(
            'UPDATE sequence_enrollments
             SET current_step = current_step + 1, next_run_at = ?
             WHERE id = ?'
        )->execute([$nextRun, $enrId]);
    } else {
        $db->prepare(
            'UPDATE sequence_enrollments SET current_step = current_step + 1, completed_at = NOW(),
             unenrolled_reason = "completed" WHERE id = ?'
        )->execute([$enrId]);
    }
    $processed++;
}

echo "Sequences: processed={$processed} unenrolled={$unenrolled}\n";
