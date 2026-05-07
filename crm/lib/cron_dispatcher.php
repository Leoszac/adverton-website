<?php
// Page-load cron dispatcher: when an authenticated user loads any CRM page,
// we check which scheduled jobs are due and run them. Atomic (LOCK_EX) so
// concurrent page loads don't double-fire. Uses fastcgi_finish_request when
// available so the user sees a fast response and the work happens after.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

const CRM_CRON_STATE = '/home2/advertonnet/cron-state.json';

const CRM_CRON_JOBS = [
    'client_triggers'   => ['interval' => 86400, 'script' => 'cron-client-triggers.php'],
    'health_score'      => ['interval' => 86400, 'script' => 'cron-health-score.php'],
    'lost_reengagement' => ['interval' => 86400, 'script' => 'cron-lost-reengagement.php'],
    'sequences'         => ['interval' => 1800,  'script' => 'cron-sequences.php'],
    'backup'            => ['interval' => 86400, 'script' => 'cron-backup.php'],
    // Sprint 3: classify newly-arrived client photos. Every 5 min so a
    // contractor who just emailed assets@adverton.net sees them organized
    // by the next time they (or the operator) reload client.php.
    'photo_classify'    => ['interval' => 300,   'script' => 'cron-photo-classify.php'],
];

function crm_loadCronState(): array {
    if (!is_readable(CRM_CRON_STATE)) return [];
    $raw = @file_get_contents(CRM_CRON_STATE);
    return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}

function crm_saveCronState(array $state): void {
    $dir = dirname(CRM_CRON_STATE);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(CRM_CRON_STATE, json_encode($state), LOCK_EX);
}

// Returns the list of jobs due to run right now.
function crm_dueCronJobs(array $state): array {
    $now = time();
    $due = [];
    foreach (CRM_CRON_JOBS as $name => $job) {
        $last = (int)($state[$name]['last_run'] ?? 0);
        if (($now - $last) >= $job['interval']) $due[] = $name;
    }
    return $due;
}

// Run a single cron file in-process (require it). Catches throwables.
function crm_runCronJob(string $name): array {
    $start = microtime(true);
    $script = CRM_CRON_JOBS[$name]['script'] ?? null;
    if (!$script) return ['ok' => false, 'error' => 'unknown job'];

    $path = dirname(__DIR__) . '/' . $script;
    if (!is_readable($path)) return ['ok' => false, 'error' => 'missing script'];

    // Sandbox so the cron's exit/echo doesn't break the caller
    ob_start();
    try {
        // Signal cron scripts to skip their HTTP-token gate
        if (!defined('CRM_INPROCESS_CRON')) define('CRM_INPROCESS_CRON', 1);
        require $path;
        $output = ob_get_clean();
        return ['ok' => true, 'duration_ms' => (int)((microtime(true) - $start) * 1000),
                'output' => mb_substr((string)$output, 0, 500)];
    } catch (Throwable $e) {
        ob_end_clean();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// Try to acquire a lock. Returns the lock fp or null if another worker holds it.
function crm_cronAcquireLock() {
    $lockPath = CRM_CRON_STATE . '.lock';
    $dir = dirname($lockPath);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $fp = @fopen($lockPath, 'c');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

function crm_cronReleaseLock($fp): void {
    if (!$fp) return;
    @flock($fp, LOCK_UN);
    @fclose($fp);
}

// Main entry: runs any due jobs, with concurrency guard. Safe to call on
// every page load — fires at most once per interval per job.
function crm_maybeRunDueCrons(): void {
    // Quick check before locking — avoids contention when nothing is due
    $state = crm_loadCronState();
    $due = crm_dueCronJobs($state);
    if (!$due) return;

    $lock = crm_cronAcquireLock();
    if (!$lock) return;

    try {
        // Re-read state under lock
        $state = crm_loadCronState();
        $due = crm_dueCronJobs($state);
        if (!$due) return;

        foreach ($due as $name) {
            $r = crm_runCronJob($name);
            $state[$name] = [
                'last_run'    => time(),
                'last_ok'     => !empty($r['ok']),
                'last_output' => $r['output'] ?? ($r['error'] ?? ''),
            ];
            crm_saveCronState($state);
            crm_log("inline_cron name={$name} ok=" . ($r['ok'] ? '1' : '0'));
        }
    } finally {
        crm_cronReleaseLock($lock);
    }
}

// Wrap crm_maybeRunDueCrons in a shutdown function so the user gets the
// page response immediately and the cron runs in the background.
function crm_scheduleCronTick(): void {
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;

    register_shutdown_function(function() {
        // On PHP-FPM, end the request so the browser stops waiting
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // mod_php fallback: flush output
            @ob_end_flush();
            @flush();
        }
        try { crm_maybeRunDueCrons(); } catch (Throwable $e) { error_log('[cron-tick] ' . $e->getMessage()); }
    });
}
