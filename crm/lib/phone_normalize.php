<?php
// E.164 phone normalization for US/CA numbers (NANP).
// Returns canonical "+1NXXNXXXXXX" or null if it can't parse a real
// 10-digit NANP number. Used by cold-prospects import + DNC scrub.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

// Normalize an arbitrary raw phone string to E.164. Accepts:
//   "(602) 555-1234"  → "+16025551234"
//   "602.555.1234"    → "+16025551234"
//   "+1 602 555 1234" → "+16025551234"
//   "6025551234"      → "+16025551234"
//   "16025551234"     → "+16025551234"
//   "+44 20 ..."      → null  (non-NANP, out of scope)
//   "555-CALL-NOW"    → null  (letters, NANP-invalid leading digit)
// Returns null for anything that isn't a clean 10-digit NANP number.
function crm_phoneToE164(?string $raw): ?string {
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;

    // Allow an optional leading + then strip everything non-digit.
    $hasPlus = (strpos($raw, '+') === 0);
    $digits  = preg_replace('/\D/', '', $raw);
    if ($digits === '' || $digits === null) return null;

    // If the caller wrote "+44..." or any other country code, this is
    // either out of NANP or needs special handling. We only target US/CA
    // for now — anything that explicitly says + and isn't +1 → reject.
    if ($hasPlus && substr($digits, 0, 1) !== '1') return null;

    // Strip a leading country code "1" if it's there.
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }

    // NANP numbers are exactly 10 digits at this point.
    if (strlen($digits) !== 10) return null;

    // NANP rules: area code and exchange must start 2–9 (no leading 0/1).
    $areaFirst     = (int) $digits[0];
    $exchangeFirst = (int) $digits[3];
    if ($areaFirst < 2 || $exchangeFirst < 2) return null;

    return '+1' . $digits;
}

// Pretty-print "(602) 555-1234" from a stored E.164 string. Falls back to
// the raw string for non-NANP / malformed values.
function crm_phoneFormatPretty(?string $e164): string {
    if (!$e164) return '—';
    if (preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m)) {
        return "({$m[1]}) {$m[2]}-{$m[3]}";
    }
    return $e164;
}
