<?php
// Adverton Care — Twilio client (SMS + number provisioning + webhook auth).
// Mirrors crm/lib/instantly.php. Runs in STUB MODE when credentials are not
// configured: it logs what it WOULD do and returns a fake success, so the
// entire product is buildable and testable before the Twilio account exists.
// When TWILIO_ACCOUNT_SID + TWILIO_AUTH_TOKEN are set in config, it goes live
// with no code change.
//
// PHP 7.4-safe (no match/nullsafe/str_contains) — may be loaded by CLI cron.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/care.php';

const CARE_TWILIO_BASE = 'https://api.twilio.com/2010-04-01';

function care_twilioSid(): ?string   { $v = crm_config('TWILIO_ACCOUNT_SID'); return $v ? trim($v) : null; }
function care_twilioToken(): ?string { $v = crm_config('TWILIO_AUTH_TOKEN');  return $v ? trim($v) : null; }
function care_twilioConfigured(): bool { return care_twilioSid() && care_twilioToken(); }
function care_twilioStub(): bool { return !care_twilioConfigured(); }

// Generic Twilio REST call (Basic auth SID:token, form-encoded body, JSON out).
function care_twilioRequest(string $method, string $path, array $params = []): array {
    $sid = care_twilioSid(); $token = care_twilioToken();
    if (!$sid || !$token) {
        return ['ok'=>false, 'data'=>null, 'error'=>'Twilio not configured', 'http'=>0, 'stub'=>true];
    }
    $url = CARE_TWILIO_BASE . '/Accounts/' . rawurlencode($sid) . '/' . ltrim($path, '/');
    $method = strtoupper($method);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($method === 'GET') {
        if ($params) $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($params) $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok'=>false, 'data'=>null, 'error'=>"curl: {$err}", 'http'=>$http];
    $data = json_decode((string)$resp, true);
    if ($http >= 400) {
        $msg = $data['message'] ?? "Twilio HTTP {$http}";
        return ['ok'=>false, 'data'=>$data, 'error'=>$msg, 'http'=>$http];
    }
    return ['ok'=>true, 'data'=>$data, 'error'=>'', 'http'=>$http];
}

// Send an SMS. $to/$from in E.164. STUB: logs + returns a fake SID.
function care_twilioSendSms(string $to, string $from, string $body): array {
    if (care_twilioStub()) {
        care_log('STUB sendSms from=' . $from . ' to=' . $to . ' body=' . str_replace("\n", ' ', $body));
        return ['ok'=>true, 'data'=>['sid'=>'SM-stub-' . substr(md5($to . $body . microtime()), 0, 16)], 'error'=>'', 'http'=>200, 'stub'=>true];
    }
    $r = care_twilioRequest('POST', 'Messages.json', ['To'=>$to, 'From'=>$from, 'Body'=>$body]);
    care_log(($r['ok'] ? 'sentSms' : 'sendSms FAIL') . ' from=' . $from . ' to=' . $to . ($r['ok'] ? '' : ' err=' . $r['error']));
    return $r;
}

// Verify an inbound Twilio webhook signature: HMAC-SHA1 over (full URL + each
// POST key+value sorted by key), base64. The Auth Token is the signing key.
// Fail-closed: no token → reject.
function care_twilioVerifySignature(string $fullUrl, array $postParams, string $signature): bool {
    $token = care_twilioToken();
    if (!$token || $signature === '') return false;
    ksort($postParams);
    $data = $fullUrl;
    foreach ($postParams as $k => $v) { $data .= $k . $v; }
    $expected = base64_encode(hash_hmac('sha1', $data, $token, true));
    return hash_equals($expected, $signature);
}

// Buy a US local number (SMS + voice). STUB returns a fake number.
function care_twilioBuyNumber(?string $areaCode = null): array {
    if (care_twilioStub()) {
        $fake = '+1' . str_pad((string)(abs(crc32(uniqid('', true))) % 10000000000), 10, '0', STR_PAD_LEFT);
        care_log('STUB buyNumber areaCode=' . (string)$areaCode . ' -> ' . $fake);
        return ['ok'=>true, 'data'=>['phone_number'=>$fake, 'sid'=>'PN-stub-' . substr(md5($fake), 0, 16)], 'error'=>'', 'http'=>200, 'stub'=>true];
    }
    $q = ['SmsEnabled'=>'true', 'VoiceEnabled'=>'true', 'PageSize'=>1];
    if ($areaCode) $q['AreaCode'] = $areaCode;
    $avail = care_twilioRequest('GET', 'AvailablePhoneNumbers/US/Local.json', $q);
    if (!$avail['ok']) return $avail;
    $num = $avail['data']['available_phone_numbers'][0]['phone_number'] ?? null;
    if (!$num) return ['ok'=>false, 'data'=>null, 'error'=>'no number available for area code', 'http'=>0];
    return care_twilioRequest('POST', 'IncomingPhoneNumbers.json', ['PhoneNumber'=>$num]);
}

// Point a number's voice + SMS webhooks at Care.
function care_twilioSetWebhooks(string $numberSid, string $voiceUrl, string $smsUrl): array {
    if (care_twilioStub()) {
        care_log('STUB setWebhooks ' . $numberSid . ' voice=' . $voiceUrl . ' sms=' . $smsUrl);
        return ['ok'=>true, 'data'=>[], 'error'=>'', 'http'=>200, 'stub'=>true];
    }
    return care_twilioRequest('POST', 'IncomingPhoneNumbers/' . $numberSid . '.json', [
        'VoiceUrl'=>$voiceUrl, 'VoiceMethod'=>'POST', 'SmsUrl'=>$smsUrl, 'SmsMethod'=>'POST',
    ]);
}

function care_twilioTest(): array {
    if (care_twilioStub()) {
        return ['ok'=>true, 'message'=>'STUB mode (no Twilio creds) — logic testable, no live sends', 'stub'=>true];
    }
    $r = care_twilioRequest('GET', 'Account.json');
    return $r['ok']
        ? ['ok'=>true, 'message'=>'Connected: ' . ($r['data']['friendly_name'] ?? '?')]
        : ['ok'=>false, 'message'=>$r['error']];
}
