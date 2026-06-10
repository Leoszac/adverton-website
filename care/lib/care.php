<?php
// Adverton Care — shared bootstrap for the standalone telephony product.
// Reuses the CRM's DB connection + config loader (crm_db / crm_config) but
// keeps Care's own tables (care_*), logging and helpers isolated. Care files
// live in public_html/care/ ; the CRM lib is a sibling at public_html/crm/lib/.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);

require_once __DIR__ . '/../../crm/lib/db.php';        // crm_config(), crm_db(), crm_h()
require_once __DIR__ . '/../../crm/lib/phone_normalize.php'; // crm_phoneToE164()

const CARE_LOG = '/home/advertonnet/logs/care.log';

function care_log(string $line): void {
    @file_put_contents(CARE_LOG, gmdate('Y-m-d\TH:i:s\Z') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function care_db(): PDO { return crm_db(); }

// E.164 normalize with a safe fallback (digits → +1XXXXXXXXXX for US).
function care_e164(string $raw): ?string {
    if (function_exists('crm_phoneToE164')) {
        $n = crm_phoneToE164($raw);
        if ($n) return $n;
    }
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 10) return '+1' . $d;
    if (strlen($d) === 11 && $d[0] === '1') return '+' . $d;
    return $d ? '+' . $d : null;
}

// Has this number opted out (replied STOP)? Global suppression.
function care_isOptedOut(string $phoneE164): bool {
    try {
        $st = care_db()->prepare('SELECT 1 FROM care_optouts WHERE phone = ? LIMIT 1');
        $st->execute([$phoneE164]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
