<?php
// Daily cron — generates renewal alerts, upsell triggers, at-risk flags.
// Run via cPanel cron:  0 8 * * *  /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-client-triggers.php

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/activities.php';

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    $expected = crm_config('SEED_TOKEN');
    if (!$expected || !hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

$db = crm_db();
$today = date('Y-m-d');
$counters = ['renewal90' => 0, 'renewal30' => 0, 'auto_renewed' => 0, 'upsells' => 0, 'at_risk' => 0];

// Pull active-ish clients (anything not cancelled)
$rows = $db->query(
    "SELECT * FROM clients WHERE status IN ('onboarding','active','past_due','paused','renewed')"
)->fetchAll();

foreach ($rows as $c) {
    $clientId  = (int)$c['id'];
    $am        = $c['account_manager_id'] ? (int)$c['account_manager_id'] : null;
    $bizName   = $c['business_name'] ?: ('Client #' . $clientId);
    $startTs   = strtotime((string)$c['contract_start_at']);
    $endTs     = strtotime((string)$c['contract_end_at']);
    $now       = time();
    $daysIn    = (int)(($now - $startTs) / 86400);
    $daysToEnd = (int)(($endTs - $now) / 86400);
    $leadId    = $c['lead_id'] ? (int)$c['lead_id'] : null;

    // ---- Renewal at -90 days ----
    if ($daysToEnd === 90) {
        ensureTaskOnce($leadId, $am,
            "Renewal call con {$bizName}",
            date('Y-m-d 10:00:00', strtotime('+1 day')));
        crm_logClientEvent($clientId, null, 'renewal_notice', '90-day window opened');
        $counters['renewal90']++;
    }
    // ---- Renewal final reminder at -30 ----
    if ($daysToEnd === 30) {
        ensureTaskOnce($leadId, $am,
            "Confirmar renewal con {$bizName}",
            date('Y-m-d 10:00:00', strtotime('+1 day')));
        $counters['renewal30']++;
    }
    // ---- Auto-renewal at end of term ----
    if ($daysToEnd <= 0 && $c['status'] !== 'cancelled' && $c['status'] !== 'renewed') {
        // Roll contract_end forward 12 months, increment renewal_count, reset installment_count
        $newEnd = date('Y-m-d', strtotime($c['contract_end_at'] . ' +12 months'));
        crm_updateClient($clientId, [
            'contract_end_at'   => $newEnd,
            'renewal_count'     => (int)$c['renewal_count'] + 1,
            'installment_count' => 0,
            'status'            => 'renewed',
        ], null);
        crm_logClientEvent($clientId, null, 'renewed', "Auto-renewed for 12 months → {$newEnd}");
        $counters['auto_renewed']++;
    }

    // ---- Upsell triggers ----
    $upsells = [
        60  => ['code'=>'ai_voice',        'label'=>'AI Voice ($349)'],
        90  => ['code'=>'meta_or_yelp',    'label'=>'Meta ($199) or Yelp ($149)'],
        120 => ['code'=>'multi_location',  'label'=>'Multi-location ($199)'],
        180 => ['code'=>'nurture_stack',   'label'=>'Email/SMS nurture stack'],
    ];
    foreach ($upsells as $day => $u) {
        if ($daysIn === $day) {
            ensureTaskOnce($leadId, $am,
                "Pitch {$u['label']} a {$bizName} (day {$day})",
                date('Y-m-d 10:00:00', strtotime('+1 day')));
            $counters['upsells']++;
        }
    }

    // ---- At-risk: 30+ days without contact ----
    // Use lead's last_contacted_at if available
    if ($leadId) {
        $stmt = $db->prepare(
            "SELECT last_contacted_at FROM leads WHERE id = ? AND
             (last_contacted_at IS NULL OR last_contacted_at < DATE_SUB(NOW(), INTERVAL 30 DAY))"
        );
        $stmt->execute([$leadId]);
        if ($stmt->fetch()) {
            ensureTaskOnce($leadId, $am,
                "Check-in con {$bizName} — sin contacto 30d+",
                date('Y-m-d 10:00:00', strtotime('+1 day')));
            // Tag at-risk on the lead
            try {
                $tagId = (int)$db->query("SELECT id FROM tags WHERE name = 'at-risk' LIMIT 1")->fetch()['id'] ?? 0;
                if (!$tagId) {
                    $db->prepare("INSERT INTO tags (name, color) VALUES ('at-risk', '#dc2626')")->execute();
                    $tagId = (int)$db->lastInsertId();
                }
                $db->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)')
                   ->execute([$leadId, $tagId]);
            } catch (Throwable $e) { /* ignore */ }
            $counters['at_risk']++;
        }
    }
}

// ---- Unpaid payment-link reminders ----
// Clients with a checkout link sent N days ago and still no Stripe subscription
// → auto-task for the AM. 3d / 7d / 14d-stale.
$counters['unpaid_3d'] = 0; $counters['unpaid_7d'] = 0; $counters['unpaid_14d_stale'] = 0;
$unpaid = $db->query(
    "SELECT id, business_name, lead_id, account_manager_id, primary_email,
            stripe_checkout_sent_at,
            DATEDIFF(NOW(), stripe_checkout_sent_at) AS days_since_sent
     FROM clients
     WHERE stripe_checkout_sent_at IS NOT NULL
       AND (stripe_subscription_id IS NULL OR stripe_subscription_id = '')
       AND status NOT IN ('cancelled')"
)->fetchAll();

foreach ($unpaid as $c) {
    $daysAgo = (int)$c['days_since_sent'];
    $bizName = $c['business_name'] ?: ('Client #' . $c['id']);
    $am      = $c['account_manager_id'] ? (int)$c['account_manager_id'] : null;
    $leadId  = $c['lead_id'] ? (int)$c['lead_id'] : null;
    $cid     = (int)$c['id'];

    if ($daysAgo === 3) {
        ensureTaskOnce($leadId, $am,
            "💳 Resend payment link · {$bizName} (3d unpaid)",
            date('Y-m-d 10:00:00', strtotime('+1 day')));
        crm_logClientEvent($cid, null, 'note', '3-day unpaid reminder created');
        $counters['unpaid_3d']++;
    }
    if ($daysAgo === 7) {
        ensureTaskOnce($leadId, $am,
            "📞 Call {$bizName} · payment link sent 7 days ago, still unpaid",
            date('Y-m-d 10:00:00', strtotime('+1 day')));
        crm_logClientEvent($cid, null, 'note', '7-day unpaid reminder created');
        $counters['unpaid_7d']++;
    }
    if ($daysAgo >= 14) {
        // Tag client (and lead if any) as at-risk so they show up in dashboards
        try {
            $tagId = (int)($db->query("SELECT id FROM tags WHERE name = 'payment-stale' LIMIT 1")->fetch()['id'] ?? 0);
            if (!$tagId) {
                $db->prepare("INSERT INTO tags (name, color) VALUES ('payment-stale', '#dc2626')")->execute();
                $tagId = (int)$db->lastInsertId();
            }
            if ($leadId && $tagId) {
                $db->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)')
                   ->execute([$leadId, $tagId]);
            }
        } catch (Throwable $e) { /* ignore */ }
        ensureTaskOnce($leadId, $am,
            "🚨 Decide on {$bizName} · 14d unpaid · close out or escalate",
            date('Y-m-d 10:00:00', strtotime('+1 day')));
        $counters['unpaid_14d_stale']++;
    }
}

echo "Client triggers run · " . json_encode($counters) . "\n";

// Helper: create a task only if no open task with same title exists for the lead
function ensureTaskOnce(?int $leadId, ?int $userId, string $title, string $dueAt): void {
    try {
        $stmt = crm_db()->prepare(
            'SELECT 1 FROM tasks WHERE done_at IS NULL AND title = ? AND lead_id <=> ? LIMIT 1'
        );
        $stmt->execute([$title, $leadId]);
        if ($stmt->fetch()) return;
        crm_createTask([
            'lead_id'     => $leadId,
            'assigned_to' => $userId,
            'title'       => $title,
            'due_at'      => $dueAt,
        ]);
    } catch (Throwable $e) { error_log('[ensureTaskOnce] ' . $e->getMessage()); }
}
