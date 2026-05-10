<?php
// One-shot: register the Instantly health cron via cPanel uapi binary OR
// shell crontab as fallback. DELETE after successful run.
//
// Usage:
//   curl -sX POST 'https://adverton.net/crm/_install-cron.php?token=cron-9k7m2q' -d ''

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const ONE_SHOT_TOKEN = 'cron-9k7m2q';
const CRON_TOKEN     = 'fb83e553a45863a3e9e8d190cb57c368';

header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== ONE_SHOT_TOKEN) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')      { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$cmd = "curl -s 'https://adverton.net/crm/cron-instantly-health.php?token=" . CRON_TOKEN . "' >/dev/null 2>&1";

$results = [];
$installed = false;

// === Attempt 1: uapi CLI binary ===
$uapiPath = '/usr/local/cpanel/bin/uapi';
if (file_exists($uapiPath) && function_exists('shell_exec')) {
    // First check if a similar cron line already exists
    $listOut = @shell_exec($uapiPath . ' --user=advertonnet Cron list_lines 2>&1');
    $alreadyHas = $listOut && strpos($listOut, 'cron-instantly-health.php') !== false;
    $results['uapi_list'] = [
        'found' => $listOut !== null,
        'already_has_cron' => $alreadyHas,
        'preview' => substr((string)$listOut, 0, 200),
    ];

    if ($alreadyHas) {
        $installed = true;
        $results['uapi_skip'] = 'already installed via uapi';
    } else {
        // Add the cron line
        $escapedCmd = escapeshellarg($cmd);
        $addOut = @shell_exec($uapiPath . ' --user=advertonnet Cron add_line command=' . $escapedCmd . ' minute=0 hour=\\* day=\\* month=\\* weekday=\\* 2>&1');
        $results['uapi_add'] = [
            'output' => substr((string)$addOut, 0, 400),
            'success' => $addOut && strpos($addOut, 'status') && (strpos($addOut, 'status: 1') || strpos($addOut, '"status":1') || strpos($addOut, 'success')),
        ];
        if ($results['uapi_add']['success']) {
            $installed = true;
        }
    }
}

// === Attempt 2: crontab CLI fallback ===
if (!$installed && function_exists('shell_exec')) {
    $current = @shell_exec('crontab -l 2>/dev/null') ?: '';
    $alreadyHas = strpos($current, 'cron-instantly-health.php') !== false;
    $results['crontab_check'] = ['already_has' => $alreadyHas, 'len' => strlen($current)];

    if ($alreadyHas) {
        $installed = true;
        $results['crontab_skip'] = 'already installed via crontab';
    } else {
        // Append our line and reinstall the crontab
        $newCron = trim($current) . "\n0 * * * * " . $cmd . "\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'cron_');
        file_put_contents($tmpFile, $newCron);
        $installOut = @shell_exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1');
        @unlink($tmpFile);

        // Verify
        $verify = @shell_exec('crontab -l 2>/dev/null') ?: '';
        $verifyHas = strpos($verify, 'cron-instantly-health.php') !== false;
        $results['crontab_install'] = [
            'install_output' => substr((string)$installOut, 0, 200),
            'verified' => $verifyHas,
        ];
        if ($verifyHas) {
            $installed = true;
        }
    }
}

// === If neither path worked, surface the line for manual setup ===
if (!$installed) {
    $results['manual_required'] = [
        'reason' => 'Neither uapi nor crontab were available — likely shared-hosting restriction.',
        'cron_line_to_add_manually_in_cPanel' => "0 * * * * " . $cmd,
        'cpanel_path' => 'cPanel → Cron Jobs → Add New Cron Job → Common Settings: Once Per Hour → Command: ' . $cmd,
    ];
}

echo json_encode([
    'installed'    => $installed,
    'attempts'     => $results,
    'cron_command' => $cmd,
], JSON_PRETTY_PRINT);
