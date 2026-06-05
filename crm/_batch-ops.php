<?php
// ONE-SHOT — (1) mailbox health readout, (2) add a rotating SEED/test address
// to the draft cold-email campaigns so we can see inbox-vs-spam placement and
// the real rendered message.
//
//   Dry-run (default): prints mailbox health + the seed plan. No changes.
//   ?confirm=1:         adds the seed lead to Batch 02 + Batch 03.
//
//   /crm/_batch-ops.php?t=TOKEN
//   /crm/_batch-ops.php?t=TOKEN&confirm=1
//
// DELETE THIS FILE after use.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/instantly.php';

if (($_GET['t'] ?? '') !== '389db08aa5c75fc1b9cc7986c8a63d0ff09d599017eb8356') {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
$confirm = ($_GET['confirm'] ?? '') === '1';

// Rotating seed pool (one of mine per batch, never the same twice in a row).
$SEED_POOL = [
    'szachtman.leandro@gmail.com',
    'leo@szaccapital.com',
    'adverton.arg@gmail.com',
    'szac.capital@gmail.com',
];
// Which seed goes in which batch (by batch number). Rotates through the pool.
$seedFor = function (int $batchNo) use ($SEED_POOL) {
    return $SEED_POOL[($batchNo - 1) % count($SEED_POOL)];
};

echo "=== Batch ops ===\n" . ($confirm ? "MODE: CONFIRM (adding seeds)\n\n" : "MODE: DRY-RUN (no changes)\n\n");

// ── 1) MAILBOX HEALTH ──────────────────────────────────────────────
echo "── Mailbox health ──\n";
$snap = crm_instantlyAccountsSnapshot();
if ($snap['error']) {
    echo "  ERROR: {$snap['error']}\n";
} else {
    foreach ($snap['items'] as $m) {
        printf("  %-32s status=%-8s warmup=%-8s score=%-3d%s\n",
            $m['email'], $m['status_label'], $m['warmup_label'], $m['warmup_score'],
            $m['setup_pending'] ? '  setup_pending' : '');
    }
    echo "  (warmup score 100 = fully warmed; status active = sending OK; warmup 'banned'/'reconnect_required' = problem)\n";
}
echo "\n";

// ── 2) SEED PLAN ───────────────────────────────────────────────────
$camps = crm_instantlyListCampaigns(50);
$targets = []; // batchNo => campaign
foreach ($camps['items'] as $c) {
    $name = (string)($c['name'] ?? '');
    if (preg_match('/Cold Batch 0?(\d+)/i', $name, $mm)) {
        $targets[(int)$mm[1]] = $c;
    }
}

// Per-batch merge vars so the rendered email looks real.
$batchVars = [
    2 => ['trade_plural' => 'HVAC companies', 'city' => 'Columbus', 'state' => 'OH'],
    3 => ['trade_plural' => 'electricians',   'city' => 'Columbus', 'state' => 'OH'],
];

echo "── Seed plan (Batch 02 + 03) ──\n";
foreach ([2, 3] as $bn) {
    if (!isset($targets[$bn])) { echo "  Batch 0{$bn}: campaign not found\n"; continue; }
    $c = $targets[$bn];
    $seed = $seedFor($bn);
    echo "  Batch 0{$bn} (\"{$c['name']}\") → seed {$seed}\n";

    if ($confirm) {
        $vars = array_merge([
            'first_name'   => 'Leo',
            'last_name'    => 'Seed',
            'company_name' => 'Adverton QA',
        ], $batchVars[$bn] ?? []);
        $r = crm_instantlyAddLead((string)$c['id'], $seed, $vars);
        if ($r['ok']) {
            echo "    ADDED ok. id=" . (is_array($r['data']) ? ($r['data']['id'] ?? '?') : '?') . "\n";
        } else {
            echo "    ADD FAILED: http={$r['http']} error=\"{$r['error']}\" raw="
               . substr(json_encode($r['data']), 0, 250) . "\n";
        }
    }
}
echo "\n";

echo $confirm
    ? "Done. Seeds added — check those inboxes (and SPAM folders) once the campaigns send.\n"
    : "Dry-run. Re-run with &confirm=1 to add the seeds.\n";
