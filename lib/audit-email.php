<?php
if (!defined('AUDIT_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/benchmarks.php';
require_once __DIR__ . '/audit-scorer.php';

// Email rendering + sending. Resend HTTP API primary, PHP mail() fallback.

const SENDER_FROM    = 'Adverton <no-reply@adverton.net>';
const SENDER_REPLY   = 'hello@adverton.net';
const SENDER_ADDRESS = '16192 Coastal Highway, Lewes, DE 19958, USA';
const CTA_BASE_URL   = 'https://adverton.net/';

// Brand tokens — kept 1:1 in sync with website-deploy/styles.css :root.
const COLOR_PURPLE     = '#6d28d9';
const COLOR_PURPLE_DK  = '#5b21b6';
const COLOR_PURPLE_TIN = '#f3eeff';
const COLOR_PURPLE_BG  = '#faf9ff';
const COLOR_GREEN      = '#16a34a';
const COLOR_AMBER      = '#f59e0b';
const COLOR_RED        = '#dc2626';
const COLOR_INK        = '#0e0d12';
const COLOR_INK_2      = '#383640';
const COLOR_INK_3      = '#6b6877';
const COLOR_LINE       = '#e7e4ee';

// Font stack matching the site (system-first, then Inter, then web-safe).
const FONT_STACK = '-apple-system, BlinkMacSystemFont, "SF Pro Display", "Inter", "Helvetica Neue", Arial, sans-serif';

// Hosted logo (deployed at /assets/adverton-logo.png on adverton.net).
const LOGO_URL = 'https://adverton.net/assets/adverton-logo.png';

// ------- Public API -------

function sendAuditEmail(array $form, array $audit, string $auditId): bool {
    $html = renderAuditEmail($form, $audit, $auditId);
    $text = stripHtml($html);
    $business = $audit['business_name'] ?? $form['business_name'] ?? 'your business';
    $subject = "Your Google Business audit: {$audit['score']}/100 — {$business}";
    return sendEmail($form['email'], $subject, $html, $text);
}

function sendManualPendingEmail(array $form): bool {
    $first = htmlEsc($form['first_name'] ?? 'there');
    $bodyInner = "<p style='font-size:16px;color:" . COLOR_INK . ";margin:0 0 16px;'>Hi {$first},</p>"
               . "<p style='color:" . COLOR_INK_2 . ";line-height:1.6;'>Thanks for requesting your free Google Business audit. We've got your info.</p>"
               . "<p style='color:" . COLOR_INK_2 . ";line-height:1.6;'>Because you couldn't grab your Google Maps link, <strong>Leandro</strong> is going to look up your profile by hand and send the full audit within <strong>24 business hours</strong>.</p>"
               . "<p style='color:" . COLOR_INK_2 . ";line-height:1.6;'>If anything's urgent, just reply to this email — it goes straight to Leandro.</p>"
               . "<p style='color:" . COLOR_INK . ";margin-top:24px;'>— Leandro<br><span style='color:" . COLOR_INK_3 . ";font-size:14px;'>Adverton</span></p>";
    $html = renderEmailShell('Your audit is on the way', $bodyInner, $form['email']);
    $text = stripHtml($html);
    return sendEmail($form['email'], 'Your Adverton audit is on the way', $html, $text);
}

function notifyNewLead(array $form, ?array $audit, string $auditId, bool $manual = false): bool {
    $recipient = config('LEAD_NOTIFICATION_EMAIL') ?: 'hello@adverton.net';

    if ($manual) {
        $tag = '[MANUAL]';
        $subject = "{$tag} New audit lead: {$form['first_name']} {$form['last_name']} — {$form['business_name']} ({$form['trade']})";
    } else {
        $temp = classifyLead($audit, $form);
        $tag = "[{$temp}]";
        $bn  = $audit['business_name'] ?? $form['business_name'] ?? 'unknown';
        $subject = "{$tag} New audit lead: {$form['first_name']} {$form['last_name']} — {$bn} ({$audit['score']}/100, {$form['trade']})";
    }

    $lines = [];
    $lines[] = "Audit ID:   {$auditId}";
    $lines[] = "Name:       {$form['first_name']} {$form['last_name']}";
    $lines[] = "Email:      {$form['email']}";
    $lines[] = "Phone:      {$form['phone']}";
    $lines[] = "Trade:      {$form['trade']}";
    $lines[] = "";
    if ($manual) {
        $lines[] = "Path:       MANUAL (user couldn't paste GBP URL)";
        $lines[] = "Business:   " . ($form['business_name'] ?? '—');
        $lines[] = "City/State: " . ($form['city_state'] ?? '—');
        $lines[] = "Website:    " . ($form['website'] ?? '—');
        $lines[] = "";
        $lines[] = "ACTION: look up the business in Google Maps, run an audit by hand, send within 24h.";
    } else {
        $lines[] = "Path:       AUTOMATED";
        $lines[] = "Business:   " . ($audit['business_name'] ?? '—');
        $lines[] = "GBP URL:    " . ($form['gbp_url'] ?? '—');
        $lines[] = "Maps URL:   " . ($audit['google_maps_uri'] ?? '—');
        $lines[] = "Score:      {$audit['score']}/100 ({$audit['passed_count']}/{$audit['total_count']} checks passed)";
        $lines[] = "Rating:     " . ($audit['rating'] > 0 ? number_format($audit['rating'], 1) : '—') . " ({$audit['review_count']} reviews)";
        $lines[] = "Photos:     {$audit['photo_count']}";
        $lines[] = "";
        $top = topFailedChecks($audit, 5);
        if ($top) {
            $lines[] = "Top issues to lead with on the call:";
            foreach ($top as $i => $c) {
                $lines[] = '  ' . ($i + 1) . '. ' . $c['label'];
            }
        }
    }
    $lines[] = "";
    $lines[] = "Submitted:  " . gmdate('Y-m-d H:i:s') . " UTC";
    $lines[] = "Source IP:  " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $bodyText = implode("\n", $lines);
    $bodyHtml = "<pre style='font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px;line-height:1.6;color:" . COLOR_INK . ";white-space:pre-wrap;'>" . htmlEsc($bodyText) . "</pre>";
    return sendEmail($recipient, $subject, $bodyHtml, $bodyText);
}

// ------- Templates -------

function renderAuditEmail(array $form, array $audit, string $auditId): string {
    $first    = htmlEsc($form['first_name'] ?? 'there');
    $business = htmlEsc($audit['business_name'] ?? $form['business_name'] ?? 'your business');
    $score    = (int)$audit['score'];
    $trade    = $form['trade'] ?? 'Other';
    $bench    = htmlEsc(benchmarkLine($trade, $score, $audit['review_count'] ?? 0));
    $intro    = htmlEsc(tradeIntroLine($trade, $score));
    $temp     = classifyLead($audit, $form);
    $cta      = tradeCtaCopy($temp);

    // Score color
    if ($score < 50)        { $scoreColor = COLOR_RED;    $tier = 'Needs work'; }
    elseif ($score < 75)    { $scoreColor = COLOR_AMBER;  $tier = 'Decent — room to grow'; }
    else                    { $scoreColor = COLOR_GREEN;  $tier = 'Top tier'; }

    // Build sections
    $passingItems = $failingItems = '';
    foreach ($audit['checks'] as $c) {
        if ($c['pass']) {
            $passingItems .= renderCheckPassRow(htmlEsc($c['label']));
        } else {
            $failingItems .= renderCheckFailRow(htmlEsc($c['label']), htmlEsc($c['tip']));
        }
    }

    $top = topFailedChecks($audit, 3);
    $topActionsHtml = renderTopActions($top);

    // Email button → Calendly direct (UTM params preserved for analytics).
    $ctaUrl = 'https://calendly.com/meet-adverton/15?utm_source=audit&utm_medium=email&utm_campaign=gbp_audit&audit_id=' . urlencode($auditId);

    // Compose body
    $body = '';

    // 1. Greeting + business name
    $body .= "<p style='font-size:16px;color:" . COLOR_INK . ";margin:0 0 12px;'>Hi {$first},</p>";
    $body .= "<p style='color:" . COLOR_INK_2 . ";line-height:1.6;margin:0 0 24px;'>Here's your free Google Business Profile audit for <strong style='color:" . COLOR_INK . "'>{$business}</strong>.</p>";

    // 2. Score hero with SVG circle
    $body .= renderScoreHero($score, $scoreColor, $tier, $bench);

    // 3. Trade-fluent intro paragraph
    $body .= "<p style='color:" . COLOR_INK_2 . ";font-size:15px;line-height:1.65;margin:24px 0 32px;'>{$intro}</p>";

    // 4. What's working
    if ($passingItems !== '') {
        $body .= renderSectionHeader('What\'s working', COLOR_GREEN);
        $body .= "<table role='presentation' cellpadding='0' cellspacing='0' style='width:100%;margin:8px 0 28px;'><tbody>{$passingItems}</tbody></table>";
    }

    // 5. What's costing you
    if ($failingItems !== '') {
        $body .= renderSectionHeader("What's costing you customers", COLOR_RED);
        $body .= "<table role='presentation' cellpadding='0' cellspacing='0' style='width:100%;margin:8px 0 28px;'><tbody>{$failingItems}</tbody></table>";
    }

    // 6. Top 3 actions
    $body .= renderSectionHeader('Do this week', COLOR_PURPLE);
    $body .= $topActionsHtml;

    // 7. CTA
    $body .= renderCta($cta, $ctaUrl);

    // 8. Sign-off
    $body .= "<p style='color:" . COLOR_INK_2 . ";line-height:1.6;margin:32px 0 12px;'>If anything in here doesn't make sense, just reply to this email. I read every reply.</p>";
    $body .= "<p style='color:" . COLOR_INK . ";margin:0;'>— Leo from Adverton<br><span style='color:" . COLOR_INK_3 . ";font-size:14px;'>The marketing team for U.S. home service contractors</span></p>";

    return renderEmailShell("Your GBP audit: {$score}/100", $body, $form['email']);
}

function renderScoreHero(int $score, string $color, string $tier, string $benchmark): string {
    return ''
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:' . COLOR_PURPLE_BG . ';border:1px solid ' . COLOR_LINE . ';border-radius:16px;margin:0 0 24px;">'
        . '<tr><td style="padding:28px 24px;text-align:center;">'
        . '<div style="font-size:72px;font-weight:900;color:' . $color . ';line-height:1;letter-spacing:-2px;">' . $score . '</div>'
        . '<div style="font-size:18px;font-weight:600;color:' . COLOR_INK_3 . ';margin-top:2px;">/ 100</div>'
        . '<div style="font-size:13px;color:' . COLOR_INK_3 . ';text-transform:uppercase;letter-spacing:0.12em;font-weight:700;margin-top:10px;">Your audit score</div>'
        . '<div style="font-size:14px;color:' . COLOR_INK_2 . ';line-height:1.55;margin-top:14px;max-width:480px;margin-left:auto;margin-right:auto;">' . $benchmark . '</div>'
        . '</td></tr></table>';
}

function renderCheckPassRow(string $label): string {
    return ''
        . '<tr><td style="padding:8px 0;border-bottom:1px solid ' . COLOR_LINE . ';">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr>'
        . '<td valign="top" width="28" style="padding-right:8px;">'
        . '<div style="width:22px;height:22px;border-radius:11px;background:' . COLOR_GREEN . ';color:#fff;text-align:center;line-height:22px;font-size:13px;font-weight:700;">✓</div>'
        . '</td>'
        . '<td valign="middle" style="font-size:15px;color:' . COLOR_INK . ';font-weight:500;">' . $label . '</td>'
        . '</tr></table></td></tr>';
}

function renderCheckFailRow(string $label, string $tip): string {
    return ''
        . '<tr><td style="padding:14px 16px;background:#fff;border:1px solid ' . COLOR_LINE . ';border-radius:12px;margin-bottom:8px;display:block;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr>'
        . '<td valign="top" width="28" style="padding-right:10px;">'
        . '<div style="width:22px;height:22px;border-radius:11px;background:' . COLOR_RED . ';color:#fff;text-align:center;line-height:22px;font-size:12px;font-weight:700;">✕</div>'
        . '</td>'
        . '<td valign="top">'
        . '<div style="font-size:15px;color:' . COLOR_INK . ';font-weight:700;margin-bottom:4px;">' . $label . '</div>'
        . '<div style="font-size:14px;color:' . COLOR_INK_2 . ';line-height:1.55;">' . $tip . '</div>'
        . '</td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="height:8px;line-height:8px;">&nbsp;</td></tr>';
}

function renderSectionHeader(string $title, string $accent): string {
    return ''
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px;"><tr>'
        . '<td valign="middle" style="padding-right:10px;">'
        . '<div style="width:6px;height:22px;background:' . $accent . ';border-radius:3px;"></div>'
        . '</td>'
        . '<td valign="middle" style="font-size:18px;font-weight:800;color:' . COLOR_INK . ';letter-spacing:-0.01em;">' . htmlEsc($title) . '</td>'
        . '</tr></table>';
}

function renderTopActions(array $top): string {
    if (empty($top)) {
        return "<p style='color:" . COLOR_INK_2 . ";line-height:1.6;margin:8px 0 24px;'>You're already covering the basics. The next gains come from posting weekly updates and adding service-area pages on your website. Happy to walk you through it on a call.</p>";
    }
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:8px 0 28px;"><tbody>';
    $i = 0;
    foreach ($top as $c) {
        $i++;
        $label = htmlEsc($c['label']);
        $tip   = htmlEsc($c['tip']);
        $html .= ''
            . '<tr><td style="padding:14px 16px 14px 16px;background:#fff;border:1px solid ' . COLOR_LINE . ';border-radius:12px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr>'
            . '<td valign="top" width="36" style="padding-right:12px;">'
            . '<div style="width:30px;height:30px;border-radius:15px;background:' . COLOR_PURPLE . ';color:#fff;text-align:center;line-height:30px;font-size:14px;font-weight:800;">' . $i . '</div>'
            . '</td>'
            . '<td valign="top">'
            . '<div style="font-size:15px;color:' . COLOR_INK . ';font-weight:700;margin-bottom:4px;">' . $label . '</div>'
            . '<div style="font-size:14px;color:' . COLOR_INK_2 . ';line-height:1.55;">' . $tip . '</div>'
            . '</td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="height:8px;line-height:8px;">&nbsp;</td></tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

function renderCta(array $cta, string $ctaUrl): string {
    $headline = htmlEsc($cta['headline']);
    $body     = $cta['body']; // already trusted HTML
    $btn      = htmlEsc($cta['button']);
    return ''
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:32px 0 0;background:linear-gradient(135deg,' . COLOR_PURPLE . ',' . COLOR_PURPLE_DK . ');border-radius:16px;">'
        . '<tr><td style="padding:32px 28px;text-align:center;color:#fff;">'
        . '<div style="font-size:13px;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.12em;font-weight:700;margin-bottom:12px;">From Adverton</div>'
        . '<div style="font-size:22px;font-weight:800;line-height:1.3;color:#fff;margin-bottom:12px;">' . $headline . '</div>'
        . '<div style="font-size:15px;line-height:1.55;color:rgba(255,255,255,0.92);margin:0 auto 24px;max-width:480px;">' . $body . '</div>'
        . '<a href="' . htmlEsc($ctaUrl) . '" style="display:inline-block;background:#fff;color:' . COLOR_PURPLE . ';padding:14px 28px;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;box-shadow:0 8px 20px rgba(0,0,0,0.2);">' . $btn . ' →</a>'
        . '<div style="font-size:12px;color:rgba(255,255,255,0.65);margin-top:14px;">No long sales pitch. 15 minutes. Honest yes/no on whether $799/mo makes sense for you.</div>'
        . '</td></tr></table>';
}

function renderEmailShell(string $title, string $bodyHtml, string $recipientEmail): string {
    $unsubUrl = buildUnsubscribeUrl($recipientEmail);
    return ''
        . '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light only">'
        . '<title>' . htmlEsc($title) . '</title>'
        . '</head>'
        . '<body style="margin:0;padding:0;background:#f5f4f9;font-family:' . FONT_STACK . ';color:' . COLOR_INK . ';-webkit-font-smoothing:antialiased;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f5f4f9;"><tr><td style="padding:24px 12px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;margin:0 auto;background:#ffffff;border-radius:18px;box-shadow:0 8px 24px rgba(13,11,30,0.06);">'
        // Header bar with logo
        . '<tr><td style="padding:22px 28px;border-bottom:1px solid ' . COLOR_LINE . ';">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr>'
        . '<td valign="middle">'
        . '<a href="https://adverton.net" style="text-decoration:none;display:inline-block;">'
        . '<img src="' . LOGO_URL . '" alt="Adverton" width="120" style="display:block;height:30px;width:auto;border:0;outline:none;">'
        . '</a>'
        . '</td>'
        . '<td valign="middle" align="right" style="font-family:' . FONT_STACK . ';font-size:11px;color:' . COLOR_INK_3 . ';text-transform:uppercase;letter-spacing:0.12em;font-weight:700;">Free GBP audit</td>'
        . '</tr></table></td></tr>'
        // Body
        . '<tr><td style="padding:32px 28px;font-family:' . FONT_STACK . ';">' . $bodyHtml . '</td></tr>'
        // Footer (CAN-SPAM)
        . '<tr><td style="padding:18px 28px 28px;border-top:1px solid ' . COLOR_LINE . ';font-family:' . FONT_STACK . ';font-size:12px;color:' . COLOR_INK_3 . ';line-height:1.6;">'
        . 'You\'re receiving this because you requested a free Google Business audit at adverton.net.<br>'
        . 'Adverton is operated by MDS LLC · ' . htmlEsc(SENDER_ADDRESS) . '<br>'
        . '<a href="' . htmlEsc($unsubUrl) . '" style="color:' . COLOR_INK_3 . ';text-decoration:underline;">Unsubscribe</a> · '
        . '<a href="https://adverton.net/privacy.html" style="color:' . COLOR_INK_3 . ';text-decoration:underline;">Privacy Policy</a>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

// ------- Sending: Resend → mail() fallback -------

function sendEmail(string $to, string $subject, string $html, string $text): bool {
    $apiKey = config('RESEND_API_KEY');
    if ($apiKey) {
        try {
            return sendViaResend($to, $subject, $html, $text, $apiKey);
        } catch (Throwable $e) {
            error_log('[audit-email] Resend failed, falling back to mail(): ' . $e->getMessage());
        }
    }
    return sendViaMail($to, $subject, $html);
}

function sendViaResend(string $to, string $subject, string $html, string $text, string $apiKey): bool {
    $payload = [
        'from'     => SENDER_FROM,
        'to'       => [$to],
        'subject'  => $subject,
        'html'     => $html,
        'text'     => $text,
        'reply_to' => SENDER_REPLY,
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
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new RuntimeException("curl: $err");
    if ($code >= 400) throw new RuntimeException("Resend HTTP $code: " . substr((string)$resp, 0, 200));
    return true;
}

function sendViaMail(string $to, string $subject, string $html): bool {
    $headers  = "From: " . SENDER_FROM . "\r\n";
    $headers .= "Reply-To: " . SENDER_REPLY . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: Adverton-Audit/1.0\r\n";
    return @mail($to, $subject, $html, $headers);
}

// ------- Unsubscribe tokens -------

function buildUnsubscribeUrl(string $email): string {
    $salt = config('UNSUBSCRIBE_SALT') ?: 'adverton-default-salt';
    $sig  = substr(hash_hmac('sha256', strtolower(trim($email)), $salt), 0, 24);
    $e64  = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    return 'https://adverton.net/unsubscribe.php?e=' . $e64 . '&s=' . $sig;
}

function verifyUnsubscribeToken(string $e64, string $sig): ?string {
    $padded = $e64 . str_repeat('=', (4 - strlen($e64) % 4) % 4);
    $email  = base64_decode(strtr($padded, '-_', '+/'), true);
    if ($email === false) return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $salt = config('UNSUBSCRIBE_SALT') ?: 'adverton-default-salt';
    $expected = substr(hash_hmac('sha256', strtolower(trim($email)), $salt), 0, 24);
    return hash_equals($expected, $sig) ? $email : null;
}

// ------- Helpers -------

function htmlEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stripHtml(string $html): string {
    $t = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $t = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $t);
    $t = preg_replace('/<br\s*\/?>/i', "\n", $t);
    $t = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $t);
    return trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'));
}
