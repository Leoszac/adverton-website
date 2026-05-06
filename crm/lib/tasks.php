<?php
// Tasks (callbacks, follow-ups). Tied to a lead optionally.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

function crm_createTask(array $t): ?int {
    if (empty($t['title']) || empty($t['due_at'])) return null;
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO tasks (lead_id, assigned_to, created_by, title, notes, due_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            !empty($t['lead_id'])     ? (int)$t['lead_id']     : null,
            !empty($t['assigned_to']) ? (int)$t['assigned_to'] : null,
            !empty($t['created_by'])  ? (int)$t['created_by']  : null,
            mb_substr((string)$t['title'], 0, 255),
            $t['notes'] ?? null,
            (string)$t['due_at'],
        ]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[crm_createTask] ' . $e->getMessage());
        return null;
    }
}

function crm_completeTask(int $taskId, int $userId): bool {
    $stmt = crm_db()->prepare(
        'UPDATE tasks SET done_at = NOW() WHERE id = ? AND done_at IS NULL'
    );
    return $stmt->execute([$taskId]);
}

function crm_uncompleteTask(int $taskId): bool {
    $stmt = crm_db()->prepare('UPDATE tasks SET done_at = NULL WHERE id = ?');
    return $stmt->execute([$taskId]);
}

function crm_deleteTask(int $taskId): bool {
    $stmt = crm_db()->prepare('DELETE FROM tasks WHERE id = ?');
    return $stmt->execute([$taskId]);
}

function crm_listTasksForLead(int $leadId): array {
    $stmt = crm_db()->prepare(
        'SELECT t.*, u.display_name AS assignee_name
         FROM tasks t
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE t.lead_id = ?
         ORDER BY done_at IS NOT NULL ASC, due_at ASC'
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll();
}

// Today / overdue dashboard. If $userId is null → all users.
function crm_listDueTasks(?int $userId, string $bucket): array {
    $params = [];
    $where  = ['t.done_at IS NULL'];
    if ($userId !== null) {
        $where[] = 't.assigned_to = ?';
        $params[] = $userId;
    }
    if ($bucket === 'overdue') {
        $where[] = 't.due_at < CURDATE()';
    } elseif ($bucket === 'today') {
        $where[] = 'DATE(t.due_at) = CURDATE()';
    } elseif ($bucket === 'upcoming') {
        $where[] = 't.due_at > DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
        $where[] = 't.due_at < DATE_ADD(CURDATE(), INTERVAL 8 DAY)';
    }
    $sql = 'SELECT t.*, u.display_name AS assignee_name,
                   l.first_name, l.last_name, l.business_name, l.phone, l.email
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assigned_to
            LEFT JOIN leads l ON l.id = t.lead_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY t.due_at ASC';
    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crm_countDueTasks(?int $userId): array {
    $params = [];
    $where = ['done_at IS NULL'];
    if ($userId !== null) {
        $where[] = 'assigned_to = ?';
        $params[] = $userId;
    }
    $sql = 'SELECT
              SUM(CASE WHEN due_at < CURDATE()             THEN 1 ELSE 0 END) AS overdue,
              SUM(CASE WHEN DATE(due_at) = CURDATE()       THEN 1 ELSE 0 END) AS today
            FROM tasks
            WHERE ' . implode(' AND ', $where);
    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'overdue' => (int)($row['overdue'] ?? 0),
        'today'   => (int)($row['today']   ?? 0),
    ];
}
