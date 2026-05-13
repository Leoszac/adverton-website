<?php
// Shared crontab installer logic. Used by:
//   - The CRM button at /crm/integrations.php → update.php case sync_crontab
//     (operator login, founder-only)
//   - The legacy /crm/_install-cron.php one-shot endpoint (token-gated,
//     same canonical list — kept for emergency recovery)
//
// Single source of truth for "what crons does Adverton run, on what schedule".

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

// Canonical crontab lines, schedules in ET wall-clock. The
// `CRON_TZ=America/New_York` header at the top of the crontab tells
// Vixie/Cronie to interpret these in ET regardless of server TZ.
const CRM_CANONICAL_CRONTAB = [
    '*/15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-sequences.php >> /home2/advertonnet/logs/cron-sequences.log 2>&1',
    '17 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-calendly.php >> /home2/advertonnet/logs/cron-calendly.log 2>&1',
    '5 7 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-client-triggers.php >> /home2/advertonnet/logs/cron-client-triggers.log 2>&1',
    '20 8 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-lost-reengagement.php >> /home2/advertonnet/logs/cron-lost-reengagement.log 2>&1',
    '0 6 * * 1 /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-health-score.php >> /home2/advertonnet/logs/cron-health-score.log 2>&1',
    '0 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-instantly-health.php >> /home2/advertonnet/logs/cron-instantly-health.log 2>&1',
    '*/15 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-watchdog.php >> /home2/advertonnet/logs/cron-watchdog.log 2>&1',
    '0 3 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-dnc-rescrub.php >> /home2/advertonnet/logs/cron-dnc-rescrub.log 2>&1',
];

const CRM_MANAGED_CRON_SCRIPTS = [
    'cron-sequences.php',
    'cron-calendly.php',
    'cron-client-triggers.php',
    'cron-lost-reengagement.php',
    'cron-health-score.php',
    'cron-instantly-health.php',
    'cron-watchdog.php',
    'cron-dnc-rescrub.php',
];

// Sync the user's crontab to the canonical set. Preserves any unrelated
// lines, drops + re-adds CRON_TZ + managed scripts. Returns:
//   ['ok' => bool, 'preserved' => int, 'dropped' => string[],
//    'added' => string[], 'verify' => string (live crontab), 'error' => ?string]
function crm_syncManagedCrontab(): array {
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'error' => 'shell_exec is disabled', 'preserved' => 0, 'dropped' => [], 'added' => [], 'verify' => ''];
    }
    $logDir = '/home2/advertonnet/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

    $current = (string) shell_exec('crontab -l 2>/dev/null');
    $lines   = preg_split('/\R/', trim($current), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $preserved = [];
    $dropped   = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (preg_match('/^(CRON_TZ|TZ)\s*=/i', $trim)) { $dropped[] = $line; continue; }
        $isManaged = false;
        foreach (CRM_MANAGED_CRON_SCRIPTS as $s) {
            if (strpos($line, $s) !== false) { $isManaged = true; break; }
        }
        if ($isManaged) $dropped[] = $line;
        else            $preserved[] = $line;
    }

    $newLines = array_merge(['CRON_TZ=America/New_York'], $preserved, CRM_CANONICAL_CRONTAB);
    $newCron  = implode("\n", $newLines) . "\n";

    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'tempnam() failed', 'preserved' => count($preserved), 'dropped' => $dropped, 'added' => CRM_CANONICAL_CRONTAB, 'verify' => ''];
    }
    file_put_contents($tmp, $newCron);
    $applyOut = (string) shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    $verify  = (string) shell_exec('crontab -l 2>/dev/null');
    $missing = [];
    foreach (CRM_MANAGED_CRON_SCRIPTS as $s) {
        if (strpos($verify, $s) === false) $missing[] = $s;
    }
    $tzOk = (bool) preg_match('/^CRON_TZ\s*=\s*America\/New_York/m', $verify);

    if ($missing || !$tzOk) {
        return [
            'ok' => false,
            'error' => $missing ? ('Missing in crontab after apply: ' . implode(', ', $missing)) : 'CRON_TZ header not in place',
            'preserved' => count($preserved),
            'dropped'   => $dropped,
            'added'     => CRM_CANONICAL_CRONTAB,
            'verify'    => $verify,
            'apply_out' => $applyOut,
        ];
    }
    return [
        'ok' => true,
        'error' => null,
        'preserved' => count($preserved),
        'dropped'   => $dropped,
        'added'     => CRM_CANONICAL_CRONTAB,
        'verify'    => $verify,
        'apply_out' => $applyOut,
    ];
}
