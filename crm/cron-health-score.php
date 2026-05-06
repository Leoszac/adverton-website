<?php
// Daily — recompute health_score per active client.
//   0–100. Higher = healthier.

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/activities.php';

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

$db = crm_db();
$updated = 0;

// (Commissions disabled — no day-90 credit step.)
$d90 = 0;

$rows = $db->query(
    "SELECT * FROM clients WHERE status NOT IN ('cancelled')"
)->fetchAll();

foreach ($rows as $c) {
    $score = 80; // baseline

    if ($c['payment_status'] === 'past_due')   $score -= 30;
    if ($c['payment_status'] === 'failed')     $score -= 40;
    if ($c['status'] === 'paused')             $score -= 25;
    if ($c['status'] === 'past_due')           $score -= 20;

    // Days since last contact via lead.last_contacted_at
    if ($c['lead_id']) {
        $stmt = $db->prepare("SELECT last_contacted_at FROM leads WHERE id = ?");
        $stmt->execute([(int)$c['lead_id']]);
        $row = $stmt->fetch();
        $last = $row['last_contacted_at'] ?? null;
        if (!$last) {
            $score -= 15;
        } else {
            $days = (time() - strtotime($last)) / 86400;
            if ($days > 60)      $score -= 30;
            elseif ($days > 30)  $score -= 15;
            elseif ($days > 14)  $score -= 5;
            elseif ($days < 7)   $score += 10;
        }
    }

    // Add-ons indicate engagement (+5 each, max +15)
    $addons = $c['addons'] ? (json_decode((string)$c['addons'], true) ?: []) : [];
    $activeAddons = 0;
    foreach ($addons as $a) {
        if (empty($a['ended_at']) || $a['ended_at'] > date('Y-m-d')) $activeAddons++;
    }
    $score += min(15, $activeAddons * 5);

    // Renewed clients are healthy by definition
    if ($c['status'] === 'renewed') $score += 10;

    $score = max(0, min(100, $score));
    if ((int)($c['health_score'] ?? -1) !== $score) {
        $db->prepare("UPDATE clients SET health_score = ? WHERE id = ?")
           ->execute([$score, (int)$c['id']]);
        $updated++;
    }
}

echo "Health score · updated={$updated} · day90_credits={$d90}\n";
