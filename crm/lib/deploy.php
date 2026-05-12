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
function crm_deployToClient(int $clientId, ?int $actorUserId): array {
    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'url' => null, 'adapter' => null, 'error' => 'Client not found'];

    $intake = crm_getIntake($clientId);
    if (!$intake || ($intake['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'url' => null, 'adapter' => null,
                'error' => 'Client intake must be approved before deploying'];
    }

    // Render all 5 pages
    $rendered = crm_renderAllPages($clientId);
    if (!$rendered['ok']) {
        return ['ok' => false, 'url' => null, 'adapter' => null,
                'error' => 'Render failed: ' . ($rendered['error'] ?? 'unknown')];
    }
    $pages = $rendered['pages'];  // ['index.html' => '<html>...', 'about.html' => ..., ...]

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

// cPanel SFTP adapter — uploads via FTP curl. Multi-page: uploads each
// {filename => html} entry to /public_html/{filename}.
function crm_deployAdapterCpanel(array $pages, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host (set url field)'];
    $user = (string)($cred['row']['username'] ?? '');
    $pass = (string)($cred['value'] ?? '');
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing username/password'];

    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');

    $uploaded = 0;
    $errors = [];
    foreach ($pages as $filename => $html) {
        $r = crm_deployFtpUpload($host, $user, $pass, '/public_html/' . $filename, $html, $client);
        if ($r['ok']) {
            $uploaded++;
        } else {
            $errors[] = "{$filename}: " . ($r['error'] ?? 'unknown');
        }
    }
    if ($errors) {
        return ['ok' => false, 'url' => null, 'error' => "Uploaded {$uploaded}/" . count($pages) . " pages. Errors: " . implode(' | ', $errors)];
    }
    // Derive public URL from host (best-effort — same logic as crm_deployFtpUpload)
    $pubHost = preg_replace('/^(?:s?ftp\.|ftps\.)/i', '', $host);
    return ['ok' => true, 'url' => 'https://' . $pubHost . '/', 'error' => null];
}

// Generic SFTP adapter — same multi-page pattern. Uses notes field as
// remote dir prefix (default '/'); each page lands at {prefix}/{filename}.
function crm_deployAdapterSftp(array $pages, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host'];
    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');

    $prefix = rtrim('/' . ltrim((string)($cred['row']['notes'] ?? '/'), '/'), '/');
    $uploaded = 0;
    $errors = [];
    foreach ($pages as $filename => $html) {
        $r = crm_deployFtpUpload(
            $host, (string)$cred['row']['username'], (string)$cred['value'],
            $prefix . '/' . $filename, $html, $client
        );
        if ($r['ok']) {
            $uploaded++;
        } else {
            $errors[] = "{$filename}: " . ($r['error'] ?? 'unknown');
        }
    }
    if ($errors) {
        return ['ok' => false, 'url' => null, 'error' => "Uploaded {$uploaded}/" . count($pages) . " pages. Errors: " . implode(' | ', $errors)];
    }
    $pubHost = preg_replace('/^(?:s?ftp\.|ftps\.)/i', '', $host);
    return ['ok' => true, 'url' => 'https://' . $pubHost . '/', 'error' => null];
}

// Wordpress adapter — POSTs to WP REST API to create 5 pages.
// home → set as front page (slug: home). Others as standard pages.
// NOTE: setting WP front_page option requires settings:write — operator
// flips it manually if needed (we just create the pages here).
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

    $created = 0;
    $errors = [];
    $homeLink = null;
    foreach ($pages as $filename => $html) {
        $slug = $slugMap[$filename] ?? pathinfo($filename, PATHINFO_FILENAME);
        $title = ucfirst(str_replace('-', ' ', $slug));
        $body = json_encode([
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
            $errors[] = "{$slug}: HTTP {$code}";
            continue;
        }
        $data = json_decode((string)$resp, true) ?: [];
        if ($slug === 'home') $homeLink = (string)($data['link'] ?? $base);
        $created++;
    }
    if ($errors) {
        return ['ok' => false, 'url' => $homeLink, 'error' => "Created {$created}/" . count($pages) . " pages. Errors: " . implode(' | ', $errors)];
    }
    return ['ok' => true, 'url' => $homeLink ?: $base, 'error' => null];
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
function crm_postDeployTaskTitles(): array {
    return [
        'Setup Google Business Profile (claim/optimize/add photos)',
        'Configure Google Local Services Ads (categories, area, hours)',
        'Seed Tradio CRM account (services, hours, team)',
        'Setup cold-email outreach with personalized scripts',
        'Verify domain DNS + SSL on the deployed site',
    ];
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
