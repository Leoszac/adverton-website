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
    // A seo_local deploy can be 30+ files over explicit FTPS (one TLS handshake
    // each) — well past PHP's default max_execution_time (~30s), which fatals
    // as a 500 mid-upload. Lift the limit and keep running even if the
    // operator's browser gives up on the long request.
    @set_time_limit(0);
    @ignore_user_abort(true);

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

    // The seo_local template emits nested pages (services/x.html,
    // locations/y.html). The WordPress adapter creates flat pages by slug, so
    // the nested internal links would 404. Fail clearly instead of deploying a
    // broken site — this template needs static hosting (cPanel/SFTP).
    if ($kindUsed === 'wordpress' && (string)($intake['template_choice'] ?? '') === 'seo_local') {
        return ['ok' => false, 'url' => null, 'adapter' => $kindUsed,
                'error' => 'The "SEO Local" template needs static hosting (cPanel/SFTP) for its per-city/per-service pages — WordPress is not supported for this layout. Add a cPanel/SFTP credential or pick another template.'];
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

// SFTP adapter — REAL SFTP over SSH (curl sftp://), used when a host firewalls
// FTP passive data connections (HostGator etc.). SSH auth uses the system/cPanel
// user (NOT an FTP sub-account); docroot is public_html under the login home.
function crm_deployAdapterSftp(array $pages, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host'];
    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');
    $user = (string)($cred['row']['username'] ?? '');
    $pass = (string)($cred['value'] ?? '');
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing username/password'];

    return crm_deployTwoPhaseSftp($host, $user, $pass, '/public_html', $pages, $client);
}

// Build curl auth opts for SFTP: if the stored secret is a PEM private key,
// use key auth (write to a 0600 temp file for libssh2); otherwise password.
// Sets $keyfile to the temp path to unlink afterwards (null for password auth).
function crm_sftpAuthOpts(string $user, string $secret, &$keyfile): array {
    $keyfile = null;
    if (strpos($secret, 'PRIVATE KEY') !== false) {
        $keyfile = tempnam(sys_get_temp_dir(), 'advkey');
        file_put_contents($keyfile, $secret);
        @chmod($keyfile, 0600);
        return [CURLOPT_USERNAME => $user, CURLOPT_SSH_PRIVATE_KEYFILE => $keyfile];
    }
    return [CURLOPT_USERPWD => $user . ':' . $secret];
}

// Real SFTP (SSH) two-phase deploy over curl's sftp://. ONE reused SSH
// connection for every op; paths are home-relative (login lands in the account
// home, docroot is public_html under it). SSH host-key verification is left off
// — this tool connects to arbitrary client hosts we can't pre-trust; the whole
// session (creds + data) is encrypted by SSH regardless. Auth = SSH key if the
// credential value is a private key, else password.
function crm_deployTwoPhaseSftp(string $host, string $user, string $pass,
                               string $remoteDir, array $pages, array $client): array {
    @set_time_limit(0);
    @ignore_user_abort(true);
    $base  = trim($remoteDir, '/');                 // 'public_html'
    $names = array_keys($pages);
    $rp    = static function (string $p) use ($base) { return ($base !== '' ? $base . '/' : '') . $p; };

    $keyfile = null;
    $authOpts = crm_sftpAuthOpts($user, $pass, $keyfile);
    $ch = curl_init();
    // Connection + auth are set ONCE so libcurl keeps the SSH connection alive
    // across every op. Re-setting auth per call forced a fresh SSH handshake
    // each time — ~90 handshakes overran LiteSpeed's request limit (500).
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT        => 120,
    ] + $authOpts);
    $dirUrl = 'sftp://' . $host . '/' . $base . '/';

    // SFTP protocol command(s) over the reused connection. '*' prefix = ignore
    // failure (best-effort). SFTP verbs: rename <a> <b>, rm <p>, mkdir <p>.
    $quote = function (array $cmds) use ($ch, $dirUrl) {
        curl_setopt($ch, CURLOPT_UPLOAD, false);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_QUOTE, $cmds);
        curl_setopt($ch, CURLOPT_URL, $dirUrl);
        curl_exec($ch);
        $errno = curl_errno($ch);
        return ['ok' => ($errno === 0), 'error' => 'SFTP ' . $errno . ': ' . (curl_error($ch) ?: 'command failed')];
    };
    $upload = function (string $path, string $html) use ($ch, $host) {
        $tmp = tmpfile();
        if (!$tmp) return ['ok' => false, 'error' => 'tmpfile() failed'];
        fwrite($tmp, $html); rewind($tmp);
        curl_setopt($ch, CURLOPT_QUOTE, []);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $tmp);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($html));
        curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, CURLFTP_CREATE_DIR);
        curl_setopt($ch, CURLOPT_URL, 'sftp://' . $host . '/' . $path);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        fclose($tmp);
        return ['ok' => ($resp !== false && $errno === 0), 'error' => 'SFTP ' . $errno . ': ' . ($err ?: 'upload failed')];
    };
    $finish = function (array $ret) use ($ch, &$keyfile) {
        curl_close($ch);
        if ($keyfile) @unlink($keyfile);
        return $ret;
    };

    // Phase 0: batch-remove stale .adv-bak (best-effort, one command set).
    $rm = [];
    foreach ($names as $fn) $rm[] = '*rm ' . $rp($fn . '.adv-bak');
    if ($rm) $quote($rm);

    // Phase 1: upload every page to .adv-new over the reused SSH connection.
    $uploadedNew = [];
    foreach ($pages as $filename => $html) {
        $r = $upload($rp($filename . '.adv-new'), $html);
        if (!$r['ok']) {
            $cl = [];
            foreach ($uploadedNew as $c) $cl[] = '*rm ' . $rp($c . '.adv-new');
            if ($cl) $quote($cl);
            return $finish(['ok' => false, 'url' => null,
                'error' => "Upload failed on {$filename}: " . ($r['error'] ?? 'unknown') . " — original site untouched"]);
        }
        $uploadedNew[] = $filename;
    }

    // Phase 2: batch backup (live → .adv-bak), then batch swap (.adv-new → live).
    $bk = [];
    foreach ($names as $fn) $bk[] = '*rename ' . $rp($fn) . ' ' . $rp($fn . '.adv-bak');
    $quote($bk);
    $sw = [];
    foreach ($names as $fn) $sw[] = 'rename ' . $rp($fn . '.adv-new') . ' ' . $rp($fn);
    $r = $quote($sw);
    if (!$r['ok']) {
        // Best-effort restore: put backups back, drop leftover .adv-new.
        $rb = [];
        foreach ($names as $fn) $rb[] = '*rename ' . $rp($fn . '.adv-bak') . ' ' . $rp($fn);
        $quote($rb);
        $cl = [];
        foreach ($names as $fn) $cl[] = '*rm ' . $rp($fn . '.adv-new');
        $quote($cl);
        return $finish(['ok' => false, 'url' => null,
            'error' => "Swap failed: " . ($r['error'] ?? 'unknown') . " — restored from backup"]);
    }

    return $finish(['ok' => true, 'url' => 'https://' . preg_replace('/^(?:s?ftp\.)/i', '', $host) . '/', 'error' => null]);
}

// SFTP connection probe (Test connection) — connect, auth, list the dir.
function crm_deploySftpProbe(string $host, string $user, string $pass, string $remoteDir): array {
    $base = trim($remoteDir, '/');
    $keyfile = null;
    $authOpts = crm_sftpAuthOpts($user, $pass, $keyfile);
    $ch = curl_init('sftp://' . $host . '/' . $base . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT        => 25,
    ] + $authOpts);
    $ok    = curl_exec($ch) !== false && curl_errno($ch) === 0;
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    if ($keyfile) @unlink($keyfile);
    curl_close($ch);
    return $ok ? ['ok' => true, 'error' => null]
               : ['ok' => false, 'error' => 'SFTP ' . $errno . ': ' . ($err ?: 'connect/auth failed')];
}

// Shared two-phase FTP/FTPS deploy. Both cPanel and SFTP adapters call
// this; difference is just the remote dir.
//
// Uses ONE reused FTPS connection for every op. A seo_local site is 30+ files
// and this does ~4 ops/file (bak-sweep + upload + backup-rename + swap-rename);
// opening a fresh TLS connection each time = 100+ handshakes = >2 min, which
// LiteSpeed kills mid-request (its LSAPI timeout, separate from PHP's) as a
// bare 500. Keeping the curl handle alive collapses it to seconds.
function crm_deployTwoPhaseFtp(string $host, string $user, string $pass,
                                string $remoteDir, array $pages, array $client): array {
    $remoteDir = rtrim($remoteDir, '/');
    $names     = array_keys($pages);

    $ch = curl_init();
    $baseOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        // Encrypt the CONTROL channel only (protects login creds over TLS), but
        // leave the DATA channel clear. A full TLS handshake per file on the
        // data channel makes a 30-file deploy take minutes and get killed by
        // LiteSpeed; the payload is public website HTML, so clear data is fine.
        CURLOPT_USE_SSL        => CURLUSESSL_CONTROL,
        CURLOPT_FTP_SSL        => CURLFTPSSL_CONTROL,
        // Shared hosts (HostGator etc.) present an FTPS cert for the SERVER
        // hostname (e.g. *.hostgator.com), not the client's own domain — strict
        // hostname verification always fails. Connection stays TLS-encrypted;
        // we just don't require the cert to match the domain we dialed.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        // Shared hosts sit behind NAT: their PASV (227) reply hands back an
        // internal IP the client can't reach, so the DATA connection (uploads)
        // fails even though the control connection (test) works. Ignore the
        // PASV IP and reuse the control host for data; force classic PASV.
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_FTP_USE_EPSV     => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 120,
    ];
    $dirUrl = 'ftp://' . $host . $remoteDir . '/';

    // FTP command(s) over the shared connection (RNFR/RNTO/DELE). Prefix a
    // command with '*' to ignore its failure (best-effort). Returns ok/err.
    $ftpQuote = function (array $cmds) use ($ch, $baseOpts, $dirUrl) {
        curl_setopt_array($ch, $baseOpts + [
            CURLOPT_URL       => $dirUrl,
            CURLOPT_UPLOAD    => false,
            CURLOPT_NOBODY    => true,
            CURLOPT_POSTQUOTE => $cmds,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['ok' => ($code === 0 || $code < 400), 'error' => "FTP {$code}: " . (curl_error($ch) ?: 'failed')];
    };
    $upload = function (string $path, string $html) use ($ch, $baseOpts, $host) {
        $tmp = tmpfile();
        if (!$tmp) return ['ok' => false, 'error' => 'tmpfile() failed'];
        fwrite($tmp, $html); rewind($tmp);
        curl_setopt_array($ch, $baseOpts + [
            CURLOPT_URL        => 'ftp://' . $host . $path,
            CURLOPT_NOBODY     => false,
            CURLOPT_POSTQUOTE  => [],
            CURLOPT_UPLOAD     => true,
            CURLOPT_INFILE     => $tmp,
            CURLOPT_INFILESIZE => strlen($html),
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        fclose($tmp);
        return ['ok' => ($resp !== false && ($code === 0 || $code < 400)), 'error' => "FTP {$code}: " . ($err ?: 'upload failed')];
    };
    $swapRename = function (string $from, string $to) use ($ftpQuote) {
        return $ftpQuote(['RNFR ' . $from, 'RNTO ' . $to]);
    };
    $softRename = function (string $from, string $to) use ($ftpQuote) {
        return $ftpQuote(['*RNFR ' . $from, '*RNTO ' . $to]);   // best-effort
    };
    $del = function (string $path) use ($ftpQuote) {
        return $ftpQuote(['*DELE ' . $path]);                   // best-effort
    };

    // Sweep stale .adv-bak from a prior deploy (best-effort, one batch).
    $bakCmds = [];
    foreach ($names as $fn) $bakCmds[] = '*DELE ' . $remoteDir . '/' . $fn . '.adv-bak';
    if ($bakCmds) $ftpQuote($bakCmds);

    // PHASE 1 — upload each page to filename.adv-new
    $uploadedNew = [];
    foreach ($pages as $filename => $html) {
        $r = $upload($remoteDir . '/' . $filename . '.adv-new', $html);
        if (!$r['ok']) {
            // Rollback Phase 1: delete any .adv-new we created. Live .html untouched.
            foreach ($uploadedNew as $cleanup) $del($remoteDir . '/' . $cleanup . '.adv-new');
            curl_close($ch);
            return ['ok' => false, 'url' => null,
                    'error' => "Upload failed on {$filename}: " . ($r['error'] ?? 'unknown') . " — original site untouched"];
        }
        $uploadedNew[] = $filename;
    }

    // PHASE 2 — swap: back up live .html → .adv-bak, then .adv-new → .html
    $swapped = [];
    foreach ($names as $filename) {
        $live = $remoteDir . '/' . $filename;
        $softRename($live, $live . '.adv-bak');       // best-effort backup (no-op on first deploy)
        $r = $swapRename($live . '.adv-new', $live);  // atomic swap (must succeed)
        if (!$r['ok']) {
            // Restore what we swapped so far, then clean remaining .adv-new.
            foreach ($swapped as $restored) {
                $rl = $remoteDir . '/' . $restored;
                $del($rl);
                $softRename($rl . '.adv-bak', $rl);
            }
            foreach ($names as $remaining) $del($remoteDir . '/' . $remaining . '.adv-new');
            curl_close($ch);
            return ['ok' => false, 'url' => null,
                    'error' => "Swap failed on {$filename}: " . ($r['error'] ?? 'unknown') . " — restored " . count($swapped) . " pages from backup"];
        }
        $swapped[] = $filename;
    }

    curl_close($ch);
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
        // Image assets ride in $pages for the FTP adapters; WP can't take them
        // as pages (they'd need media-library upload), so skip non-HTML here.
        if (substr($filename, -5) !== '.html') { continue; }
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

// FTP/FTPS upload helper. We use curl's ftp:// scheme + CURLUSESSL_ALL so
// credentials are sent over EXPLICIT TLS (FTPES) on port 21 — the mode cPanel
// hosts (HostGator etc.) support. The ftps:// scheme would force IMPLICIT FTPS
// on port 990, which most cPanel hosts don't listen on (connect refused).
function crm_deployFtpUpload(string $host, string $user, string $pass,
                             string $remotePath, string $content, array $client): array {
    // PHP 7.4-safe (strpos === 0) — deploy.php loaded by update.php (web on
    // PHP 8) today but keep CLI-safe for any future cron use.
    if (strpos($remotePath, '/') !== 0) $remotePath = '/' . $remotePath;
    $url = 'ftp://' . $host . $remotePath;

    $tmp = tmpfile();
    if (!$tmp) return ['ok' => false, 'url' => null, 'error' => 'tmpfile() failed'];
    fwrite($tmp, $content);
    rewind($tmp);
    $size = strlen($content);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_ALL,
        CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
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

    $ch = curl_init('ftp://' . $host . $dir . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_ALL,
        CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
        CURLOPT_SSL_VERIFYPEER => false,   // shared-host FTPS cert won't match client domain
        CURLOPT_SSL_VERIFYHOST => 0,
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

    $ch = curl_init('ftp://' . $host . $dir . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_ALL,
        CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
        CURLOPT_SSL_VERIFYPEER => false,   // shared-host FTPS cert won't match client domain
        CURLOPT_SSL_VERIFYHOST => 0,
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
            // sftp = real SSH/SFTP probe; cpanel = FTPS probe. Both target public_html.
            return $kindUsed === 'sftp'
                ? crm_deploySftpProbe($host, $user, $pass, '/public_html')
                : crm_deployFtpProbe($host, $user, $pass, '/public_html');

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

// ─── ROLLBACK ──────────────────────────────────────────────────────────
//
// Swap each {filename}.html with its {filename}.adv-bak counterpart.
// This is REVERSIBLE: a second rollback brings back the original deploy
// because the swap turns the previous bad-live into the new .adv-bak.
//
// Per-file algorithm (3 renames):
//   1. .adv-bak → .adv-rollback-tmp     (fails if no .adv-bak — first deploy)
//   2. .html    → .adv-bak              (current live becomes the new bak)
//   3. .adv-rollback-tmp → .html        (old bak becomes the new live)
//
// WordPress: no automatic rollback. The adapter creates fresh posts each
// deploy; old content lives in WP revisions and must be restored via
// wp-admin (Pages → Revisions). Return a clear message.
function crm_deployRollbackLast(int $clientId, ?int $actorUserId): array {
    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'adapter' => null, 'error' => 'Client not found'];

    $intake = crm_getIntake($clientId);
    if (!$intake || ($intake['status'] ?? '') !== 'deployed') {
        return ['ok' => false, 'adapter' => null,
                'error' => 'Nothing to roll back (intake.status is not "deployed")'];
    }

    $cred = null; $kindUsed = null;
    foreach (CRM_DEPLOY_PRIORITIES as $kind) {
        $r = crm_getFirstCredentialOfKind($clientId, $kind, $actorUserId);
        if ($r['ok']) { $cred = $r; $kindUsed = $kind; break; }
    }
    if (!$cred) return ['ok' => false, 'adapter' => null, 'error' => 'No deploy credential on file'];

    if ($kindUsed === 'wordpress' || $kindUsed === 'custom') {
        return ['ok' => false, 'adapter' => $kindUsed,
                'error' => 'Rollback not supported for ' . $kindUsed
                         . ' — restore via wp-admin Pages → Revisions'];
    }

    // Resolve host/user/pass + remote dir (mirrors the cpanel/sftp adapters)
    $host = preg_replace('#^[a-z]+://#i', '', trim((string)($cred['row']['url'] ?? '')));
    $host = rtrim((string)$host, '/');
    $user = (string)($cred['row']['username'] ?? '');
    $pass = (string)($cred['value'] ?? '');
    if ($host === '' || $user === '' || $pass === '') {
        return ['ok' => false, 'adapter' => $kindUsed, 'error' => 'Credential missing host/username/password'];
    }
    $remoteDir = $kindUsed === 'cpanel'
        ? '/public_html'
        : (rtrim('/' . ltrim((string)($cred['row']['notes'] ?? '/'), '/'), '/') ?: '/');

    $filenames = ['index.html','about.html','services.html','service-area.html','contact.html'];
    $swapped = []; $skipped = []; $errors = [];

    foreach ($filenames as $fn) {
        $live = $remoteDir . '/' . $fn;
        $bak  = $live . '.adv-bak';
        $tmp  = $live . '.adv-rollback-tmp';

        // Step 1: move .adv-bak aside. If this fails, there's no backup —
        // skip this file silently (likely the first deploy ever).
        $r1 = crm_deployFtpRename($host, $user, $pass, $bak, $tmp);
        if (!$r1['ok']) { $skipped[] = $fn; continue; }

        // Step 2: current live → .adv-bak  (so this rollback is reversible)
        $r2 = crm_deployFtpRename($host, $user, $pass, $live, $bak);
        if (!$r2['ok']) {
            // Put bak back where it was so site keeps serving current
            crm_deployFtpRename($host, $user, $pass, $tmp, $bak);
            $errors[] = "{$fn}: " . ($r2['error'] ?? 'step 2 failed');
            continue;
        }

        // Step 3: old bak → live
        $r3 = crm_deployFtpRename($host, $user, $pass, $tmp, $live);
        if (!$r3['ok']) {
            // Try to put things back: bak → live (the recent good state)
            crm_deployFtpRename($host, $user, $pass, $bak, $live);
            crm_deployFtpRename($host, $user, $pass, $tmp, $bak);  // best-effort
            $errors[] = "{$fn}: " . ($r3['error'] ?? 'step 3 failed');
            continue;
        }
        $swapped[] = $fn;
    }

    if (!$swapped) {
        $reason = $errors
            ? 'All swaps failed: ' . implode(' | ', $errors)
            : 'No .adv-bak files found — this client has no prior deploy to restore';
        crm_logClientEvent($clientId, $actorUserId, 'note', 'Rollback failed: ' . substr($reason, 0, 200));
        return ['ok' => false, 'adapter' => $kindUsed, 'error' => $reason];
    }

    // Flip intake.status back to 'approved' so the operator can re-deploy
    // a fresh build over the rolled-back site (after fixing whatever was
    // wrong). The deployed_url stays — rollback served the previous
    // content from the same URL.
    try {
        $stmt = crm_db()->prepare(
            "UPDATE client_intake SET status = 'approved' WHERE client_id = ? AND status = 'deployed'"
        );
        $stmt->execute([$clientId]);
    } catch (Throwable $e) {
        error_log('[crm_deployRollbackLast status] ' . $e->getMessage());
    }

    $detail = count($swapped) . '/' . count($filenames) . ' pages';
    if ($skipped) $detail .= ' (skipped: ' . implode(',', $skipped) . ')';
    if ($errors)  $detail .= ' (errors: ' . implode(' | ', $errors) . ')';
    crm_logClientEvent($clientId, $actorUserId, 'note', 'Deploy rolled back via ' . $kindUsed . ' — ' . $detail);

    return [
        'ok'      => true,
        'adapter' => $kindUsed,
        'detail'  => $detail,
        'swapped' => count($swapped),
        'skipped' => count($skipped),
        'errors'  => $errors,
        'error'   => null,
    ];
}

// Probe a FTPS server: just connect + list the remote dir. No state change.
function crm_deployFtpProbe(string $host, string $user, string $pass, string $remoteDir): array {
    $remoteDir = rtrim($remoteDir, '/');
    if ($remoteDir === '') $remoteDir = '/';
    $ch = curl_init('ftp://' . $host . $remoteDir . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_USE_SSL        => CURLUSESSL_ALL,
        CURLOPT_FTP_SSL        => CURLFTPSSL_ALL,
        CURLOPT_SSL_VERIFYPEER => false,   // shared-host FTPS cert won't match client domain
        CURLOPT_SSL_VERIFYHOST => 0,
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
