<?php
// Commission tracking — Sales VA gets $20 per qualified demo + $500 per close
// (split 50% on signing / 50% on day-90 retention).

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_COMMISSION_TYPES = ['demo','close_signed','close_day90','clawback'];

function crm_recordCommission(int $userId, string $type, float $amount,
                              ?int $leadId, ?int $clientId, string $notes = ''): ?int {
    if (!in_array($type, CRM_COMMISSION_TYPES, true)) return null;
    if ($userId <= 0) return null;
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO commission_events (user_id, lead_id, client_id, type, amount, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $leadId, $clientId, $type, $amount, $notes ?: null]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[crm_recordCommission] ' . $e->getMessage());
        return null;
    }
}

// Insert only if no row of the same (user, lead, type) exists. Used to prevent
// double-counting when status flips qualified → contacted → qualified.
function crm_recordCommissionOnce(int $userId, string $type, float $amount,
                                  ?int $leadId, ?int $clientId, string $notes = ''): ?int {
    try {
        $stmt = crm_db()->prepare(
            'SELECT id FROM commission_events WHERE user_id = ? AND type = ? AND lead_id <=> ? AND client_id <=> ? LIMIT 1'
        );
        $stmt->execute([$userId, $type, $leadId, $clientId]);
        if ($stmt->fetch()) return null;
    } catch (Throwable $e) { /* fall through */ }
    return crm_recordCommission($userId, $type, $amount, $leadId, $clientId, $notes);
}

function crm_listCommissions(int $userId, ?string $sinceDate = null): array {
    $w = ['user_id = ?']; $p = [$userId];
    if ($sinceDate) { $w[] = 'created_at >= ?'; $p[] = $sinceDate; }
    $stmt = crm_db()->prepare(
        'SELECT * FROM commission_events WHERE ' . implode(' AND ', $w) . ' ORDER BY created_at DESC'
    );
    $stmt->execute($p);
    return $stmt->fetchAll();
}

function crm_summarizeCommissions(int $userId, string $period = 'month'): array {
    $sql = "SELECT type, SUM(amount) AS total, COUNT(*) AS n
            FROM commission_events
            WHERE user_id = ?
              AND created_at >= " .
              ($period === 'month' ? 'DATE_SUB(NOW(), INTERVAL 30 DAY)'
                                   : 'DATE_SUB(NOW(), INTERVAL 7 DAY)') . "
            GROUP BY type";
    $stmt = crm_db()->prepare($sql);
    $stmt->execute([$userId]);
    $out = ['demo' => 0, 'close_signed' => 0, 'close_day90' => 0, 'clawback' => 0,
            'total' => 0, 'count' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['type']] = (float)$r['total'];
        $out['total'] += (float)$r['total'];
        $out['count'] += (int)$r['n'];
    }
    return $out;
}

// Cron-friendly: process clients past their day-90 mark and credit close_day90
// to the original sales person. Idempotent.
function crm_creditDay90Closes(): int {
    $credited = 0;
    try {
        $stmt = crm_db()->query(
            "SELECT c.id, c.lead_id, c.account_manager_id, c.contract_start_at, c.status
             FROM clients c
             WHERE c.contract_start_at <= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               AND c.status IN ('active','onboarding','renewed','past_due')"
        );
        foreach ($stmt->fetchAll() as $c) {
            if (!$c['account_manager_id']) continue;
            $created = crm_recordCommissionOnce(
                (int)$c['account_manager_id'],
                'close_day90', 250.00,
                $c['lead_id'] ? (int)$c['lead_id'] : null,
                (int)$c['id'],
                'Day-90 retention close'
            );
            if ($created) $credited++;
        }
    } catch (Throwable $e) { error_log('[crm_creditDay90Closes] ' . $e->getMessage()); }
    return $credited;
}

// Clawback when a client cancels within day-90: subtract close_day90 if it
// was already paid, and the close_signed half if it wasn't paid yet.
function crm_clawbackOnCancel(int $clientId, ?int $leadId, int $userId): void {
    if ($userId <= 0) return;
    try {
        $stmt = crm_db()->prepare(
            "SELECT contract_start_at FROM clients WHERE id = ?"
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();
        if (!$row) return;
        $daysActive = (int)((time() - strtotime((string)$row['contract_start_at'])) / 86400);
        if ($daysActive >= 90) return; // no clawback after day 90

        crm_recordCommission($userId, 'clawback', -250.00, $leadId, $clientId,
            "Clawback: cancelled at day {$daysActive}");
    } catch (Throwable $e) { error_log('[crm_clawbackOnCancel] ' . $e->getMessage()); }
}
