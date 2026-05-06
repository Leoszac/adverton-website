<?php
// Drip sequences — auto-enroll leads on triggers, run steps with delays,
// auto-unenroll on reply / status change to won/lost / DNC tag.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_SEQ_TRIGGERS = [
    'status_change_to_new',
    'status_change_to_contacted',
    'status_change_to_qualified',
    'status_change_to_proposal',
    'days_since_last_contact',
];
const CRM_SEQ_ACTIONS = ['send_template','create_task','add_tag','remove_tag'];

function crm_listSequences(bool $onlyActive = false): array {
    $sql = 'SELECT * FROM sequences ' . ($onlyActive ? 'WHERE active = TRUE ' : '') . 'ORDER BY name ASC';
    return crm_db()->query($sql)->fetchAll();
}

function crm_getSequence(int $id): ?array {
    $stmt = crm_db()->prepare('SELECT * FROM sequences WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $st = crm_db()->prepare('SELECT * FROM sequence_steps WHERE sequence_id = ? ORDER BY step_order ASC');
    $st->execute([$id]);
    $row['steps'] = $st->fetchAll();
    return $row;
}

function crm_saveSequence(int $id, array $data, ?int $userId): int {
    $name    = mb_substr(trim((string)($data['name']    ?? '')), 0, 120);
    $trigger = (string)($data['trigger_event'] ?? '');
    $value   = (string)($data['trigger_value'] ?? '');
    $active  = !empty($data['active']);
    if ($name === '' || !in_array($trigger, CRM_SEQ_TRIGGERS, true)) return 0;

    if ($id > 0) {
        $stmt = crm_db()->prepare('UPDATE sequences SET name=?, trigger_event=?, trigger_value=?, active=? WHERE id=?');
        $stmt->execute([$name, $trigger, $value, $active?1:0, $id]);
        return $id;
    }
    $stmt = crm_db()->prepare(
        'INSERT INTO sequences (name, trigger_event, trigger_value, active, created_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, $trigger, $value, $active?1:0, $userId]);
    return (int) crm_db()->lastInsertId();
}

function crm_replaceSequenceSteps(int $sequenceId, array $steps): void {
    $db = crm_db();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM sequence_steps WHERE sequence_id = ?')->execute([$sequenceId]);
        $ins = $db->prepare(
            'INSERT INTO sequence_steps (sequence_id, step_order, delay_days, action, payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($steps as $s) {
            if (empty($s['action']) || !in_array($s['action'], CRM_SEQ_ACTIONS, true)) continue;
            $delay = max(0, (int)($s['delay_days'] ?? 0));
            $payload = is_array($s['payload'] ?? null) ? json_encode($s['payload']) : (string)($s['payload'] ?? '{}');
            $ins->execute([$sequenceId, ++$order, $delay, $s['action'], $payload]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); error_log('[crm_replaceSequenceSteps] ' . $e->getMessage()); }
}

function crm_enrollLeadInSequence(int $sequenceId, int $leadId): ?int {
    try {
        $stmt = crm_db()->prepare(
            'INSERT IGNORE INTO sequence_enrollments (sequence_id, lead_id, current_step, next_run_at)
             VALUES (?, ?, 0, NOW())'
        );
        $stmt->execute([$sequenceId, $leadId]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) { return null; }
}

function crm_unenrollLead(int $leadId, string $reason): int {
    try {
        $stmt = crm_db()->prepare(
            'UPDATE sequence_enrollments
             SET completed_at = NOW(), unenrolled_reason = ?
             WHERE lead_id = ? AND completed_at IS NULL'
        );
        $stmt->execute([$reason, $leadId]);
        return $stmt->rowCount();
    } catch (Throwable $e) { return 0; }
}

function crm_listEnrollmentsForLead(int $leadId): array {
    $stmt = crm_db()->prepare(
        'SELECT e.*, s.name AS sequence_name, s.active AS sequence_active
         FROM sequence_enrollments e JOIN sequences s ON s.id = e.sequence_id
         WHERE e.lead_id = ? ORDER BY e.created_at DESC'
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll();
}

// Trigger dispatcher: called from leads.php when status changes (or from cron).
// Auto-enrolls matching active sequences.
function crm_dispatchSequenceTrigger(string $event, int $leadId): void {
    try {
        $stmt = crm_db()->prepare(
            'SELECT id FROM sequences WHERE active = TRUE AND trigger_event = ?'
        );
        $stmt->execute([$event]);
        foreach ($stmt->fetchAll() as $row) {
            crm_enrollLeadInSequence((int)$row['id'], $leadId);
        }
    } catch (Throwable $e) { error_log('[crm_dispatchSequenceTrigger] ' . $e->getMessage()); }
}
