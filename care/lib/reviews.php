<?php
// Adverton Care — review engine. Decoupled from the trigger: any source
// (one-tap from recent calls, CSV import, text-to-trigger, integration) just
// calls care_queueReview(); this engine sends the SMS + one reminder, using the
// Google review link already stored in client_intake.reviews_links_json.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/flows.php';   // pulls twilio + care helpers (care_sendSms, care_e164, ...)

// The client's active Care number (used as the SMS sender).
function care_clientNumber(int $clientId): ?string {
    try {
        $st = care_db()->prepare('SELECT twilio_number FROM care_numbers WHERE client_id = ? AND active = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$clientId]);
        $n = $st->fetchColumn();
        return $n ?: null;
    } catch (Throwable $e) { return null; }
}

// Google review link from the kickoff intake. Handles a few JSON shapes.
function care_reviewLink(int $clientId): ?string {
    try {
        $st = care_db()->prepare('SELECT reviews_links_json FROM client_intake WHERE client_id = ? LIMIT 1');
        $st->execute([$clientId]);
        $raw = (string)($st->fetchColumn() ?: '');
        if ($raw === '') return null;
        $j = json_decode($raw, true);
        if (!is_array($j)) return null;
        $google = null; $first = null;
        foreach ($j as $k => $v) {
            $url  = is_array($v) ? (string)($v['url'] ?? '') : (is_string($v) ? $v : '');
            $plat = is_array($v) ? strtolower((string)($v['platform'] ?? '')) : strtolower((string)$k);
            // Only ever trust real http(s) URLs — intake JSON isn't validated
            // elsewhere, so reject javascript:/data:/etc (it ends up in an href
            // AND is texted to customers).
            if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
            if ($first === null) $first = $url;
            if ($google === null && (strpos($plat, 'google') !== false || stripos($url, 'google') !== false || stripos($url, 'g.page') !== false)) $google = $url;
        }
        return $google ?: $first;
    } catch (Throwable $e) { return null; }
}

// Enqueue a review request. Dedupes (same client+phone within 30 days) and
// respects opt-out. $delayHours lets a job-done trigger wait a bit.
function care_queueReview(int $clientId, string $phone, ?string $name, string $source, int $delayHours = 0): array {
    $e164 = care_e164($phone);
    if (!$e164) return ['ok'=>false, 'error'=>'bad phone'];
    if (care_isOptedOut($e164)) return ['ok'=>false, 'error'=>'opted_out'];
    try {
        $dup = care_db()->prepare(
            "SELECT id FROM care_review_requests
             WHERE client_id = ? AND customer_phone = ?
               AND created_at > (NOW() - INTERVAL 30 DAY)
             LIMIT 1"
        );
        $dup->execute([$clientId, $e164]);
        if ($dup->fetchColumn()) return ['ok'=>false, 'error'=>'duplicate'];

        $sendAfter = $delayHours > 0 ? date('Y-m-d H:i:s', time() + $delayHours * 3600) : null;
        care_db()->prepare(
            'INSERT INTO care_review_requests (client_id, customer_phone, customer_name, source, status, send_after)
             VALUES (?, ?, ?, ?, "queued", ?)'
        )->execute([$clientId, $e164, ($name ?: null), $source, $sendAfter]);
        return ['ok'=>true, 'id'=>(int)care_db()->lastInsertId()];
    } catch (Throwable $e) {
        care_log('queueReview err: ' . $e->getMessage());
        return ['ok'=>false, 'error'=>$e->getMessage()];
    }
}

// Let the contractor confirm/fix their own Google review link from the
// dashboard. Writes the "google" key into client_intake.reviews_links_json.
function care_setReviewLink(int $clientId, string $url): bool {
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) return false;
    try {
        $st = care_db()->prepare('SELECT reviews_links_json FROM client_intake WHERE client_id = ? LIMIT 1');
        $st->execute([$clientId]);
        $raw = $st->fetchColumn();
        $j = ($raw !== false && $raw !== null) ? json_decode((string)$raw, true) : [];
        if (!is_array($j)) $j = [];
        $j['google'] = $url;
        if ($raw === false) {
            care_db()->prepare('INSERT INTO client_intake (client_id, reviews_links_json) VALUES (?, ?)')->execute([$clientId, json_encode($j)]);
        } else {
            care_db()->prepare('UPDATE client_intake SET reviews_links_json = ? WHERE client_id = ?')->execute([json_encode($j), $clientId]);
        }
        return true;
    } catch (Throwable $e) { care_log('setReviewLink err: ' . $e->getMessage()); return false; }
}

function care_reviewMessage(string $biz, ?string $name, string $link, bool $reminder): string {
    $hi = $name ? ('Hi ' . $name . '!') : 'Hi!';
    if ($reminder) {
        return "{$hi} Just a quick reminder — if you have a sec, a short review for {$biz} would mean a lot. {$link}";
    }
    return "{$hi} Thanks for choosing {$biz}! Would you mind leaving us a quick review? It really helps us out. {$link} (Reply STOP to opt out.)";
}

// The engine: send queued requests, then send one reminder to those sent 3+
// days ago. Returns counts. Called by cron-reviews.php.
function care_sendDueReviews(int $limit = 50, int $reminderDays = 3): array {
    $sent = 0; $reminded = 0; $failed = 0;
    $db = care_db();

    // 1) Send queued
    $rows = $db->prepare(
        'SELECT * FROM care_review_requests
         WHERE status = "queued" AND (send_after IS NULL OR send_after <= NOW())
         ORDER BY id ASC LIMIT ?'
    );
    $rows->bindValue(1, $limit, PDO::PARAM_INT);
    $rows->execute();
    foreach ($rows->fetchAll() as $r) {
        $clientId = (int)$r['client_id'];
        $careNum  = care_clientNumber($clientId);
        $link     = care_reviewLink($clientId);
        if (!$careNum || !$link) {
            $db->prepare('UPDATE care_review_requests SET status = "failed" WHERE id = ?')->execute([$r['id']]);
            $failed++; care_log("review #{$r['id']} failed: " . (!$careNum ? 'no Care number' : 'no review link'));
            continue;
        }
        $biz = care_clientName($clientId);
        $msg = care_reviewMessage($biz, $r['customer_name'] ?? null, $link, false);
        $res = care_sendSms($clientId, $careNum, (string)$r['customer_phone'], $msg, 'review');
        if ($res['ok']) {
            $db->prepare('UPDATE care_review_requests SET status = "sent", sent_at = NOW() WHERE id = ?')->execute([$r['id']]);
            $sent++;
        } else {
            $db->prepare('UPDATE care_review_requests SET status = "failed" WHERE id = ?')->execute([$r['id']]);
            $failed++;
        }
    }

    // 2) One reminder for those sent >= N days ago
    $rem = $db->prepare(
        'SELECT * FROM care_review_requests
         WHERE status = "sent" AND reminded_at IS NULL
           AND sent_at < (NOW() - INTERVAL ? DAY)
         ORDER BY id ASC LIMIT ?'
    );
    $rem->bindValue(1, $reminderDays, PDO::PARAM_INT);
    $rem->bindValue(2, $limit, PDO::PARAM_INT);
    $rem->execute();
    foreach ($rem->fetchAll() as $r) {
        $clientId = (int)$r['client_id'];
        $careNum  = care_clientNumber($clientId);
        $link     = care_reviewLink($clientId);
        if (!$careNum || !$link) continue;
        $biz = care_clientName($clientId);
        $msg = care_reviewMessage($biz, $r['customer_name'] ?? null, $link, true);
        $res = care_sendSms($clientId, $careNum, (string)$r['customer_phone'], $msg, 'review_reminder');
        if ($res['ok']) {
            $db->prepare('UPDATE care_review_requests SET status = "reminded", reminded_at = NOW() WHERE id = ?')->execute([$r['id']]);
            $reminded++;
        }
    }

    return ['sent'=>$sent, 'reminded'=>$reminded, 'failed'=>$failed];
}
