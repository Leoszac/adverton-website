<?php
// Activity timeline — auto + manual entries on a lead.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_ACTIVITY_TYPES = ['note','call','email','sms','meeting','status_change','system'];

// Common dispositions per channel — keep small, opinionated.
const CRM_DISPOSITIONS = [
    'call' => ['spoke','no_answer','voicemail','wrong_number','busy','interested','not_interested','callback'],
    'email'=> ['sent','replied','bounced','opened','no_reply'],
    'sms'  => ['sent','replied','no_reply'],
    'meeting' => ['scheduled','held','no_show','cancelled'],
];

function crm_logActivity(int $leadId, ?int $userId, string $type, ?string $disposition, ?string $body): ?int {
    if (!in_array($type, CRM_ACTIVITY_TYPES, true)) return null;
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO lead_activities (lead_id, user_id, type, disposition, body) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leadId,
            $userId,
            $type,
            $disposition === '' ? null : $disposition,
            $body === '' ? null : $body,
        ]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[crm_logActivity] ' . $e->getMessage());
        return null;
    }
}

function crm_listActivities(int $leadId, int $limit = 200): array {
    $stmt = crm_db()->prepare(
        'SELECT a.*, u.display_name AS user_name
         FROM lead_activities a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.lead_id = ?
         ORDER BY a.created_at DESC
         LIMIT ' . $limit
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll();
}

function crm_lastActivityAt(int $leadId): ?string {
    $stmt = crm_db()->prepare(
        'SELECT MAX(created_at) AS last FROM lead_activities WHERE lead_id = ? AND type != "system"'
    );
    $stmt->execute([$leadId]);
    $row = $stmt->fetch();
    return $row && $row['last'] ? (string)$row['last'] : null;
}

function crm_activityLabel(string $type, ?string $disposition): string {
    $label = ucfirst($type);
    if ($type === 'status_change') $label = 'Status change';
    if ($disposition) $label .= ' — ' . str_replace('_', ' ', $disposition);
    return $label;
}

function crm_activityIcon(string $type): string {
    return [
        'call'          => '📞',
        'email'         => '✉️',
        'sms'           => '💬',
        'meeting'       => '📅',
        'note'          => '📝',
        'status_change' => '↪',
        'system'        => '⚙',
    ][$type] ?? '•';
}
