<?php
// DNC scrub API client.
//
// Provider-agnostic wrapper. The DNC scrub vendor (DNCScrub.com / DNC.com /
// Contact Center Compliance / similar) is configured via two settings:
//   - DNCSCRUB_API_KEY  (set in /crm/integrations.php)
//   - DNCSCRUB_API_URL  (optional override; defaults to DNCScrub.com pattern)
//
// Stub mode: if DNCSCRUB_API_KEY is empty, returns 'clean' for every number
// and logs a warning. That lets the cold-calling pipeline ship + be tested
// before the founder finishes signup with the vendor. Once the key is set,
// real scrubs happen automatically.
//
// PUBLIC API:
//   crm_dncScrubBatch(array $phonesE164): array
//     → ['+15551234567' => ['status' => 'clean',           'meta' => ['raw' => ...]],
//        '+15559876543' => ['status' => 'blocked_federal', 'meta' => [...]],
//        ...]
//
// Statuses match the cold_prospects.dnc_status ENUM exactly:
//   clean, blocked_federal, blocked_state, blocked_wireless,
//   blocked_litigator, blocked_internal, scrub_error
//
// PHP 7.4 compatible — used from cron CLI which loads the 7.4 binary.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_DNC_BATCH_SIZE       = 500;   // numbers per HTTP call
const CRM_DNC_HTTP_TIMEOUT_SEC = 30;
const CRM_DNC_LOG_PATH         = '/home2/advertonnet/logs/dnc-scrub.log';

// Main entry point. Always returns one entry per input phone, even on error
// (status='scrub_error' for any phone we couldn't reach the vendor for).
// On stub mode (no API key), returns 'clean' for everything.
function crm_dncScrubBatch(array $phonesE164): array {
    $out = [];
    if (empty($phonesE164)) return $out;

    // De-dup the input + drop empties; we re-attach the original keys at the end.
    $unique = [];
    foreach ($phonesE164 as $p) {
        $p = (string) $p;
        if ($p !== '') $unique[$p] = true;
    }
    $unique = array_keys($unique);

    $apiKey = (string) crm_config('DNCSCRUB_API_KEY', '');
    if ($apiKey === '') {
        crm_dncLog('STUB mode (no DNCSCRUB_API_KEY): returning clean for ' . count($unique) . ' numbers');
        foreach ($unique as $p) {
            $out[$p] = ['status' => 'clean', 'meta' => ['stub' => true]];
        }
        return $out;
    }

    $apiUrl = (string) crm_config('DNCSCRUB_API_URL', 'https://api.dncscrub.com/v1/scrub');

    // Chunked batches
    $chunks = array_chunk($unique, CRM_DNC_BATCH_SIZE);
    foreach ($chunks as $chunk) {
        $batchOut = crm_dncCallApi($apiUrl, $apiKey, $chunk);
        foreach ($chunk as $p) {
            if (isset($batchOut[$p])) {
                $out[$p] = $batchOut[$p];
            } else {
                $out[$p] = ['status' => 'scrub_error', 'meta' => ['reason' => 'missing_in_response']];
            }
        }
    }

    return $out;
}

// One HTTP round-trip with one retry. Returns ['phone' => ['status'=>..., 'meta'=>...], ...]
// or an empty array on total failure (caller will fill 'scrub_error').
function crm_dncCallApi(string $url, string $apiKey, array $phones): array {
    $payload = json_encode([
        'phones' => array_values($phones),
        'sources' => ['federal', 'state', 'wireless', 'litigator'],
    ]);

    $attempt = 0;
    $lastErr = '';
    while ($attempt < 2) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => CRM_DNC_HTTP_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 500 || $code === 0) {
            $lastErr = "HTTP {$code} attempt {$attempt}: " . ($err ?: 'no body');
            crm_dncLog($lastErr);
            if ($attempt < 2) { sleep(2); continue; }
            return [];
        }
        if ($code >= 400) {
            // 4xx is the vendor saying we're wrong; don't retry.
            crm_dncLog("HTTP {$code}: " . substr((string)$body, 0, 500));
            return [];
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
            crm_dncLog("Bad JSON shape: " . substr((string)$body, 0, 500));
            if ($attempt < 2) { sleep(2); continue; }
            return [];
        }

        // Normalize vendor response into our canonical shape.
        $normalized = [];
        foreach ($json['results'] as $row) {
            if (!is_array($row)) continue;
            $phone = isset($row['phone']) ? (string)$row['phone'] : '';
            if ($phone === '') continue;
            $normalized[$phone] = [
                'status' => crm_dncMapStatus($row),
                'meta'   => $row,
            ];
        }
        crm_dncLog("scrubbed batch of " . count($phones) . " → " . count($normalized) . " results");
        return $normalized;
    }
    return [];
}

// Map a vendor result row to our cold_prospects.dnc_status ENUM.
// Most DNC APIs return either a structured `on_dnc` boolean + list of which
// lists matched (federal/state/wireless/litigator), or a single `status` field.
// We accept both shapes — first-match wins.
function crm_dncMapStatus(array $row): string {
    // Direct status field shape: {'status': 'on_dnc_federal'} or {'status': 'clean'}
    if (isset($row['status']) && is_string($row['status'])) {
        $s = strtolower((string)$row['status']);
        if (strpos($s, 'clean') !== false || strpos($s, 'ok') !== false || strpos($s, 'allowed') !== false) {
            return 'clean';
        }
        if (strpos($s, 'litigator') !== false) return 'blocked_litigator';
        if (strpos($s, 'wireless') !== false) return 'blocked_wireless';
        if (strpos($s, 'state')    !== false) return 'blocked_state';
        if (strpos($s, 'federal')  !== false) return 'blocked_federal';
        if (strpos($s, 'dnc')      !== false) return 'blocked_federal';
    }

    // List-membership shape: {'on_dnc': true, 'lists': ['federal','wireless']}
    if (!empty($row['on_dnc']) || !empty($row['blocked']) || !empty($row['on_dnc_list'])) {
        $lists = isset($row['lists']) && is_array($row['lists']) ? $row['lists'] : [];
        $lists = array_map('strtolower', array_map('strval', $lists));
        if (in_array('litigator', $lists, true)) return 'blocked_litigator';
        if (in_array('wireless', $lists, true))  return 'blocked_wireless';
        if (in_array('state', $lists, true))     return 'blocked_state';
        if (in_array('federal', $lists, true))   return 'blocked_federal';
        return 'blocked_federal';
    }

    // Boolean-style flags
    if (!empty($row['is_litigator'])) return 'blocked_litigator';
    if (!empty($row['is_wireless']) && !empty($row['on_dnc'])) return 'blocked_wireless';
    if (!empty($row['on_state_dnc'])) return 'blocked_state';
    if (!empty($row['on_federal_dnc'])) return 'blocked_federal';

    // No block signal in the row → assume clean.
    return 'clean';
}

function crm_dncLog(string $line): void {
    $dir = dirname(CRM_DNC_LOG_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(
        CRM_DNC_LOG_PATH,
        gmdate('Y-m-d\TH:i:s\Z') . ' ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// True if the API key is configured (real scrubs will happen). False = stub mode.
function crm_dncIsLive(): bool {
    return ((string) crm_config('DNCSCRUB_API_KEY', '')) !== '';
}
