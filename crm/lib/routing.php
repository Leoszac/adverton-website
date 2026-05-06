<?php
// Lead routing rules — auto-assign owner_user_id based on trade/source/state.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

function crm_listRoutingRules(bool $onlyActive = false): array {
    $sql = 'SELECT r.*, u.display_name AS assignee_name FROM routing_rules r
            LEFT JOIN users u ON u.id = r.assign_to '
         . ($onlyActive ? 'WHERE r.active = TRUE ' : '')
         . 'ORDER BY r.priority ASC, r.id ASC';
    return crm_db()->query($sql)->fetchAll();
}

function crm_getRoutingRule(int $id): ?array {
    $stmt = crm_db()->prepare('SELECT * FROM routing_rules WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function crm_saveRoutingRule(int $id, array $data): int {
    $assign = (int)($data['assign_to'] ?? 0);
    if ($assign <= 0) return 0;
    $row = [
        'priority'     => max(0, (int)($data['priority'] ?? 100)),
        'match_trade'  => $data['match_trade']  ?: null,
        'match_source' => $data['match_source'] ?: null,
        'match_state'  => $data['match_state']  ?: null,
        'match_temp'   => $data['match_temp']   ?: null,
        'assign_to'    => $assign,
        'active'       => !empty($data['active']) ? 1 : 0,
    ];
    if ($id > 0) {
        $stmt = crm_db()->prepare(
            'UPDATE routing_rules SET priority=?, match_trade=?, match_source=?, match_state=?, match_temp=?, assign_to=?, active=? WHERE id=?'
        );
        $stmt->execute([$row['priority'],$row['match_trade'],$row['match_source'],$row['match_state'],$row['match_temp'],$row['assign_to'],$row['active'],$id]);
        return $id;
    }
    $stmt = crm_db()->prepare(
        'INSERT INTO routing_rules (priority, match_trade, match_source, match_state, match_temp, assign_to, active)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$row['priority'],$row['match_trade'],$row['match_source'],$row['match_state'],$row['match_temp'],$row['assign_to'],$row['active']]);
    return (int) crm_db()->lastInsertId();
}

function crm_deleteRoutingRule(int $id): bool {
    $stmt = crm_db()->prepare('DELETE FROM routing_rules WHERE id = ?');
    return $stmt->execute([$id]);
}

// Resolve a lead's owner from routing rules. First match by priority wins.
// Returns user_id or null.
function crm_resolveOwner(array $leadData): ?int {
    try {
        $rules = crm_listRoutingRules(true);
        foreach ($rules as $r) {
            if (!empty($r['match_trade'])  && strcasecmp((string)($leadData['trade']  ?? ''), (string)$r['match_trade'])  !== 0)  continue;
            if (!empty($r['match_source']) && strcasecmp((string)($leadData['source'] ?? ''), (string)$r['match_source']) !== 0) continue;
            if (!empty($r['match_temp'])   && strcasecmp((string)($leadData['temperature'] ?? ''), (string)$r['match_temp']) !== 0) continue;
            if (!empty($r['match_state'])) {
                // city_state column is "City, ST". Match suffix after comma.
                $cs = trim((string)($leadData['city_state'] ?? ''));
                $st = '';
                if (preg_match('/,\s*([A-Z]{2})\b/i', $cs, $m)) $st = strtoupper($m[1]);
                if (strcasecmp($st, (string)$r['match_state']) !== 0) continue;
            }
            return (int)$r['assign_to'];
        }
    } catch (Throwable $e) { error_log('[crm_resolveOwner] ' . $e->getMessage()); }
    return null;
}
