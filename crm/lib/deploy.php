<?php
// Deploy adapter — push the rendered site to the CLIENT's hosting.
//
// One entry point: crm_deployToClient($clientId). Switches on the kind of
// credential the client has (cpanel, sftp, wordpress, custom) and dispatches
// to the matching adapter.
//
// Each adapter receives:
//   - the rendered HTML string (from crm_renderPreviewHtml)
//   - the credential row (with decrypted value) from crm_getFirstCredentialOfKind
//   - the client row
// And returns ['ok','target_url','error'].

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/intake.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/preview.php';

const CRM_DEPLOY_PRIORITIES = ['cpanel', 'sftp', 'wordpress', 'custom'];

// Top-level dispatch. Returns ['ok','url','adapter','error'].
// Multi-page: renders all 5 pages (home/about/services/service-area/contact)
// and hands the array {filename => html} to the adapter.
//
// Preflight: validates credentials BEFORE rendering / uploading so we fail
// fast on bad credentials instead of mid-deploy with partial state.
function crm_deployToClient(int $clientId, ?int $actorUserId): array {
    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'url' => null, 'adapter' => null, 'error' => 'Client not found'];

    $intake = crm_getIntake($clientId);
    if (!$intake || ($intake['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'url' => null, 'adapter' => null,
                'error' => 'Client intake must be approved before deploying'];
    }

    // Pick the highest-priority credential the client has
    $cred = null;
    $kindUsed = null;
    foreach (CRM_DEPLOY_PRIORITIES as $kind) {
        $r = crm_getFirstCredentialOfKind($clientId, $kind, $actorUserId);
        if ($r['ok']) { $cred = $r; $kindUsed = $kind; break; }
    }
    if (!$cred) {
        return ['ok' => false, 'url' => null, 'adapter' => null,
                'error' => 'No deploy credential on file (need one of: ' . implode(', ', CRM_DEPLOY_PRIORITIES) . ')'];
    }

    // Preflight — test the credential before we render or touch anything.
    // Cheaper than rendering 5 pages and discovering bad password.
    $pre = crm_deployRunPreflight($kindUsed, $cred);
    if (!$pre['ok']) {
        crm_logClientEvent($clientId, $actorUserId, 'note',
            'Deploy preflight failed (' . $kindUsed . '): ' . substr((string)$pre['error'], 0, 200));
        return ['ok' => false, 'url' => null, 'adapter' => $kindUsed,
                'error' => 'Preflight: ' . $pre['error']];
    }

    // Render all 5 pages
    $rendered = crm_renderAllPages($clientId);
    if (!$rendered['ok']) {
        return ['ok' => false, 'url' => null, 'adapter' => $kindUsed,
                'error' => 'Render failed: ' . ($rendered['error'] ?? 'unknown')];
    }
    $pages = $rendered['pages'];  // ['index.html' => '<html>...', 'about.html' => ..., ...]

    // Dispatch
    $result = match ($kindUsed) {
        'cpanel'    => crm_deployAdapterCpanel($pages, $cred, $client),
        'sftp'      => crm_deployAdapterSftp($pages, $cred, $client),
        'wordpress' => crm_deployAdapterWordpress($pages, $cred, $client),
        'custom'    => crm_deployAdapterCustom($pages, $cred, $client),
        default     => ['ok' => false, 'url' => null, 'error' => 'Unknown adapter: ' . $kindUsed],
    };

    // Persist outcome on the intake row
    try {
        if ($result['ok']) {
            $stmt = crm_db()->prepare(
                "UPDATE client_intake
                 SET status = 'deployed', deployed_at = NOW(), deployed_url = ?
                 WHERE client_id = ?"
            );
            $stmt->execute([$result['url'] ?? null, $clientId]);
            crm_logClientEvent($clientId, $actorUserId, 'note',
                'Deployed via ' . $kindUsed . ' to ' . ($result['url'] ?? 'client hosting'));
        } else {
            crm_logClientEvent($clientId, $actorUserId, 'note',
                'Deploy attempt failed (' . $kindUsed . '): ' . substr((string)($result['error'] ?? ''), 0, 200));
        }
    } catch (Throwable $e) {
        error_log('[crm_deployToClient persist] ' . $e->getMessage());
    }

    return ['ok' => $result['ok'], 'url' => $result['url'] ?? null,
            'adapter' => $kindUsed, 'error' => $result['error'] ?? null];
}

// ─── ADAPTERS ──────────────────────────────────────────────────────────

// cPanel adapter — two-phase upload with rollback.
//
//   Phase 1: upload all N pages to {filename}.adv-new
//            (existing .html files untouched; site keeps serving old content)
//   Phase 2: for each page, rename .html → .adv-bak, then .adv-new → .html
//            (.adv-bak left as 1-deploy rollback safety)
//
//   If phase 1 fails on any page → cleanup .adv-new files we created, abort.
//   If phase 2 fails midway     → attempt to restore .adv-bak → .html for
//                                 the pages we already swapped.
//
// Stale .adv-bak from a prior successful deploy gets cleaned at the START
// of this run so they don't accumulate.
function crm_deployAdapterCpanel(array $pages, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host (set url field)'];
    $user = (string)($cred['row']['username'] ?? '');
    $pass = (string)($cred['value'] ?? '');
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing username/password'];

    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');

    return crm_deployTwoPhaseFtp($host, $user, $pass, '/public_html', $pages, $client);
}

// Generic SFTP adapter — same two-phase pattern as cPanel. The notes
// field on the credential row carries the remote dir prefix (default '/').
function crm_deployAdapterSftp(array $pages, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host'];
    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');
    $user = (string)($cred['row']['username'] ?? '');
    $pass = (string)($cred['value'] ?? '');
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing username/password'];

    $prefix = rtrim('/' . ltrim((string)($cred['row']['notes'] ?? '/'), '/'), '/');
    if ($prefix === '') $prefix = '/';

    return crm_deployTwoPhaseFtp($host, $user, $pass, $prefix, $pages, $client);
}

// Shared two-phase FTP/FTPS deploy. Both cPanel and SFTP adapters call
// this; difference is just the remote dir.
function crm_deployTwoPhaseFtp(string $host, string $user, string $pass,
                                string $remoteDir, array $pages, array $client): array {
    $remoteDir = rtrim($remoteDir, '/');
    $names     = array_keys($pages);

    // Sweep stale .adv-bak from a prior deploy. Best-effort — not fatal.
    foreach ($names as $fn) {
        crm_deployFtpDelete($host, $user, $pass, $remoteDir . '/' . $fn . '.adv-bak');
    }

    // PHASE 1 — upload each page to filename.adv-new
    $uploadedNew = [];   // filenames that landed as .adv-new
    foreach ($pages as $filename => $html) {
        $target = $remoteDir . '/' . $filename . '.adv-new';
        $r = crm_deployFtpUpload($host, $user, $pass, $target, $html, $client);
        if (!$r['ok']) {
            // Rollback Phase 1: delete any .adv-new files we created. Site
            // (the live .html files) was never touched.
            foreach ($uploadedNew as $cleanup) {
                crm_deployFtpDelete($host, $user, $pass, $remoteDir . '/' . $cleanup . '.adv-new');
            }
            return ['ok' => false, 'url' => null,
                    'error' => "Upload failed on {$filename}: " . ($r['error'] ?? 'unknown') . " — original site untouched"];
        }
        $uploadedNew[] = $filename;
    }

    // PHASE 2 — swap. For each page:
    //   1) Rename existing .html → .adv-bak  (skip silently if no current file)
    //   2) Rename .adv-new → .html
    $swapped = [];   // filenames we successfully swapped (for rollback)
    foreach ($names as $filename) {
        $live = $remoteDir . '/' . $filename;
        $bak  = $live . '.adv-bak';
        $new  = $live . '.adv-new';

        // Best-effort backup of the existing live file. May fail if no
        // previous file existed — that's fine, just means first deploy.
        crm_deployFtpRename($host, $user, $pass, $live, $bak);

        // Atomic swap: rename .adv-new → .html
        $r = crm_deployFtpRename($host, $user, $pass, $new, $live);
        if (!$r['ok']) {
            // Phase 2 failure: try to restore what we swapped so far.
            foreach ($swapped as $restored) {
                $rl = $remoteDir . '/' . $restored;
                crm_deployFtpDelete($host, $user, $pass, $rl);                  // remove the new .html we just put there
                crm_deployFtpRename($host, $user, $pass, $rl . '.adv-bak', $rl); // put the old one back
            }
            // Also clean any remaining .adv-new files for pages we hadn't
            // gotten to yet, plus the failing one.
            foreach ($names as $remaining) {
                crm_deployFtpDelete($host, $user, $pass, $remoteDir . '/' . $remaining . '.adv-new');
            }
            return ['ok' => false, 'url' => null,
                    'error' => "Swap failed on {$filename}: " . ($r['error'] ?? 'unknown') . " — restored " . count($swapped) . " pages from backup"];
        }
        $swapped[] = $filename;
    }

    $pubHost = preg_replace('/^(?:s?ftp\.|ftps\.)/i', '', $host);
    return ['ok' => true, 'url' => 'https://' . $pubHost . '/', 'error' => null];
}

// Wordpress adapter — POSTs to WP REST API to create 5 pages, then sets
// the home page as the site's front page.
//
//   On partial failure: deletes every page created so far (DELETE
//   /wp/v2/pages/{id}?force=true) so WP isn't left with orphan pages.
//   On success: POSTs /wp/v2/settings to set show_on_front=page +
//   page_on_front=<home_id>. Best-effort — if the auth user lacks
//   manage_options, the deploy still counts as successful and the
//   operator flips the setting manually (post-deploy task list).
//
// The user must use a WP Application Password (NOT the login password).
function crm_deployAdapterWordpress(array $pages, array $cred, array $client): array {
    $base = rtrim((string)($cred['row']['url'] ?? ''), '/');
    if ($base === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing url (e.g. https://site.com)'];
    $user = (string)$cred['row']['username'];
    $pass = (string)$cred['value'];
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'WP needs username + app password'];

    $slugMap = [
        'index.html'        => 'home',
        'about.html'        => 'about',
        'services.html'     => 'services',
        'service-area.html' => 'service-area',
        'contact.html'      => 'contact',
    ];

    $createdIds = [];   // [postId, postId, ...] in creation order — for rollback
    $homeId     = null;
    $homeLink   = null;

    foreach ($pages as $filename => $html) {
        $slug  = $slugMap[$filename] ?? pathinfo($filename, PATHINFO_FILENAME);
        $title = ucfirst(str_replace('-', ' ', $slug));
        $body  = json_encode([
            'title'   => $title,
            'content' => $html,
            'status'  => 'publish',
            'slug'    => $slug,
        ]);
        $ch = curl_init($base . '/wp-json/wp/v2/pages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            // Rollback: delete every page we created so far so the site
            // isn't left in a partial state.
            foreach ($createdIds as $pid) {
                crm_deployWpDeletePost($base, $user, $pass, $pid);
            }
            return ['ok' => false, 'url' => null,
                    'error' => "Page create failed on {$slug}: HTTP {$code} — rolled back " . count($createdIds) . " prior pages"];
        }
        $data = json_decode((string)$resp, true) ?: [];
        $pid  = (int)($data['id'] ?? 0);
        if ($pid > 0) $createdIds[] = $pid;
        if ($slug === 'home') {
            $homeId   = $pid;
            $homeLink = (string)($data['link'] ?? $base);
        }
    }

    // Set the home page as the site front page (best-effort, doesn't fail
    // the deploy if it doesn't work — usually a permission issue, fixable
    // in wp-admin).
    if ($homeId) {
        crm_deployWpSetFrontPage($base, $user, $pass, $homeId);
    }

    return ['ok' => true, 'url' => $homeLink ?: $base, 'error' => null];
}

// WP helper: delete a page by ID (force=true skips trash bin).
function crm_deployWpDeletePost(string $base, string $user, string $pass, int $postId): bool {
    $ch = curl_init($base . '/wp-json/wp/v2/pages/' . $postId . '?force=true');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// WP helper: POST /wp/v2/settings to set show_on_front=page + page_on_front.
// Returns true if WP accepted the setting, false otherwise. Best-effort —
// requires manage_options capability on the auth user.
function crm_deployWpSetFrontPage(string $base, string $user, string $pass, int $homePageId): bool {
    $body = json_encode([
        'show_on_front' => 'page',
        'page_on_front' => $homePageId,
    ]);
    $ch = curl_init($base . '/wp-json/wp/v2/settings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// Custom adapter — host doesn't fit our patterns. The dispatcher logs the
// failure to client_events; the operator picks it up from /crm/today.php
// and uploads the rendered HTML by hand.
function crm_deployAdapterCustom(array $pages, array $cred, array $client): array {
    return ['ok' => false, 'url' => null,
            'error' => 'Custom hosting — manual deploy required for ' . count($pages) . ' pages. Check client_events log for details.'];
}

// Tasks that fire automatically on a successful deploy. Operator handles
// these by hand because they live on third-party platforms (Google Business
// Profile, Local Services Ads, Tradio CRM). The deploy adapter only does
// the website file upload.
//
// Keep ['key', 'title'] in this canonical list and reference the title from
// crm_postDeployTaskTitles(); crm_postDeployProgress() reads the same list
// to render the per-client progress card.
function crm_postDeployTaskCatalog(): array {
    return [
        ['key' => 'gbp',        'title' => 'Setup Google Business Profile (claim/optimize/add photos)',  'short' => 'Google Business Profile'],
        ['key' => 'lsa',        'title' => 'Configure Google Local Services Ads (categories, area, hours)', 'short' => 'Local Services Ads'],
        ['key' => 'tradio',     'title' => 'Seed Tradio CRM account (services, hours, team)',           'short' => 'Tradio CRM'],
        ['key' => 'cold_email', 'title' => 'Setup cold-email outreach with personalized scripts',       'short' => 'Cold email outreach'],
        ['key' => 'dns',        'title' => 'Verify domain DNS + SSL on the deployed site',              'short' => 'DNS + SSL verified'],
    ];
}

function crm_postDeployTaskTitles(): array {
    return array_column(crm_postDeployTaskCatalog(), 'title');
}

// Returns one row per canonical post-deploy milestone with its current
// status from the tasks table. Used by client.php to render the progress
// card. Matches tasks by title prefix + lead_id (the deploy code creates
// them with "<title> — <bizName>", so prefix match is enough).
//
// Each row: ['key','title','short','task_id','done_at','done_by_name','due_at','status'].
// status ∈ { 'done', 'pending', 'not_created' }.
function crm_postDeployProgress(int $clientId, ?int $leadId): array {
    $catalog = crm_postDeployTaskCatalog();
    if (!$leadId) {
        // Tasks are keyed by lead_id; no lead → can't find them.
        return array_map(fn($c) => $c + ['task_id' => null, 'done_at' => null, 'done_by_name' => null, 'due_at' => null, 'status' => 'not_created'], $catalog);
    }

    try {
        $stmt = crm_db()->prepare(
            'SELECT t.id, t.title, t.done_at, t.due_at, u.display_name AS done_by_name
             FROM tasks t
             LEFT JOIN users u ON u.id = t.assigned_to
             WHERE t.lead_id = ?
             ORDER BY t.id ASC'
        );
        $stmt->execute([$leadId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[crm_postDeployProgress] ' . $e->getMessage());
        $rows = [];
    }

    $out = [];
    foreach ($catalog as $cat) {
        $task = null;
        foreach ($rows as $r) {
            if (strpos((string)$r['title'], $cat['title']) === 0) {  // prefix match
                $task = $r;
                break;
            }
        }
        $status = !$task ? 'not_created' : (!empty($task['done_at']) ? 'done' : 'pending');
        $out[] = $cat + [
            'task_id'      => $task ? (int)$task['id'] : null,
            'done_at'      => $task['done_at']      ?? null,
            'done_by_name' => $task['done_by_name'] ?? null,
            'due_at'       => $task['due_at']       ?? null,
            'status'       => $status,
        ];
    }
    return $out;
}

// FTP/FTPS upload helper. We use curl's ftps:// scheme so credentials are
// sent over TLS. Compatible with both FTP-explicit-TLS (FTPES) and
// FTPS-implicit on port 990; cPanel uses FTPES which curl picks via flags.
function crm_deployFtpUpload(string $host, string $user, string $pass,
                             string $remotePath, string $content, array $client): array {
    // PHP 7.4-safe (strpos === 0) — deploy.php loaded by update.php (web on
    // PHP 8) today but keep CLI-safe for any future cron use.
    if (strpos($remotePath, '/') !== 0) $remotePath = '/' . $remotePath;
    $url = 'ftps://' . $host . $remotePath;

    $tmp = tmpfile();
    if (!$tmp) return ['ok' => false, 'url' => null, 'error' => 'tmpfile() failed'];
    fwrite($tmp, $content);
    rewind($tmp);
    $size = strlen($content);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_TRY,
        CURLOPT_FTP_SSL        => CURLFTPSSL_TRY,
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $tmp,
        CURLOPT_INFILESIZE     => $size,
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    fclose($tmp);
    curl_close($ch);

    if ($resp === false || ($code !== 0 && $code >= 400)) {
        return ['ok' => false, 'url' => null, 'error' => "FTP {$code}: " . ($err ?: 'upload failed')];
    }
    // We don't know the public URL from FTP alone — derive a best-effort one
    // from the host minus 'ftp.' or 'sftp.' prefix.
    $pubHost = preg_replace('/^(?:s?ftp\.|ftps\.)/i', '', $host);
    return ['ok' => true, 'url' => 'https://' . $pubHost . '/', 'error' => null];
}

// FTP/FTPS delete helper. Best-effort — returns true on success, false
// on any failure (no-op semantics for cleanup paths).
function crm_deployFtpDelete(string $host, string $user, string $pass, string $remotePath): bool {
    if (strpos($remotePath, '/') !== 0) $remotePath = '/' . $remotePath;
    $dir = dirname($remotePath);
    if ($dir === '.' || $dir === '') $dir = '/';
    $base = basename($remotePath);

    $ch = curl_init('ftps://' . $host . $dir . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_TRY,
        CURLOPT_FTP_SSL        => CURLFTPSSL_TRY,
        CURLOPT_NOBODY         => true,
        CURLOPT_POSTQUOTE      => ['DELE ' . $base],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // FTP DELE returns 250 on success; curl maps to HTTP 226 or 0 on
    // success-without-body. Treat anything below 400 as success.
    return ($code === 0 || $code < 400);
}

// FTP/FTPS rename helper. Returns ['ok'=>bool, 'error'=>?string].
function crm_deployFtpRename(string $host, string $user, string $pass,
                              string $fromPath, string $toPath): array {
    if (strpos($fromPath, '/') !== 0) $fromPath = '/' . $fromPath;
    if (strpos($toPath, '/') !== 0)   $toPath   = '/' . $toPath;
    $dir = dirname($fromPath);
    if ($dir === '.' || $dir === '') $dir = '/';

    $ch = curl_init('ftps://' . $host . $dir . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_TRY,
        CURLOPT_FTP_SSL        => CURLFTPSSL_TRY,
        CURLOPT_NOBODY         => true,
        CURLOPT_POSTQUOTE      => ['RNFR ' . $fromPath, 'RNTO ' . $toPath],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 0 && $code >= 400) {
        return ['ok' => false, 'error' => "FTP rename {$code}: " . ($err ?: 'failed')];
    }
    return ['ok' => true, 'error' => null];
}

// ─── PREFLIGHT / TEST CONNECTION ───────────────────────────────────────
//
// Internal dispatcher used by crm_deployToClient (auto-preflight) and the
// public crm_deployTestConnection (callable from a future "Test connection"
// button). Both share the same per-adapter probe.
function crm_deployRunPreflight(string $kindUsed, array $cred): array {
    switch ($kindUsed) {
        case 'cpanel':
        case 'sftp':
            $host = trim((string)($cred['row']['url'] ?? ''));
            $user = (string)($cred['row']['username'] ?? '');
            $pass = (string)($cred['value'] ?? '');
            if ($host === '' || $user === '' || $pass === '') {
                return ['ok' => false, 'error' => 'Credential missing host/username/password'];
            }
            $host = preg_replace('#^[a-z]+://#i', '', $host);
            $host = rtrim($host, '/');
            $prefix = $kindUsed === 'cpanel'
                ? '/public_html'
                : (rtrim('/' . ltrim((string)($cred['row']['notes'] ?? '/'), '/'), '/') ?: '/');
            return crm_deployFtpProbe($host, $user, $pass, $prefix);

        case 'wordpress':
            $base = rtrim((string)($cred['row']['url'] ?? ''), '/');
            $user = (string)($cred['row']['username'] ?? '');
            $pass = (string)($cred['value'] ?? '');
            if ($base === '' || $user === '' || $pass === '') {
                return ['ok' => false, 'error' => 'WP credential missing url/username/app-password'];
            }
            return crm_deployWpProbe($base, $user, $pass);

        case 'custom':
            // Custom hosting needs manual deploy — preflight always fails
            // so we never even render. Operator handles the upload by hand.
            return ['ok' => false, 'error' => 'Custom hosting — manual deploy required'];
    }
    return ['ok' => false, 'error' => 'Unknown adapter kind: ' . $kindUsed];
}

// Public entry point — pick the credential the same way deploy would, then
// run preflight. Used by /crm/update.php?mode=deploy_test_connection so the
// operator can verify a credential without committing to a deploy.
function crm_deployTestConnection(int $clientId, ?int $actorUserId): array {
    foreach (CRM_DEPLOY_PRIORITIES as $kind) {
        $r = crm_getFirstCredentialOfKind($clientId, $kind, $actorUserId);
        if ($r['ok']) {
            $pre = crm_deployRunPreflight($kind, $r);
            return ['ok' => $pre['ok'], 'adapter' => $kind, 'error' => $pre['error']];
        }
    }
    return ['ok' => false, 'adapter' => null, 'error' => 'No deploy credential on file'];
}

// Probe a FTPS server: just connect + list the remote dir. No state change.
function crm_deployFtpProbe(string $host, string $user, string $pass, string $remoteDir): array {
    $remoteDir = rtrim($remoteDir, '/');
    if ($remoteDir === '') $remoteDir = '/';
    $ch = curl_init('ftps://' . $host . $remoteDir . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_TRY,
        CURLOPT_FTP_SSL        => CURLFTPSSL_TRY,
        CURLOPT_NOBODY         => true,    // list, don't download
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 0 && $code >= 400) {
        return ['ok' => false, 'error' => "FTP probe {$code}: " . ($err ?: 'connection failed')];
    }
    return ['ok' => true, 'error' => null];
}

// Probe a WordPress site: GET /wp-json/wp/v2/types/page with auth.
// Lightweight (returns small JSON), only succeeds with valid app password.
function crm_deployWpProbe(string $base, string $user, string $pass): array {
    $ch = curl_init($base . '/wp-json/wp/v2/types/page');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        $hint = '';
        if ($code === 401 || $code === 403) {
            $hint = ' (check username + that the password is a WP Application Password, NOT the login password)';
        } elseif ($code === 404) {
            $hint = ' (REST API endpoint not found — check URL or that WP REST is enabled)';
        }
        return ['ok' => false, 'error' => "WP probe HTTP {$code}" . $hint . ($err ? ' — ' . $err : '')];
    }
    return ['ok' => true, 'error' => null];
}
