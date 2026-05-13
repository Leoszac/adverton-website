<?php
// Cron: re-scrubs cold_prospects that were marked 'clean' more than 30 days
// ago. The National DNC list updates daily — a number clean on import day
// may have been added since. This catches that drift.
//
// If a re-scrub flips a prospect that was already converted to a lead from
// 'clean' to 'blocked_*', that lead is in the calling pipeline and may have
// already been called. We fire a Discord alert so a human can decide.
//
// Schedule: daily at 3 AM ET (per CRON_TZ in the crontab):
//   0 3 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-dnc-rescrub.php
//
// PHP 7.4 compatible (cron runs via /usr/local/bin/php → 7.4).

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/dnc_scrub.php';

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    // Token-gated when hit over HTTP (mirrors cron-instantly-health.php pattern).
    $secret = crm_config('CRON_TICK_SECRET');
    $got    = $_GET['token'] ?? '';
    if (!$secret) { http_response_code(503); exit("CRON_TICK_SECRET not set\n"); }
    if (!hash_equals((string)$secret, (string)$got)) { http_response_code(403); exit("bad token\n"); }
}

$pdo = crm_db();

// Find prospects to re-scrub: clean + scrubbed > 30 days ago (or never).
$rows = $pdo->query(
    "SELECT id, phone, converted_lead_id
       FROM cold_prospects
      WHERE dnc_status = 'clean'
        AND (dnc_scrubbed_at IS NULL OR dnc_scrubbed_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
      LIMIT 5000"
)->fetchAll();

if (empty($rows)) {
    crm_dncLog('rescrub_cron: nothing due');
    echo "ok: 0 due\n";
    exit;
}

$phones = array_column($rows, 'phone');
$results = crm_dncScrubBatch($phones);

$upd = $pdo->prepare(
    "UPDATE cold_prospects
        SET dnc_status      = ?,
            dnc_scrubbed_at = NOW(),
            dnc_meta_json   = ?
      WHERE id = ?"
);

$newlyBlocked = 0;
$alertRows    = [];
foreach ($rows as $r) {
    $res = $results[$r['phone']] ?? ['status' => 'clean', 'meta' => null];
    $status = (string)$res['status'];
    $isBlocked = (strpos($status, 'blocked_') === 0);
    if ($isBlocked) $newlyBlocked++;

    $upd->execute([
        $status,
        $res['meta'] ? json_encode($res['meta'], JSON_UNESCAPED_SLASHES) : null,
        (int)$r['id'],
    ]);

    // Compliance alert: a converted lead just flipped to blocked.
    if ($isBlocked && !empty($r['converted_lead_id'])) {
        $alertRows[] = [
            'prospect_id' => (int)$r['id'],
            'lead_id'     => (int)$r['converted_lead_id'],
            'phone'       => (string)$r['phone'],
            'reason'      => $status,
        ];
    }
}

crm_dncLog("rescrub_cron: scrubbed=" . count($rows) . " newly_blocked={$newlyBlocked} alerts=" . count($alertRows));

// Fire Discord/Slack alert per flipped converted lead. Best-effort; cron
// doesn't block if the webhook is down.
if (!empty($alertRows)) {
    $webhookUrl = (string) crm_config('NEW_LEAD_WEBHOOK_URL', '');
    if ($webhookUrl !== '') {
        foreach ($alertRows as $a) {
            $text = "🚨 DNC compliance alert — converted lead just flipped to *{$a['reason']}*.\n"
                  . "Lead #{$a['lead_id']} (cold prospect #{$a['prospect_id']}, phone {$a['phone']}).\n"
                  . "If this lead has been called or sequenced, review for DNC violation exposure.\n"
                  . "https://adverton.net/crm/lead.php?id={$a['lead_id']}";
            $payload = json_encode(['text' => $text, 'content' => $text]);
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_POST              => true,
                CURLOPT_POSTFIELDS        => $payload,
                CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT_MS        => 2000,
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_NOSIGNAL          => 1,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        }
    }
}

echo "ok: scrubbed=" . count($rows) . " newly_blocked={$newlyBlocked} alerts=" . count($alertRows) . "\n";
