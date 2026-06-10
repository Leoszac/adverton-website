#!/usr/bin/env php
<?php
// Reads a raw email from stdin (cPanel "Pipe to Program" forwarder), parses
// it, matches sender against clients.primary_email/billing_email, saves
// every image attachment to /home/advertonnet/crm-files/clients/{id}/photos/inbox/.
//
// CPANEL SETUP (one-time, run by founder):
//   1. cPanel → Email Accounts → Create assets@adverton.net
//   2. cPanel → Forwarders → Add Forwarder → "Pipe to a Program"
//      Path (relative to home): public_html/crm/email-pipe.php
//
// The shebang above lets cPanel invoke us as `/path/to/email-pipe.php`
// directly (cPanel docs explicitly say to omit /usr/bin/php from the
// program field). Script must be chmod +x — handled by .cpanel.yml.
//
// Hardening:
//   - Senders that don't match a client are dropped + logged (never bounce)
//   - Each attachment goes through crm_storeClientPhoto (MIME re-detection,
//     size cap, EXIF extraction, DB row)
//   - Cap: 20 attachments per email, 10MB per file (set in lib/photos.php)
//   - Classification is async (cron-photo-classify.php picks them up)

declare(strict_types=1);

// CLI-only: this file is invoked by cPanel's mail forwarder ("Pipe to a
// Program"), never by a web request. Refuse anything else so a curious
// visitor can't smuggle stdin via HTTP.
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/photos.php';
require_once __DIR__ . '/lib/clients.php';

const PIPE_MAX_ATTACHMENTS = 20;

// Read raw email
$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') {
    error_log('[email-pipe] empty stdin');
    exit(0);  // exit 0 so cPanel doesn't bounce
}

// Parse with mailparse if available (cPanel ships it on PHP-FPM most plans);
// fall back to a tiny built-in MIME splitter otherwise.
$parsed = pipeParseEmail($raw);
if (!$parsed) {
    error_log('[email-pipe] could not parse email; from=' . pipeExtractFromHeader($raw));
    exit(0);
}

$fromEmail = strtolower(trim((string)($parsed['from_email'] ?? '')));
if (!$fromEmail) {
    error_log('[email-pipe] no From: address');
    exit(0);
}

// Match against clients.primary_email or billing_email
$matchMethod = 'sender';
$client = pipeFindClientForEmail($fromEmail);
if (!$client) {
    // Sender isn't a known client email (a third party — staff, the customer,
    // Leo forwarding). Try to infer the right client with AI from the subject,
    // attachment filenames and sender, against the client list. Photos still
    // land as unapproved, so a wrong guess is caught at the approval step.
    $client = pipeAiMatchClient($raw, $parsed, $fromEmail);
    if ($client) $matchMethod = 'ai';
}
if (!$client) {
    error_log("[email-pipe] unknown sender {$fromEmail}; no client match (incl. AI); dropped " . count($parsed['attachments']) . ' attachment(s)');
    exit(0);
}
$clientId = (int)$client['id'];

$saved = 0; $skipped = 0;
foreach (array_slice($parsed['attachments'], 0, PIPE_MAX_ATTACHMENTS) as $att) {
    if (!is_array($att) || empty($att['data'])) { $skipped++; continue; }
    if (!str_starts_with((string)($att['mime'] ?? ''), 'image/')) { $skipped++; continue; }

    // Write to a scratch tmp file so crm_storeClientPhoto's
    // (MIME re-detect + size check + EXIF) work exactly the same as
    // the manual-upload path.
    $tmp = tempnam(sys_get_temp_dir(), 'mailpipe_');
    if (!$tmp || file_put_contents($tmp, $att['data']) === false) { $skipped++; continue; }

    $r = crm_storeClientPhoto(
        $clientId, $tmp,
        (string)($att['filename'] ?: 'inbound.jpg'),
        (string)$att['mime'],
        'email_inbound'
    );
    if ($r['ok']) $saved++;
    else { $skipped++; error_log("[email-pipe] store failed: " . ($r['error'] ?? '?')); }
    @unlink($tmp);
}

try {
    $how = ($matchMethod === 'ai') ? 'AI-matched (verify these are the right client!)' : 'sender-matched';
    crm_logClientEvent($clientId, null, 'note',
        "Email-inbound ({$how}): saved {$saved} photo(s), skipped {$skipped} from {$fromEmail}");
} catch (Throwable $e) { error_log('[email-pipe logEvent] ' . $e->getMessage()); }

exit(0);

// ─────────────────────────────────────────────────────────────────────

function pipeFindClientForEmail(string $email): ?array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT * FROM clients
             WHERE LOWER(primary_email) = ? OR LOWER(billing_email) = ?
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$email, $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[email-pipe lookup] ' . $e->getMessage());
        return null;
    }
}

// AI fallback: when the sender doesn't match a client, ask Claude which client
// these photos belong to, using the subject + attachment names + sender against
// the client list. Returns the client row only on a confident match (>=0.75).
function pipeAiMatchClient(string $raw, array $parsed, string $fromEmail): ?array {
    try {
        $apiKey = crm_config('ANTHROPIC_API_KEY');
        if (!$apiKey) return null;

        $clients = crm_db()->query(
            "SELECT id, business_name, trade FROM clients
             WHERE status IS NULL OR status NOT IN ('cancelled')
             ORDER BY business_name LIMIT 80"
        )->fetchAll();
        if (!$clients) return null;

        $subject  = pipeExtractHeader($raw, 'Subject');
        $fromName = '';
        if (preg_match('/^From:\s*"?([^"<\r\n]+?)"?\s*</mi', $raw, $m)) $fromName = trim($m[1]);
        $files = [];
        foreach (($parsed['attachments'] ?? []) as $a) {
            $fn = trim((string)($a['filename'] ?? ''));
            if ($fn !== '') $files[] = $fn;
        }
        $list = [];
        foreach ($clients as $c) $list[] = "id={$c['id']} | {$c['business_name']} | {$c['trade']}";

        $prompt = "Photos were emailed to a contractor-marketing intake address. Decide which of OUR clients they belong to.\n\n"
            . "EMAIL\n  From: " . ($fromName !== '' ? $fromName . ' ' : '') . "<{$fromEmail}>\n"
            . "  Subject: {$subject}\n"
            . "  Attachment filenames: " . (count($files) ? implode(', ', $files) : '(none)') . "\n\n"
            . "OUR CLIENTS\n  " . implode("\n  ", $list) . "\n\n"
            . "Match ONLY if the email clearly points to ONE client (its business name, an obvious nickname, "
            . "or a filename that names it). If it is unclear or could be several, return null.\n"
            . "Reply with ONLY JSON, nothing else: {\"client_id\": <id or null>, \"confidence\": <0 to 1>}";

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 120,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) { error_log('[email-pipe AI] HTTP ' . $code); return null; }

        $data = json_decode((string)$resp, true) ?: [];
        $text = '';
        foreach ((array)($data['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') $text .= (string)($b['text'] ?? '');
        }
        if (!preg_match('/\{.*\}/s', $text, $mm)) return null;
        $out  = json_decode($mm[0], true) ?: [];
        $cid  = (int)($out['client_id'] ?? 0);
        $conf = (float)($out['confidence'] ?? 0);
        error_log("[email-pipe AI] from={$fromEmail} subject=\"{$subject}\" -> client_id={$cid} confidence={$conf}");
        if ($cid <= 0 || $conf < 0.75) return null;

        $st = crm_db()->prepare('SELECT * FROM clients WHERE id = ?');
        $st->execute([$cid]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[email-pipe AI] ' . $e->getMessage());
        return null;
    }
}

function pipeExtractHeader(string $raw, string $name): string {
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $raw, $m)) {
        return trim($m[1]);
    }
    return '';
}

// Extracts From: header value cheaply for log lines, even if full parse fails.
function pipeExtractFromHeader(string $raw): string {
    if (preg_match('/^From:\s*(.+)$/mi', $raw, $m)) return trim($m[1]);
    return 'unknown';
}

// Parse the email. Prefers ext-mailparse; falls back to a tiny multipart
// splitter that handles base64 + image/* parts (which is 95% of contractor
// phone uploads).
function pipeParseEmail(string $raw): ?array {
    if (function_exists('mailparse_msg_parse')) return pipeParseWithExt($raw);
    return pipeParseFallback($raw);
}

function pipeParseWithExt(string $raw): ?array {
    $msg = mailparse_msg_create();
    mailparse_msg_parse($msg, $raw);
    $struct = mailparse_msg_get_structure($msg);

    $fromEmail = '';
    $rootHeaders = mailparse_msg_get_part_data($msg);
    if (!empty($rootHeaders['headers']['from'])) {
        $fromHeader = (string)$rootHeaders['headers']['from'];
        if (preg_match('/<([^>]+)>/', $fromHeader, $m)) $fromEmail = $m[1];
        elseif (filter_var($fromHeader, FILTER_VALIDATE_EMAIL)) $fromEmail = $fromHeader;
    }

    $attachments = [];
    foreach ($struct as $part) {
        $section = mailparse_msg_get_part($msg, $part);
        $info = mailparse_msg_get_part_data($section);
        $mime = strtolower((string)($info['content-type'] ?? ''));
        if (!str_starts_with($mime, 'image/')) continue;

        $bodyStart = (int)($info['starting-pos-body'] ?? 0);
        $bodyEnd   = (int)($info['ending-pos-body']   ?? 0);
        $body = substr($raw, $bodyStart, max(0, $bodyEnd - $bodyStart));
        $enc  = strtolower((string)($info['transfer-encoding'] ?? '7bit'));
        if ($enc === 'base64')             $body = base64_decode($body, true) ?: '';
        elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
        if ($body === '') continue;

        $filename = (string)($info['disposition-filename']
                          ?? $info['content-name'] ?? 'image.jpg');
        $attachments[] = ['mime' => $mime, 'filename' => $filename, 'data' => $body];
    }
    mailparse_msg_free($msg);

    return ['from_email' => $fromEmail, 'attachments' => $attachments];
}

// Cheap fallback when ext-mailparse isn't available. Handles standard
// multipart/mixed | multipart/related with base64 image parts. Good enough
// for iPhone Mail / Android Gmail attachments.
function pipeParseFallback(string $raw): ?array {
    $headerEnd = strpos($raw, "\r\n\r\n");
    if ($headerEnd === false) $headerEnd = strpos($raw, "\n\n");
    if ($headerEnd === false) return null;
    $headerBlock = substr($raw, 0, $headerEnd);
    $body        = substr($raw, $headerEnd + 4);

    $fromEmail = '';
    if (preg_match('/^From:\s*(.+)$/mi', $headerBlock, $m)) {
        if (preg_match('/<([^>]+)>/', $m[1], $mm)) $fromEmail = $mm[1];
        elseif (filter_var(trim($m[1]), FILTER_VALIDATE_EMAIL)) $fromEmail = trim($m[1]);
    }

    $boundary = '';
    if (preg_match('/^Content-Type:\s*multipart\/[^;]+;\s*boundary=(?:"([^"]+)"|(\S+))/mi', $headerBlock, $m)) {
        $boundary = $m[1] ?: $m[2];
    }
    if (!$boundary) return ['from_email' => $fromEmail, 'attachments' => []];

    $parts = explode('--' . $boundary, $body);
    $attachments = [];
    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");
        if ($part === '' || str_starts_with($part, '--')) continue;
        $sep = strpos($part, "\r\n\r\n");
        if ($sep === false) $sep = strpos($part, "\n\n");
        if ($sep === false) continue;
        $hd = substr($part, 0, $sep);
        $bd = trim(substr($part, $sep + 4));

        if (!preg_match('/Content-Type:\s*(image\/[a-z0-9.+-]+)/i', $hd, $m)) continue;
        $mime = strtolower($m[1]);
        $filename = 'image.' . (CRM_PHOTO_EXT_OF[$mime] ?? 'jpg');
        if (preg_match('/filename=(?:"([^"]+)"|(\S+))/i', $hd, $fn)) {
            $filename = $fn[1] ?: $fn[2];
        }

        $enc = '7bit';
        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $hd, $em)) $enc = strtolower($em[1]);
        if ($enc === 'base64')             $bd = base64_decode($bd, true) ?: '';
        elseif ($enc === 'quoted-printable') $bd = quoted_printable_decode($bd);
        if ($bd === '') continue;

        $attachments[] = ['mime' => $mime, 'filename' => $filename, 'data' => $bd];
    }
    return ['from_email' => $fromEmail, 'attachments' => $attachments];
}
