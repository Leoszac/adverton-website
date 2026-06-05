<?php
// ONE-SHOT (READ-ONLY for now) — dump the email sequence of Batch 02 + 03 so
// we can see the exact structure before adding Variant B + fixing the
// adverton.com → adverton.net link. No changes are made.
//
//   /crm/_ab-setup.php?t=TOKEN
//
// DELETE THIS FILE after use.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/instantly.php';

if (($_GET['t'] ?? '') !== '32eccfe9664338c626af9ac29a26de303bc56223cfb4af3e') {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');

$camps = crm_instantlyListCampaigns(50);
$targets = [];
foreach ($camps['items'] as $c) {
    $name = (string)($c['name'] ?? '');
    if (preg_match('/Cold Batch 0?([23])\b/i', $name)) $targets[] = $c;
}

foreach ($targets as $c) {
    $cid = (string)($c['id'] ?? '');
    echo "================================================================\n";
    echo "CAMPAIGN: {$c['name']}  (id={$cid})\n";
    echo "================================================================\n";

    // Full campaign object (contains the sequence).
    $r = crm_instantlyRequest('GET', '/campaigns/' . rawurlencode($cid));
    if (!$r['ok']) {
        echo "  ERROR: http={$r['http']} {$r['error']}\n\n";
        continue;
    }
    $data = $r['data'] ?? [];

    // Show the top-level keys so we know where the sequence lives.
    echo "top-level keys: " . implode(', ', array_keys((array)$data)) . "\n\n";

    // Dump the sequence-ish fields verbatim (pretty) so we see step/variant
    // shape + the exact body format (HTML divs etc.).
    foreach (['sequences', 'sequence', 'steps', 'email_list'] as $k) {
        if (isset($data[$k])) {
            echo "--- {$k} ---\n";
            echo json_encode($data[$k], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    }
}
echo "Done (read-only). Paste this back so the A/B variant + link fix can be built precisely.\n";
