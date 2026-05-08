<?php
// Instantly API V2 client.
//
// API key managed in /crm/integrations.php (DB-backed). Format is base64-encoded
// "<workspace_id>:<token>" — used as Bearer in the Authorization header.
//
// Docs: https://developer.instantly.ai/api/v2/

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

const CRM_INSTANTLY_BASE = 'https://api.instantly.ai/api/v2';

function crm_instantlyApiKey(): ?string {
    $key = crm_config('INSTANTLY_API_KEY');
    return $key ? trim($key) : null;
}

/**
 * Generic API call. Returns ['ok'=>bool, 'data'=>array|null, 'error'=>string, 'http'=>int].
 *
 * @param string $method GET|POST|PATCH|DELETE
 * @param string $path   '/accounts', '/campaigns', etc. (no leading base)
 * @param array  $params Query string for GET; JSON body for POST/PATCH
 */
function crm_instantlyRequest(string $method, string $path, array $params = []): array {
    $key = crm_instantlyApiKey();
    if (!$key) {
        return ['ok'=>false, 'data'=>null, 'error'=>'INSTANTLY_API_KEY not configured', 'http'=>0];
    }

    $url = CRM_INSTANTLY_BASE . '/' . ltrim($path, '/');
    $method = strtoupper($method);

    $headers = [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ];

    if ($method === 'GET') {
        if ($params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER]    = $headers;
        if ($params) $opts[CURLOPT_POSTFIELDS] = json_encode($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok'=>false, 'data'=>null, 'error'=>"curl: {$err}", 'http'=>$http];
    }

    $data = json_decode((string)$resp, true);
    if ($http >= 400) {
        $msg = $data['message'] ?? $data['error'] ?? "Instantly HTTP {$http}";
        return ['ok'=>false, 'data'=>$data, 'error'=>$msg, 'http'=>$http];
    }

    return ['ok'=>true, 'data'=>$data, 'error'=>'', 'http'=>$http];
}

/**
 * List connected email accounts (mailboxes). Returns array of accounts.
 * Each item typically includes: email, status, warmup, daily limit, health score, etc.
 */
function crm_instantlyListAccounts(int $limit = 100): array {
    $r = crm_instantlyRequest('GET', '/accounts', ['limit' => $limit]);
    if (!$r['ok']) return ['error'=>$r['error'], 'http'=>$r['http'], 'items'=>[]];
    $items = $r['data']['items'] ?? $r['data'] ?? [];
    return ['error'=>'', 'http'=>$r['http'], 'items'=>$items];
}

/**
 * List campaigns.
 */
function crm_instantlyListCampaigns(int $limit = 50): array {
    $r = crm_instantlyRequest('GET', '/campaigns', ['limit' => $limit]);
    if (!$r['ok']) return ['error'=>$r['error'], 'http'=>$r['http'], 'items'=>[]];
    $items = $r['data']['items'] ?? $r['data'] ?? [];
    return ['error'=>'', 'http'=>$r['http'], 'items'=>$items];
}

/**
 * Add a lead to a campaign. Returns the API response.
 *
 * @param string $campaignId  Instantly campaign UUID
 * @param string $email       Lead email
 * @param array  $vars        Optional merge vars: first_name, last_name, company, etc.
 */
function crm_instantlyAddLead(string $campaignId, string $email, array $vars = []): array {
    $body = array_merge([
        'campaign'    => $campaignId,
        'email'       => $email,
    ], $vars);
    return crm_instantlyRequest('POST', '/leads', $body);
}

/**
 * Quick health check. Returns ['ok'=>bool, 'message'=>string, 'account_count'=>int].
 */
function crm_instantlyTestConnection(): array {
    $r = crm_instantlyListAccounts(1);
    if ($r['error']) {
        return ['ok'=>false, 'message'=>$r['error'], 'account_count'=>0];
    }
    return ['ok'=>true, 'message'=>'Connection OK', 'account_count'=>count($r['items'])];
}

// ===== Status code mappings (per Instantly V2 API) =====

function crm_instantlyStatusLabel($code): string {
    $map = [1 => 'active', 2 => 'paused', -1 => 'connection_error', -2 => 'soft_bounce_error', -3 => 'sending_error', 3 => 'connection_error'];
    return $map[(int)$code] ?? ('status_' . (string)$code);
}

function crm_instantlyWarmupStatusLabel($code): string {
    $map = [0 => 'paused', 1 => 'active', -1 => 'banned', -2 => 'reconnect_required'];
    return $map[(int)$code] ?? ('warmup_' . (string)$code);
}

/**
 * Snapshot all accounts into a flat array suitable for storage / display.
 * Returns: [['email','status','status_label','warmup_status','warmup_label','warmup_score','setup_pending'], ...]
 */
function crm_instantlyAccountsSnapshot(): array {
    $r = crm_instantlyListAccounts(100);
    if ($r['error']) return ['error' => $r['error'], 'items' => []];

    $out = [];
    foreach ($r['items'] as $a) {
        $out[] = [
            'email'          => $a['email'] ?? '',
            'status'         => (int)($a['status'] ?? 0),
            'status_label'   => crm_instantlyStatusLabel($a['status'] ?? 0),
            'warmup_status'  => (int)($a['warmup_status'] ?? 0),
            'warmup_label'   => crm_instantlyWarmupStatusLabel($a['warmup_status'] ?? 0),
            'warmup_score'   => (int)($a['stat_warmup_score'] ?? 0),
            'setup_pending'  => (bool)($a['setup_pending'] ?? false),
            'first_name'     => $a['first_name'] ?? '',
            'last_name'      => $a['last_name'] ?? '',
        ];
    }
    return ['error' => '', 'items' => $out];
}

/**
 * Persist snapshot in DB settings table (key=INSTANTLY_HEALTH_SNAPSHOT, value=JSON).
 * Called by cron-instantly-health.php every hour.
 */
function crm_instantlySaveHealthSnapshot(array $snapshot): bool {
    $payload = json_encode([
        'synced_at' => date('c'),
        'items'     => $snapshot['items'] ?? [],
        'error'     => $snapshot['error'] ?? '',
    ]);
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        return $stmt->execute(['INSTANTLY_HEALTH_SNAPSHOT', $payload]);
    } catch (Throwable $e) {
        error_log('[crm_instantlySaveHealthSnapshot] ' . $e->getMessage());
        return false;
    }
}

/**
 * Read latest health snapshot from DB. Returns ['synced_at', 'items', 'error', 'age_minutes'].
 */
function crm_instantlyLoadHealthSnapshot(): array {
    try {
        $stmt = crm_db()->prepare("SELECT `value`, updated_at FROM settings WHERE `key` = 'INSTANTLY_HEALTH_SNAPSHOT'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) return ['synced_at' => null, 'items' => [], 'error' => 'never synced', 'age_minutes' => null];
        $data = json_decode((string)$row['value'], true) ?: [];
        $age = $data['synced_at'] ? max(0, (int)round((time() - strtotime($data['synced_at'])) / 60)) : null;
        return [
            'synced_at'   => $data['synced_at'] ?? null,
            'items'       => $data['items'] ?? [],
            'error'       => $data['error'] ?? '',
            'age_minutes' => $age,
        ];
    } catch (Throwable $e) {
        return ['synced_at' => null, 'items' => [], 'error' => $e->getMessage(), 'age_minutes' => null];
    }
}
