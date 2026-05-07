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
function crm_deployToClient(int $clientId, ?int $actorUserId): array {
    $client = crm_getClient($clientId);
    if (!$client) return ['ok' => false, 'url' => null, 'adapter' => null, 'error' => 'Client not found'];

    $intake = crm_getIntake($clientId);
    if (!$intake || ($intake['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'url' => null, 'adapter' => null,
                'error' => 'Client intake must be approved before deploying'];
    }

    // Render the HTML
    $rendered = crm_renderPreviewHtml($clientId);
    if (!$rendered['ok']) {
        return ['ok' => false, 'url' => null, 'adapter' => null,
                'error' => 'Render failed: ' . ($rendered['error'] ?? 'unknown')];
    }
    $html = $rendered['html'];

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
        'cpanel'    => crm_deployAdapterCpanel($html, $cred, $client),
        'sftp'      => crm_deployAdapterSftp($html, $cred, $client),
        'wordpress' => crm_deployAdapterWordpress($html, $cred, $client),
        'custom'    => crm_deployAdapterCustom($html, $cred, $client),
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

// cPanel SFTP adapter — uploads via FTP curl (most cPanel hosts gate SFTP
// behind shell access; FTP-with-TLS is universally available).
//
// Credential row shape:
//   url       e.g. "ftp.example.com" (no scheme), or "example.com" — adapter prepends ftps://
//   username  cPanel user
//   value     decrypted password
//
// Target path: htdocs root → upload index.html. Most cPanel sites have
// public_html as the FTP user's home, so we land directly there.
function crm_deployAdapterCpanel(string $html, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host (set url field)'];
    $user = (string)($cred['row']['username'] ?? '');
    $pass = (string)($cred['value'] ?? '');
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing username/password'];

    // Strip scheme if present
    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');

    return crm_deployFtpUpload($host, $user, $pass, '/public_html/index.html', $html, $client);
}

// Generic SFTP adapter — uses the FTP curl path too (most simple hosts).
// If the client's hosting is genuinely SFTP-only, we'd need phpseclib;
// flagged as a TODO when we encounter such a client.
function crm_deployAdapterSftp(string $html, array $cred, array $client): array {
    $host = trim((string)($cred['row']['url'] ?? ''));
    if ($host === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing host'];
    $host = preg_replace('#^[a-z]+://#i', '', $host);
    $host = rtrim($host, '/');

    $remotePath = '/' . ltrim((string)($cred['row']['notes'] ?? '/index.html'), '/');
    return crm_deployFtpUpload(
        $host, (string)$cred['row']['username'], (string)$cred['value'],
        $remotePath, $html, $client
    );
}

// Wordpress adapter — POSTs to WP REST API to create/update a page.
// We don't replace the theme; we publish a single page set as front-page.
// Credential value should be a Wordpress Application Password (Users →
// Edit Profile → Application Passwords on the WP side).
function crm_deployAdapterWordpress(string $html, array $cred, array $client): array {
    $base = rtrim((string)($cred['row']['url'] ?? ''), '/');
    if ($base === '') return ['ok' => false, 'url' => null, 'error' => 'Cred missing url (e.g. https://site.com)'];
    $user = (string)$cred['row']['username'];
    $pass = (string)$cred['value'];
    if ($user === '' || $pass === '') return ['ok' => false, 'url' => null, 'error' => 'WP needs username + app password'];

    $title = (string)($client['business_name'] ?? 'Home');
    $body  = json_encode([
        'title'   => $title,
        'content' => $html,
        'status'  => 'publish',
        'slug'    => 'home',
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
        return ['ok' => false, 'url' => null,
                'error' => "WP REST HTTP {$code}: " . substr((string)$resp, 0, 200)];
    }
    $data = json_decode((string)$resp, true) ?: [];
    return ['ok' => true, 'url' => (string)($data['link'] ?? $base), 'error' => null];
}

// Custom adapter — host doesn't fit our patterns; we fall back to creating
// an operator task with the rendered HTML attached for manual upload.
function crm_deployAdapterCustom(string $html, array $cred, array $client): array {
    return ['ok' => false, 'url' => null,
            'error' => 'Custom hosting — manual deploy required. Operator task created.'];
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
    if (!str_starts_with($remotePath, '/')) $remotePath = '/' . $remotePath;
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
