<?php
if (!defined('AUDIT_ENTRY')) { http_response_code(404); exit; }

// Check if a website URL is reachable. Used as part of the audit:
// a contractor with a "website link" that points to a 404, parking page,
// or unreachable host gets a worse signal than one whose site loads fine.

function checkWebsiteAlive(?string $url): array {
    $url = trim((string)$url);
    if ($url === '') return ['alive' => false, 'reason' => 'no_url', 'http_code' => 0];
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AdvertonAuditBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => false, // contractors often have lax SSL — don't fail audits over it
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 0) {
        // HEAD blocked → retry with GET
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AdvertonAuditBot/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RANGE          => '0-0',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
    }

    if ($code >= 200 && $code < 400) {
        return ['alive' => true, 'reason' => 'ok', 'http_code' => $code];
    }
    if ($code === 0) {
        return ['alive' => false, 'reason' => 'unreachable', 'http_code' => 0, 'err' => $err];
    }
    return ['alive' => false, 'reason' => 'http_error', 'http_code' => $code];
}
