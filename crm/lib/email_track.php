<?php
// Email open + click tracking.
//
// Apple Mail Privacy Protection (iOS 15+/macOS 12+) preloads pixels at delivery
// time, so opens are ~70% reliable. Clicks (which require user action) are ~95%
// reliable and are the more useful signal.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/activities.php';

const CRM_TRACK_BASE = 'https://adverton.net/crm';

function crm_createEmailSend(?int $leadId, ?int $templateId, ?int $userId, string $subject, ?int $clientId = null): array {
    $open  = bin2hex(random_bytes(16));
    $click = bin2hex(random_bytes(16));
    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO email_sends (lead_id, client_id, template_id, user_id, open_token, click_token, subject)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $clientId, $templateId, $userId, $open, $click, mb_substr($subject, 0, 255)]);
        return [
            'id'           => (int) crm_db()->lastInsertId(),
            'open_token'   => $open,
            'click_token'  => $click,
        ];
    } catch (Throwable $e) {
        error_log('[crm_createEmailSend] ' . $e->getMessage());
        return ['id' => 0, 'open_token' => '', 'click_token' => ''];
    }
}

function crm_listSendsForClient(int $clientId): array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT s.*, u.display_name AS user_name
             FROM email_sends s LEFT JOIN users u ON u.id = s.user_id
             WHERE s.client_id = ? ORDER BY s.sent_at DESC'
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function crm_pixelUrl(string $openToken): string {
    return CRM_TRACK_BASE . '/t.php?t=' . $openToken;
}

function crm_redirectUrl(string $clickToken, string $targetUrl): string {
    return CRM_TRACK_BASE . '/r.php?t=' . $clickToken . '&u=' . urlencode($targetUrl);
}

// Wrap every <a href="..."> link in HTML with the redirector. Anchors with
// data-notrack="1" or href starting with mailto:/tel: are left alone.
function crm_wrapLinksWithRedirector(string $html, string $clickToken): string {
    return preg_replace_callback(
        '~<a\b([^>]*?)href=(["\'])(.*?)\2([^>]*)>~i',
        function ($m) use ($clickToken) {
            $pre  = $m[1];
            $q    = $m[2];
            $href = $m[3];
            $post = $m[4];
            if (preg_match('~^(mailto:|tel:|sms:|#)~i', $href))   return $m[0];
            if (stripos($pre . $post, 'data-notrack') !== false)  return $m[0];
            $wrapped = crm_redirectUrl($clickToken, $href);
            return "<a{$pre}href={$q}" . htmlspecialchars($wrapped, ENT_QUOTES, 'UTF-8') . "{$q}{$post}>";
        },
        $html
    );
}

function crm_appendOpenPixel(string $html, string $openToken): string {
    $img = '<img src="' . htmlspecialchars(crm_pixelUrl($openToken), ENT_QUOTES, 'UTF-8') .
           '" alt="" width="1" height="1" style="display:block;border:0">';
    if (stripos($html, '</body>') !== false) {
        return str_ireplace('</body>', $img . '</body>', $html);
    }
    return $html . $img;
}

// Record an open. Caller must already validate token. Logs activity on FIRST open.
function crm_recordOpen(string $token, string $ip, string $ua): void {
    try {
        $db = crm_db();
        $stmt = $db->prepare('SELECT id, lead_id, first_opened_at, sent_at FROM email_sends WHERE open_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) return;

        // Ignore opens within 5s of send — almost certainly Apple/Gmail prefetch
        if (strtotime((string)$row['sent_at']) >= time() - 5) {
            // Still increment count silently for stats
            $db->prepare('UPDATE email_sends SET open_count = open_count + 1, last_opened_at = NOW() WHERE id = ?')
               ->execute([$row['id']]);
            return;
        }

        $isFirst = !$row['first_opened_at'];
        $db->prepare(
            'UPDATE email_sends SET
               open_count = open_count + 1,
               first_opened_at = COALESCE(first_opened_at, NOW()),
               last_opened_at = NOW()
             WHERE id = ?'
        )->execute([$row['id']]);

        if ($isFirst) {
            crm_logActivity((int)$row['lead_id'], null, 'email', 'opened',
                'Email opened (first time) · ' . substr($ua, 0, 80));
        }

        crm_bumpTemperatureOnEngagement((int)$row['lead_id']);
    } catch (Throwable $e) { error_log('[crm_recordOpen] ' . $e->getMessage()); }
}

function crm_recordClick(string $token, string $url, string $ip, string $ua): void {
    try {
        $db = crm_db();
        $stmt = $db->prepare('SELECT id, lead_id, first_clicked_at FROM email_sends WHERE click_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) return;

        $isFirst = !$row['first_clicked_at'];
        $db->prepare(
            'UPDATE email_sends SET
               click_count = click_count + 1,
               first_clicked_at = COALESCE(first_clicked_at, NOW()),
               last_clicked_at = NOW()
             WHERE id = ?'
        )->execute([$row['id']]);

        if ($isFirst) {
            crm_logActivity((int)$row['lead_id'], null, 'email', 'clicked',
                'Clicked: ' . mb_substr($url, 0, 200));
        }

        crm_bumpTemperatureOnEngagement((int)$row['lead_id']);
    } catch (Throwable $e) { error_log('[crm_recordClick] ' . $e->getMessage()); }
}

// Bump a lead's temperature based on email engagement.
// Rules (only ever bump up, never down):
//   - 1+ click  OR  4+ opens   → hot
//   - 2+ opens                 → warm (minimum)
// When promoted to hot via engagement: auto-unenroll from active nurture
// sequences (a hot lead deserves manual attention) and create a callback task.
function crm_bumpTemperatureOnEngagement(int $leadId): void {
    try {
        $db = crm_db();
        $stmt = $db->prepare(
            'SELECT temperature, owner_user_id, first_name, last_name, email, phone, trade, source
               FROM leads WHERE id = ?'
        );
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        if (!$lead) return;

        // Aggregate opens + clicks across all email_sends for this lead
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(open_count),0)  AS opens,
                    COALESCE(SUM(click_count),0) AS clicks
               FROM email_sends WHERE lead_id = ?'
        );
        $stmt->execute([$leadId]);
        $agg = $stmt->fetch();
        $opens  = (int)($agg['opens']  ?? 0);
        $clicks = (int)($agg['clicks'] ?? 0);

        $current = (string)($lead['temperature'] ?? '');
        $target  = null;
        if ($clicks >= 1 || $opens >= 4) $target = 'hot';
        elseif ($opens >= 2)             $target = 'warm';

        // Rank to enforce "only bump up"
        $rank = ['cold' => 1, 'warm' => 2, 'hot' => 3];
        if ($target === null) return;
        $cur = $rank[$current] ?? 0;
        $new = $rank[$target]  ?? 0;
        if ($new <= $cur) return;

        $db->prepare('UPDATE leads SET temperature = ? WHERE id = ?')->execute([$target, $leadId]);

        $detail = "{$opens} opens · {$clicks} clicks · " . strtoupper($current ?: 'unset') . ' → ' . strtoupper($target);
        crm_logActivity($leadId, null, 'system', 'temperature_bumped', "Engagement scoring: {$detail}");

        // If promoted to HOT via engagement, treat like a fresh HOT lead:
        // unenroll active sequences + create immediate callback task.
        if ($target === 'hot') {
            $db->prepare(
                'UPDATE sequence_enrollments
                    SET completed_at = NOW(), unenrolled_reason = "engagement_hot"
                  WHERE lead_id = ? AND completed_at IS NULL'
            )->execute([$leadId]);

            if (file_exists(__DIR__ . '/tasks.php')) {
                require_once __DIR__ . '/tasks.php';
                $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
                crm_createTask([
                    'lead_id'     => $leadId,
                    'assigned_to' => $lead['owner_user_id'] ?? null,
                    'title'       => 'CALL NOW — engagement HOT (' . ($name ?: $lead['email']) . ')',
                    'notes'       => "Lead just hit HOT via engagement scoring ({$opens} opens, {$clicks} clicks). "
                                   . "Active nurture sequences auto-unenrolled. "
                                   . "Source: " . ($lead['source'] ?? '?')
                                   . " · Trade: " . ($lead['trade'] ?? '—')
                                   . " · Phone: " . ($lead['phone'] ?? '—'),
                    'due_at'      => date('Y-m-d H:i:s', time() + 3600),
                ]);
                crm_logActivity($leadId, null, 'system', 'hot_lead_routed',
                    'Engagement promoted to HOT — sequences unenrolled, callback task created');
            }
        }
    } catch (Throwable $e) { error_log('[crm_bumpTemperatureOnEngagement] ' . $e->getMessage()); }
}

// Send an email through Resend with tracking baked in. Returns ['ok'=>bool,'error'=>string].
function crm_sendTrackedEmail(int $leadId, array $lead, ?int $templateId, ?int $userId,
                              string $subject, string $bodyHtml): array {
    $apiKey = crm_config('RESEND_API_KEY');
    if (!$apiKey) return ['ok' => false, 'error' => 'RESEND_API_KEY not set in crm-config.php'];
    if (empty($lead['email'])) return ['ok' => false, 'error' => 'Lead has no email'];

    $send = crm_createEmailSend($leadId, $templateId, $userId, $subject);
    if (!$send['id']) return ['ok' => false, 'error' => 'DB insert failed'];

    // Plain-text → simple HTML if user wrote it as plain text in the template body.
    $isHtml = preg_match('/<[a-z][^>]*>/i', $bodyHtml);
    $html = $isHtml ? $bodyHtml : nl2br(htmlspecialchars($bodyHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    // Wrap in a minimal HTML shell so links + pixel survive most clients
    $html = '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:-apple-system,Segoe UI,sans-serif;color:#0e0d12;line-height:1.55">'
          . $html . '</body></html>';

    $html = crm_wrapLinksWithRedirector($html, $send['click_token']);
    $html = crm_appendOpenPixel($html, $send['open_token']);

    // Resolve sender — per-user override takes precedence over global config
    $resolved = crm_resolveUserSender($userId);
    $sender   = $resolved['from'];
    $replyTo  = $resolved['reply_to'];

    // Always send a plain-text alternative — improves deliverability
    // (especially in Outlook/corporate filters that down-rank HTML-only mail)
    // and gives a graceful fallback for clients that don't render HTML.
    $textBody = crm_htmlToPlainText($bodyHtml);

    $payload = [
        'from'     => $sender,
        'to'       => [$lead['email']],
        'subject'  => $subject,
        'html'     => $html,
        'text'     => $textBody,
        'reply_to' => $replyTo,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'error' => "Resend HTTP {$code}: " . substr((string)($resp ?: $err), 0, 200)];
    }

    crm_logActivity($leadId, $userId, 'email', 'sent',
        'Sent: ' . $subject . ($templateId ? ' (template)' : ''));
    crm_touchLastContacted($leadId);
    return ['ok' => true, 'send_id' => $send['id']];
}

// Resolve the From + Reply-To for a sending user.
// Falls back to crm-config.php values if the user hasn't set their own.
function crm_resolveUserSender(?int $userId): array {
    $userFrom = null; $userName = null; $userReply = null;
    if ($userId) {
        try {
            $stmt = crm_db()->prepare(
                'SELECT email_from, email_from_name, email_reply_to FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row) {
                $userFrom  = trim((string)($row['email_from']      ?? ''));
                $userName  = trim((string)($row['email_from_name'] ?? ''));
                $userReply = trim((string)($row['email_reply_to']  ?? ''));
            }
        } catch (Throwable $e) { /* fall through to config */ }
    }

    if ($userFrom !== '' && $userFrom !== null && filter_var($userFrom, FILTER_VALIDATE_EMAIL)) {
        $from = $userName !== '' ? "{$userName} <{$userFrom}>" : $userFrom;
        $reply = ($userReply !== '' && filter_var($userReply, FILTER_VALIDATE_EMAIL)) ? $userReply : $userFrom;
        return ['from' => $from, 'reply_to' => $reply];
    }

    $cfgFrom  = (string)(crm_config('CRM_FROM_ADDRESS') ?: '');
    $cfgReply = (string)(crm_config('CRM_REPLY_TO') ?: '');

    // Validate config-level From: must contain an email (RFC bracket-form OR bare).
    // If invalid, log + use a hardcoded safe default rather than fail the send.
    $fromHasEmail = (bool) preg_match('/<[^@\s]+@[^@\s>]+>/', $cfgFrom)
                 || (bool) filter_var($cfgFrom, FILTER_VALIDATE_EMAIL);
    if (!$fromHasEmail) {
        if ($cfgFrom !== '') error_log("[crm_resolveUserSender] invalid CRM_FROM_ADDRESS='{$cfgFrom}' — using fallback");
        $cfgFrom = 'Adverton <leandro@adverton.net>';
    }
    if (!filter_var($cfgReply, FILTER_VALIDATE_EMAIL)) {
        $cfgReply = 'leandro@adverton.net';
    }

    return ['from' => $cfgFrom, 'reply_to' => $cfgReply];
}

// Convert HTML body to a clean plain-text alternative for the multipart email.
// Preserves link URLs (Outlook hides them in HTML, so plain text shows them inline)
// and converts paragraph/list breaks into line breaks.
function crm_htmlToPlainText(string $html): string {
    // Strip inline scripts/styles
    $t = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $t = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $t);
    // Convert <a href="X">Y</a> → "Y (X)" so the URL is visible in plain text
    $t = preg_replace_callback('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m){
        $url  = trim($m[1]);
        $text = trim(strip_tags($m[2]));
        if ($text === '' || $text === $url) return $url;
        return "{$text} ({$url})";
    }, $t);
    // Block-level → newline
    $t = preg_replace('/<br\s*\/?>/i', "\n", $t);
    $t = preg_replace('/<\/(p|div|h[1-6]|li|tr|table)>/i', "\n", $t);
    $t = preg_replace('/<li\b[^>]*>/i', '• ', $t);
    // Strip remaining tags
    $t = strip_tags($t);
    // Decode entities + collapse extra whitespace
    $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

function crm_listSendsForLead(int $leadId): array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT s.*, t.name AS template_name, u.display_name AS user_name
             FROM email_sends s
             LEFT JOIN email_templates t ON t.id = s.template_id
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.lead_id = ? ORDER BY s.sent_at DESC'
        );
        $stmt->execute([$leadId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}
