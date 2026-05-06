<?php
// TOTP (RFC 6238) — pure PHP, no external lib.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

const CRM_TOTP_PERIOD = 30;
const CRM_TOTP_DIGITS = 6;

// Generate a fresh base32 secret (16 chars = 80 bits, plenty)
function crm_totpGenerateSecret(): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $alphabet[ord(random_bytes(1)) % 32];
    }
    return $out;
}

function crm_totpDecodeBase32(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $idx = strpos($alphabet, $b32[$i]);
        if ($idx === false) continue;
        $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function crm_totpAt(string $secret, int $time): string {
    $counter = intdiv($time, CRM_TOTP_PERIOD);
    $key = crm_totpDecodeBase32($secret);
    // 8-byte big-endian counter
    $msg = pack('J', $counter);
    $hmac = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hmac[19]) & 0x0F;
    $bin = ((ord($hmac[$offset]) & 0x7F) << 24)
         | ((ord($hmac[$offset+1]) & 0xFF) << 16)
         | ((ord($hmac[$offset+2]) & 0xFF) << 8)
         |  (ord($hmac[$offset+3]) & 0xFF);
    $code = $bin % (10 ** CRM_TOTP_DIGITS);
    return str_pad((string)$code, CRM_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

// Verify with ±1 step tolerance for clock skew
function crm_totpVerify(string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== CRM_TOTP_DIGITS) return false;
    $now = time();
    foreach ([-1, 0, 1] as $offset) {
        if (hash_equals(crm_totpAt($secret, $now + $offset * CRM_TOTP_PERIOD), $code)) return true;
    }
    return false;
}

function crm_totpProvisioningUri(string $accountName, string $secret, string $issuer = 'Adverton CRM'): string {
    $label = rawurlencode("{$issuer}:{$accountName}");
    $params = http_build_query([
        'secret'    => $secret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => CRM_TOTP_DIGITS,
        'period'    => CRM_TOTP_PERIOD,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}

// Use Google Charts API for the QR (server-side rendering, no lib).
function crm_totpQrUrl(string $otpauthUri): string {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($otpauthUri);
}
