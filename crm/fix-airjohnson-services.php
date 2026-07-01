<?php
// One-shot corrective patch for Air Johnson (client_id=6).
//
// The prior patch edited only ai_drafts (copy), but the AUTHORITATIVE service
// list lives in client_intake.services_json (drives titles / slugs / which
// cards + pages exist). The two join by service NAME. This script re-syncs
// them:
//   - intake.services_json: rename "Furnace Cleaning" ->
//       "Heating and Air Conditioner Maintenance", add "Maintenance Plans",
//       and reorder so both maintenance items land in the home top-6.
//   - ai_drafts_json: ensure the maintenance service has a real emoji, and
//       align the About blurb "HVAC and boiler" -> "HVAC, air conditioning,
//       and boiler" (footer was already done).
//
// Usage (must be logged in as founder/sales):
//   /crm/fix-airjohnson-services.php            -> dry-run diff, no write
//   /crm/fix-airjohnson-services.php?confirm=1  -> apply + self-destruct

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$user = crm_requireRole(['founder', 'sales']);

const CLIENT_ID = 6;
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
header('Content-Type: text/plain; charset=utf-8');

// normalize: lowercase, "&" -> "and", collapse whitespace
$norm = function ($s) {
    $s = strtolower(trim((string)$s));
    $s = str_replace('&', 'and', $s);
    return preg_replace('/\s+/', ' ', $s);
};

$db = crm_db();
$row = $db->prepare("SELECT services_json, ai_drafts_json FROM client_intake WHERE client_id = ?");
$row->execute([CLIENT_ID]);
$data = $row->fetch(PDO::FETCH_ASSOC);
if (!$data) { exit("ABORT: client_id=" . CLIENT_ID . " not found.\n"); }

$services = json_decode((string)($data['services_json'] ?? ''), true);
$copy     = json_decode((string)($data['ai_drafts_json'] ?? ''), true);
if (!is_array($services)) { exit("ABORT: services_json did not decode.\n"); }
if (!is_array($copy))     { exit("ABORT: ai_drafts_json did not decode.\n"); }

$log = [];

// ---- INTAKE services_json ------------------------------------------------
// 1) rename Furnace Cleaning
foreach ($services as $i => $s) {
    if ($norm($s['name'] ?? '') === 'furnace cleaning') {
        $log[] = "intake: rename 'Furnace Cleaning' -> 'Heating and Air Conditioner Maintenance'";
        $services[$i]['name'] = 'Heating and Air Conditioner Maintenance';
        $services[$i]['description'] = 'Seasonal cleaning and tune-ups for heating and air conditioning systems.';
    }
}
// 2) add Maintenance Plans if missing
$hasMaint = false;
foreach ($services as $s) { if ($norm($s['name'] ?? '') === 'maintenance plans') { $hasMaint = true; break; } }
if (!$hasMaint) {
    $log[] = "intake: add service 'Maintenance Plans'";
    $services[] = ['name' => 'Maintenance Plans', 'description' => 'Year-round maintenance plans with priority scheduling and repair discounts.'];
} else {
    $log[] = "intake: 'Maintenance Plans' already present (skip)";
}
// 3) reorder so the home top-6 shows both maintenance items
$target = [
    'service and install furnaces'              => 1,
    'service and install boilers'               => 2,
    'service and install air conditioning'      => 3,
    'heating and air conditioner maintenance'   => 4,
    'maintenance plans'                         => 5,
    'commercial air conditioning'               => 6,
    'high efficiency filters'                   => 7,
    'service and install water heaters'         => 8,
];
// stable sort by target priority; unlisted (e.g. Humidifiers) keep original
// order at the end.
$decorated = [];
foreach ($services as $idx => $s) {
    $p = $target[$norm($s['name'] ?? '')] ?? (100 + $idx);
    $decorated[] = [$p, $idx, $s];
}
usort($decorated, function ($a, $b) { return ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]); });
$services = array_map(function ($d) { return $d[2]; }, $decorated);
$log[] = "intake order: " . implode(' | ', array_map(function ($s) { return $s['name'] ?? '?'; }, $services));

// ---- COPY ai_drafts_json -------------------------------------------------
// ensure the maintenance service has a real emoji (join lookup now resolves)
if (isset($copy['services']) && is_array($copy['services'])) {
    foreach ($copy['services'] as $i => $s) {
        if ($norm($s['name'] ?? '') === 'heating and air conditioner maintenance') {
            $ico = trim((string)($s['icon_emoji'] ?? ''));
            if ($ico === '' || $ico === '&#128295;') {
                $copy['services'][$i]['icon_emoji'] = '🔧';
                $log[] = "copy: set emoji 🔧 on Heating and Air Conditioner Maintenance";
            }
        }
        if ($norm($s['name'] ?? '') === 'maintenance plans') {
            $ico = trim((string)($s['icon_emoji'] ?? ''));
            if ($ico === '' || $ico === '&#128295;') {
                $copy['services'][$i]['icon_emoji'] = '🗓️';
                $log[] = "copy: set emoji 🗓️ on Maintenance Plans";
            }
        }
    }
}
// About blurb: match the footer (add air conditioning)
$ab = (string)($copy['about']['body_html'] ?? '');
if (stripos($ab, 'HVAC and boiler') !== false) {
    $copy['about']['body_html'] = str_ireplace('HVAC and boiler', 'HVAC, air conditioning, and boiler', $ab);
    $log[] = "copy: about blurb 'HVAC and boiler' -> 'HVAC, air conditioning, and boiler'";
} else {
    $log[] = "copy: about blurb — 'HVAC and boiler' not found (skip)";
}

// ---- Report / write ------------------------------------------------------
echo "Air Johnson (client_id=" . CLIENT_ID . ") — services re-sync fix\n";
echo "Mode: " . ($confirm ? "APPLY" : "DRY-RUN (add ?confirm=1 to write)") . "\n";
echo str_repeat('-', 60) . "\n";
foreach ($log as $l) echo " - " . $l . "\n";
echo str_repeat('-', 60) . "\n";
echo "Home top-6: " . implode(' | ', array_map(function ($s) { return $s['name'] ?? '?'; }, array_slice($services, 0, 6))) . "\n";
echo str_repeat('-', 60) . "\n";

if (!$confirm) { exit("DRY-RUN only. Nothing written. Re-run with ?confirm=1 to apply.\n"); }

$sJson = json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cJson = json_encode($copy,     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($sJson === false || $cJson === false) { exit("ABORT: json_encode failed. Nothing written.\n"); }

try {
    $up = $db->prepare("UPDATE client_intake SET services_json = ?, ai_drafts_json = ?, ai_generated_at = NOW() WHERE client_id = ?");
    $up->execute([$sJson, $cJson, CLIENT_ID]);
    if (function_exists('crm_logClientEvent')) {
        crm_logClientEvent(CLIENT_ID, (int)($user['id'] ?? 0), 'note',
            'Re-synced services_json with copy (rename + Maintenance Plans + order) via one-shot fix');
    }
} catch (Throwable $e) {
    exit("ABORT: DB write failed: " . $e->getMessage() . "\n");
}

echo "APPLIED. services_json + ai_drafts_json updated.\n";
@unlink(__FILE__);
echo "Script self-deleted. Preview: /crm/preview-internal.php?id=" . CLIENT_ID . "\n";
