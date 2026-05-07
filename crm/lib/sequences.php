<?php
// Drip sequences — auto-enroll leads on triggers, run steps with delays,
// auto-unenroll on reply / status change to won/lost / DNC tag.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_SEQ_TRIGGERS = [
    'lead_created',
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

// Internal: replace steps without owning a transaction. Caller is expected to
// have started one (used by crm_saveSequenceWithSteps below).
function crm_replaceSequenceStepsInner(int $sequenceId, array $steps): void {
    $db = crm_db();
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
}

// Public wrapper kept for backward-compat with any standalone caller.
function crm_replaceSequenceSteps(int $sequenceId, array $steps): void {
    $db = crm_db();
    $db->beginTransaction();
    try {
        crm_replaceSequenceStepsInner($sequenceId, $steps);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[crm_replaceSequenceSteps] ' . $e->getMessage());
    }
}

// Atomic save: header + steps committed together. Returns the (new or existing)
// sequence id, or 0 on failure. If $steps is null the steps are left untouched.
function crm_saveSequenceWithSteps(int $id, array $data, ?array $steps, ?int $userId): int {
    $db = crm_db();
    $db->beginTransaction();
    try {
        $newId = crm_saveSequence($id, $data, $userId);
        if ($newId <= 0) { $db->rollBack(); return 0; }
        if ($steps !== null) crm_replaceSequenceStepsInner($newId, $steps);
        $db->commit();
        return $newId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[crm_saveSequenceWithSteps] ' . $e->getMessage());
        return 0;
    }
}

function crm_enrollLeadInSequence(int $sequenceId, int $leadId): ?int {
    try {
        // Honor the first step's delay_days. Without this the first step always
        // ran immediately at enroll-time, ignoring its configured delay.
        $stmt = crm_db()->prepare(
            'SELECT delay_days FROM sequence_steps WHERE sequence_id = ? AND step_order = 1 LIMIT 1'
        );
        $stmt->execute([$sequenceId]);
        $row = $stmt->fetch();
        $delayDays = $row ? max(0, (int)$row['delay_days']) : 0;
        $nextRun = date('Y-m-d H:i:s', strtotime("+{$delayDays} days"));

        $stmt = crm_db()->prepare(
            'INSERT IGNORE INTO sequence_enrollments (sequence_id, lead_id, current_step, next_run_at)
             VALUES (?, ?, 0, ?)'
        );
        $stmt->execute([$sequenceId, $leadId, $nextRun]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) { return null; }
}

// Aggregate counts for one sequence — used to power the stats card in the UI.
function crm_getSequenceStats(int $sequenceId): array {
    $empty = ['total'=>0,'active'=>0,'completed'=>0,'unenrolled'=>0,'completion_rate'=>0.0];
    try {
        $stmt = crm_db()->prepare(
            'SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) AS active,
               SUM(CASE WHEN unenrolled_reason = "completed" THEN 1 ELSE 0 END) AS completed,
               SUM(CASE WHEN completed_at IS NOT NULL AND unenrolled_reason != "completed" THEN 1 ELSE 0 END) AS unenrolled
             FROM sequence_enrollments WHERE sequence_id = ?'
        );
        $stmt->execute([$sequenceId]);
        $r = $stmt->fetch();
        if (!$r) return $empty;
        $total      = (int)$r['total'];
        $completed  = (int)$r['completed'];
        $unenrolled = (int)$r['unenrolled'];
        $finished   = $completed + $unenrolled;
        return [
            'total'           => $total,
            'active'          => (int)$r['active'],
            'completed'       => $completed,
            'unenrolled'      => $unenrolled,
            'completion_rate' => $finished > 0 ? round($completed * 100.0 / $finished, 1) : 0.0,
        ];
    } catch (Throwable $e) { return $empty; }
}

// List enrollments for a sequence, optionally filtered by lifecycle state.
// $statusFilter: null|'active'|'completed'|'unenrolled'
function crm_listEnrollmentsForSequence(int $sequenceId, ?string $statusFilter = null, int $limit = 100): array {
    try {
        $where = ['e.sequence_id = ?'];
        $args  = [$sequenceId];
        if ($statusFilter === 'active') {
            $where[] = 'e.completed_at IS NULL';
        } elseif ($statusFilter === 'completed') {
            $where[] = 'e.unenrolled_reason = "completed"';
        } elseif ($statusFilter === 'unenrolled') {
            $where[] = 'e.completed_at IS NOT NULL AND e.unenrolled_reason != "completed"';
        }
        $sql = 'SELECT e.*, l.first_name, l.last_name, l.business_name, l.email, l.status AS lead_status
                FROM sequence_enrollments e JOIN leads l ON l.id = e.lead_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY COALESCE(e.completed_at, e.next_run_at) DESC
                LIMIT ' . max(1, min(500, (int)$limit));
        $stmt = crm_db()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
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
        'SELECT e.*, s.name AS sequence_name, s.active AS sequence_active,
                (SELECT COUNT(*) FROM sequence_steps ss WHERE ss.sequence_id = e.sequence_id) AS total_steps
         FROM sequence_enrollments e JOIN sequences s ON s.id = e.sequence_id
         WHERE e.lead_id = ? ORDER BY e.created_at DESC'
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll();
}

function crm_deleteSequence(int $sequenceId): bool {
    try {
        $stmt = crm_db()->prepare('DELETE FROM sequences WHERE id = ?');
        return $stmt->execute([$sequenceId]);
    } catch (Throwable $e) { error_log('[crm_deleteSequence] ' . $e->getMessage()); return false; }
}

// Clone an existing sequence (with its steps) under a new name. Created copy
// is INACTIVE so it can be edited safely before turning it on. Atomic via
// crm_saveSequenceWithSteps.
function crm_duplicateSequence(int $sourceId, ?int $userId): ?int {
    $src = crm_getSequence($sourceId);
    if (!$src) return null;
    $steps = [];
    foreach ($src['steps'] as $st) {
        $steps[] = [
            'delay_days' => (int)$st['delay_days'],
            'action'     => (string)$st['action'],
            'payload'    => json_decode((string)$st['payload'], true) ?: [],
        ];
    }
    $newId = crm_saveSequenceWithSteps(0, [
        'name'          => mb_substr('Copy of ' . $src['name'], 0, 120),
        'trigger_event' => $src['trigger_event'],
        'trigger_value' => $src['trigger_value'] ?? '',
        'active'        => false,
    ], $steps, $userId);
    return $newId > 0 ? $newId : null;
}

// Unenroll one specific enrollment row (single sequence × lead pair) — used
// when the operator clicks "Unenroll" on a lead's sequence card.
function crm_unenrollEnrollment(int $enrollmentId, string $reason = 'manual'): bool {
    try {
        $stmt = crm_db()->prepare(
            'UPDATE sequence_enrollments
             SET completed_at = NOW(), unenrolled_reason = ?
             WHERE id = ? AND completed_at IS NULL'
        );
        $stmt->execute([$reason, $enrollmentId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

// Trigger dispatcher: called from leads.php when status changes (or from cron).
// Auto-enrolls matching active sequences. If $contextValue is provided, only
// sequences whose trigger_value is empty OR matches will enroll. This lets
// `lead_created` sequences filter by source (e.g. trigger_value='audit_auto'
// only enrolls audit leads, not ebook leads).
function crm_dispatchSequenceTrigger(string $event, int $leadId, ?string $contextValue = null): void {
    try {
        $stmt = crm_db()->prepare(
            'SELECT id, trigger_value FROM sequences WHERE active = TRUE AND trigger_event = ?'
        );
        $stmt->execute([$event]);
        foreach ($stmt->fetchAll() as $row) {
            $tv = trim((string)($row['trigger_value'] ?? ''));
            if ($tv !== '' && $contextValue !== null && $tv !== $contextValue) {
                continue; // sequence is scoped to a different value (e.g. different source)
            }
            crm_enrollLeadInSequence((int)$row['id'], $leadId);
        }
    } catch (Throwable $e) { error_log('[crm_dispatchSequenceTrigger] ' . $e->getMessage()); }
}
