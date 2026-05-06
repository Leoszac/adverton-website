<?php
// Active client (post-won subscription) — separate from leads.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_CLIENT_STATUSES = ['onboarding','active','past_due','paused','cancelled','renewed'];
const CRM_PAYMENT_STATUSES = ['current','past_due','failed','cancelled'];

// Promote a won lead into a client. Idempotent: if a client already exists
// for this lead_id, returns its id without creating a duplicate.
function crm_promoteLeadToClient(int $leadId, ?int $actorUserId = null): ?int {
    require_once __DIR__ . '/leads.php';
    $lead = crm_getLead($leadId);
    if (!$lead) return null;

    try {
        $stmt = crm_db()->prepare('SELECT id FROM clients WHERE lead_id = ? LIMIT 1');
        $stmt->execute([$leadId]);
        if ($row = $stmt->fetch()) return (int)$row['id'];

        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime('+12 months'));

        $stmt = crm_db()->prepare(
            'INSERT INTO clients (lead_id, business_name, trade, primary_email, primary_phone,
                                  contract_start_at, contract_end_at,
                                  monthly_fee, ad_budget, mgmt_fee_pct,
                                  account_manager_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leadId,
            $lead['business_name'],
            $lead['trade'],
            $lead['email'],
            $lead['phone'],
            $start, $end,
            $lead['monthly_fee'] !== null ? (float)$lead['monthly_fee'] : 799.00,
            $lead['ad_budget'],
            $lead['mgmt_fee_pct'] !== null ? (float)$lead['mgmt_fee_pct'] : 0,
            $lead['owner_user_id'] ?? $actorUserId,
        ]);
        $clientId = (int) crm_db()->lastInsertId();

        crm_logClientEvent($clientId, $actorUserId, 'status_change', 'Created from lead #' . $leadId);
        return $clientId;
    } catch (Throwable $e) {
        error_log('[crm_promoteLeadToClient] ' . $e->getMessage());
        return null;
    }
}

function crm_getClient(int $id): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['addons'])) {
            $row['addons_decoded'] = json_decode((string)$row['addons'], true) ?: [];
        } else if ($row) {
            $row['addons_decoded'] = [];
        }
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function crm_getClientByLead(int $leadId): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM clients WHERE lead_id = ? LIMIT 1');
        $stmt->execute([$leadId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function crm_getClientByStripeSub(string $subId): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM clients WHERE stripe_subscription_id = ? LIMIT 1');
        $stmt->execute([$subId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function crm_listClients(array $filters = [], int $limit = 100, int $offset = 0): array {
    $w = []; $p = [];
    if (!empty($filters['status']) && in_array($filters['status'], CRM_CLIENT_STATUSES, true)) {
        $w[] = 'status = ?'; $p[] = $filters['status'];
    }
    if (!empty($filters['payment_status']) && in_array($filters['payment_status'], CRM_PAYMENT_STATUSES, true)) {
        $w[] = 'payment_status = ?'; $p[] = $filters['payment_status'];
    }
    if (!empty($filters['account_manager_id'])) {
        $w[] = 'account_manager_id = ?'; $p[] = (int)$filters['account_manager_id'];
    }
    if (!empty($filters['q'])) {
        $like = '%' . addcslashes((string)$filters['q'], '%_\\') . '%';
        $w[] = '(business_name LIKE ? OR primary_email LIKE ? OR primary_phone LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    if (!empty($filters['expiring_within_days']) && (int)$filters['expiring_within_days'] > 0) {
        $w[] = 'contract_end_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';
        $p[] = (int)$filters['expiring_within_days'];
    }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $sql = "SELECT * FROM clients {$where} ORDER BY contract_end_at ASC LIMIT {$limit} OFFSET {$offset}";
    $stmt = crm_db()->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetchAll();
}

function crm_countClients(array $filters = []): int {
    $rows = crm_listClients($filters, 100000, 0);
    return count($rows);
}

function crm_clientMrr(array $client): float {
    $base   = (float)($client['monthly_fee'] ?? 0);
    $ad     = (float)($client['ad_budget'] ?? 0);
    $pct    = (float)($client['mgmt_fee_pct'] ?? 0);
    $addons = is_array($client['addons_decoded'] ?? null) ? $client['addons_decoded'] : [];
    $addonSum = 0;
    foreach ($addons as $a) {
        if (empty($a['ended_at']) || $a['ended_at'] > date('Y-m-d')) {
            $addonSum += (float)($a['price_monthly'] ?? 0);
        }
    }
    return $base + $addonSum + ($ad * $pct / 100.0);
}

function crm_updateClient(int $id, array $patch, ?int $actorUserId = null): bool {
    $allowed = [
        'business_name','trade','primary_email','primary_phone',
        'contract_start_at','contract_end_at',
        'monthly_fee','ad_budget','mgmt_fee_pct',
        'status','payment_status','installment_count','renewal_count',
        'buyout_eligible','cancellation_reason','cancellation_note',
        'account_manager_id','stripe_customer_id','stripe_subscription_id',
        'pandadoc_doc_id','health_score','notes','addons',
    ];
    $current = crm_getClient($id);
    if (!$current) return false;

    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $patch)) continue;
        $v = $patch[$k];
        if ($k === 'status'         && !in_array($v, CRM_CLIENT_STATUSES, true)) continue;
        if ($k === 'payment_status' && !in_array($v, CRM_PAYMENT_STATUSES, true)) continue;
        if (in_array($k, ['monthly_fee','ad_budget','mgmt_fee_pct'], true)) {
            $v = ($v === '' || $v === null) ? null : (float)$v;
        }
        if (in_array($k, ['installment_count','renewal_count','health_score','account_manager_id'], true)) {
            $v = ($v === '' || $v === null) ? null : (int)$v;
        }
        if ($k === 'addons' && is_array($v)) $v = json_encode(array_values($v));
        $sets[] = "{$k} = ?";
        $params[] = $v;
    }
    if (!$sets) return false;
    $params[] = $id;
    $stmt = crm_db()->prepare('UPDATE clients SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $ok = $stmt->execute($params);

    if ($ok && isset($patch['status']) && $patch['status'] !== $current['status']) {
        crm_logClientEvent($id, $actorUserId, 'status_change',
            $current['status'] . ' → ' . $patch['status']);
    }
    return $ok;
}

function crm_logClientEvent(int $clientId, ?int $userId, string $type, ?string $body, ?array $meta = null): ?int {
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO client_events (client_id, user_id, type, body, meta) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $clientId, $userId, $type,
            $body !== '' ? $body : null,
            $meta ? json_encode($meta) : null,
        ]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[crm_logClientEvent] ' . $e->getMessage());
        return null;
    }
}

function crm_listClientEvents(int $clientId, int $limit = 200): array {
    $stmt = crm_db()->prepare(
        'SELECT e.*, u.display_name AS user_name
         FROM client_events e LEFT JOIN users u ON u.id = e.user_id
         WHERE e.client_id = ? ORDER BY e.created_at DESC LIMIT ' . $limit
    );
    $stmt->execute([$clientId]);
    return $stmt->fetchAll();
}

function crm_addAddonToClient(int $clientId, string $code, float $monthlyPrice, ?int $actorUserId): bool {
    $client = crm_getClient($clientId);
    if (!$client) return false;
    $addons = $client['addons_decoded'] ?? [];
    foreach ($addons as $a) {
        if (($a['code'] ?? '') === $code && empty($a['ended_at'])) return false; // already active
    }
    $addons[] = ['code' => $code, 'price_monthly' => $monthlyPrice, 'started_at' => date('Y-m-d')];
    $ok = crm_updateClient($clientId, ['addons' => $addons], $actorUserId);
    if ($ok) crm_logClientEvent($clientId, $actorUserId, 'addon_added', "{$code} (+\${$monthlyPrice}/mo)");
    return $ok;
}

function crm_removeAddonFromClient(int $clientId, string $code, ?int $actorUserId): bool {
    $client = crm_getClient($clientId);
    if (!$client) return false;
    $addons = $client['addons_decoded'] ?? [];
    $changed = false;
    foreach ($addons as &$a) {
        if (($a['code'] ?? '') === $code && empty($a['ended_at'])) {
            $a['ended_at'] = date('Y-m-d');
            $changed = true;
        }
    }
    if (!$changed) return false;
    $ok = crm_updateClient($clientId, ['addons' => $addons], $actorUserId);
    if ($ok) crm_logClientEvent($clientId, $actorUserId, 'addon_removed', $code);
    return $ok;
}

function crm_buyoutAmount(array $client): float {
    $paid = (int)($client['installment_count'] ?? 0);
    $total = 9588.00;
    return max(0.0, $total - ($paid * 799.00));
}

function crm_isClientAtRisk(array $client): bool {
    if ($client['payment_status'] === 'past_due' || $client['payment_status'] === 'failed') return true;
    if ($client['status'] === 'paused') return true;
    if (($client['health_score'] ?? 100) < 50) return true;
    return false;
}
