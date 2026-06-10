<?php
// Adverton Care — agency provisioning: buy a number, wire its webhooks, store
// the client↔number mapping, issue the dashboard token. Stub-safe (fake number
// when Twilio isn't configured). Called from care/admin.php.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/reviews.php';   // pulls twilio + care + flows helpers

function care_provisionClient(int $clientId, string $forwardTo, ?string $areaCode = null): array {
    $fwd = care_e164($forwardTo);
    if (!$fwd) return ['ok'=>false, 'error'=>'Invalid forward-to number'];

    // Already provisioned? Reuse the existing number, just refresh forward-to.
    $existing = care_clientNumber($clientId);
    if ($existing) {
        try { care_db()->prepare('UPDATE care_numbers SET forward_to = ? WHERE client_id = ? AND twilio_number = ?')->execute([$fwd, $clientId, $existing]); }
        catch (Throwable $e) {}
        $token = care_issueToken($clientId);
        return ['ok'=>true, 'number'=>$existing, 'forward_to'=>$fwd, 'token'=>$token,
                'dashboard'=>CARE_BASE_URL . '/?t=' . $token, 'reused'=>true];
    }

    $buy = care_twilioBuyNumber($areaCode);
    if (!$buy['ok']) return ['ok'=>false, 'error'=>'Buy number: ' . $buy['error']];
    $number = (string)($buy['data']['phone_number'] ?? '');
    $sid    = (string)($buy['data']['sid'] ?? '');
    if ($number === '') return ['ok'=>false, 'error'=>'Twilio returned no number'];

    care_twilioSetWebhooks($sid, CARE_BASE_URL . '/voice.php', CARE_BASE_URL . '/sms.php');

    try {
        care_db()->prepare(
            'INSERT INTO care_numbers (client_id, twilio_number, twilio_sid, forward_to) VALUES (?, ?, ?, ?)'
        )->execute([$clientId, $number, ($sid ?: null), $fwd]);
    } catch (Throwable $e) { return ['ok'=>false, 'error'=>'Store: ' . $e->getMessage()]; }

    $token = care_issueToken($clientId);
    care_log("provisioned client={$clientId} number={$number} fwd={$fwd}" . (($buy['stub'] ?? false) ? ' [STUB]' : ''));
    return ['ok'=>true, 'number'=>$number, 'forward_to'=>$fwd, 'token'=>$token,
            'dashboard'=>CARE_BASE_URL . '/?t=' . $token, 'stub'=>($buy['stub'] ?? false)];
}
