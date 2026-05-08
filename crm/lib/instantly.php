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
