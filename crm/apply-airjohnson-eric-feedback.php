<?php
// One-shot patch: apply Eric's requested content changes to Air Johnson
// (client_id=6) draft copy (client_intake.ai_drafts_json).
//
// Surgical: decodes the FULL existing JSON and mutates only the target
// fields — preserves detail_html / locations / theme that the seo-local
// template needs (the manual draft-editor would strip those).
//
// Usage (must be logged in as founder/sales):
//   /crm/apply-airjohnson-eric-feedback.php            -> dry-run diff, no write
//   /crm/apply-airjohnson-eric-feedback.php?confirm=1  -> apply + self-destruct
//
// Idempotent: re-running matches on current text, so already-applied
// changes are skipped. Self-deletes after a confirmed, successful write.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$user = crm_requireRole(['founder', 'sales']);

const CLIENT_ID = 6;
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

header('Content-Type: text/plain; charset=utf-8');

$stmt = crm_db()->prepare("SELECT ai_drafts_json FROM client_intake WHERE client_id = ?");
$stmt->execute([CLIENT_ID]);
$raw = $stmt->fetchColumn();
if ($raw === false || $raw === null || $raw === '') {
    exit("ABORT: client_id=" . CLIENT_ID . " has no ai_drafts_json.\n");
}
$copy = json_decode((string)$raw, true);
if (!is_array($copy)) {
    exit("ABORT: could not decode ai_drafts_json (" . json_last_error_msg() . ").\n");
}

$log = [];
$changed = false;

// Helper: case/space-tolerant compare of a service name.
$norm = fn($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));

$services = isset($copy['services']) && is_array($copy['services']) ? $copy['services'] : [];

// ---- #1: AC service -> mention air conditioners, air conditioning, heat pumps
foreach ($services as $i => $svc) {
    if ($norm($svc['name'] ?? '') === $norm('Service & Install Air Conditioning')) {
        $new = '<p>We service and install air conditioners, air conditioning systems, '
             . 'and heat pumps for residential and commercial customers, from full '
             . 'replacements to fast repairs.</p>';
        if (($svc['description_html'] ?? '') !== $new) {
            $log[] = "#1 AC service description -> adds heat pumps";
            $services[$i]['description_html'] = $new;
            $changed = true;
        } else { $log[] = "#1 AC service already updated (skip)"; }
    }
}

// ---- #2: rename "Furnace Cleaning" -> "Heating and Air Conditioner Maintenance"
foreach ($services as $i => $svc) {
    if ($norm($svc['name'] ?? '') === $norm('Furnace Cleaning')) {
        $log[] = "#2 rename 'Furnace Cleaning' -> 'Heating and Air Conditioner Maintenance'";
        $services[$i]['name'] = 'Heating and Air Conditioner Maintenance';
        $services[$i]['description_html'] =
            '<p>We clean and tune up your heating and air conditioning systems each '
          . 'season so they start reliably, run efficiently, and last longer.</p>';
        // Update the seo-local per-service detail page copy too, if present.
        if (array_key_exists('detail_html', $svc)) {
            $services[$i]['detail_html'] =
                '<p>Seasonal maintenance keeps your heating and cooling equipment safe '
              . 'and efficient. We inspect, clean, and tune your furnace, boiler, and air '
              . 'conditioning system, catch small problems before they become breakdowns, '
              . 'and help your equipment last longer.</p>';
        }
        $changed = true;
    }
}

// ---- #3: add "Maintenance Plans" service (home + services section) if missing
$hasMaint = false;
foreach ($services as $svc) {
    if ($norm($svc['name'] ?? '') === $norm('Maintenance Plans')) { $hasMaint = true; break; }
}
if (!$hasMaint) {
    $log[] = "#3 add new service 'Maintenance Plans'";
    $newSvc = [
        'name'             => 'Maintenance Plans',
        'description_html' => '<p>Join a maintenance plan and we\'ll keep your heating '
                            . 'and cooling systems tuned up year-round, with priority '
                            . 'scheduling and discounts on repairs.</p>',
        'icon_emoji'       => '🗓️',
    ];
    // seo-local services carry a detail_html for their own page — match the shape
    // of the existing services so the generated service page isn't blank.
    $sample = $services[0] ?? [];
    if (array_key_exists('detail_html', $sample)) {
        $newSvc['detail_html'] =
            '<p>A maintenance plan is the easiest way to protect your heating and cooling '
          . 'investment. Members get scheduled seasonal tune-ups, priority booking when '
          . 'something goes wrong, and discounts on repairs — so your system stays '
          . 'reliable all year.</p>';
    }
    $services[] = $newSvc;
    $changed = true;
} else {
    $log[] = "#3 'Maintenance Plans' already present (skip)";
}

$copy['services'] = $services;

// ---- #4: footer_blurb -> include air conditioning in the license/service line
$fb = (string)($copy['footer_blurb'] ?? '');
if (stripos($fb, 'HVAC and boiler') !== false) {
    $log[] = "#4 footer -> 'HVAC and boiler' becomes 'HVAC, air conditioning, and boiler'";
    $copy['footer_blurb'] = str_ireplace('HVAC and boiler', 'HVAC, air conditioning, and boiler', $fb);
    $changed = true;
} elseif (stripos($fb, 'air conditioning') !== false) {
    $log[] = "#4 footer already mentions air conditioning (skip)";
} else {
    $log[] = "#4 WARNING: footer text 'HVAC and boiler' not found — footer left unchanged, review manually. Current: " . $fb;
}

// ---- #5 + #6: the "my heat stopped working" FAQ
$faq = isset($copy['faq']) && is_array($copy['faq']) ? $copy['faq'] : [];
foreach ($faq as $i => $item) {
    $q = (string)($item['question'] ?? '');
    if (stripos($q, 'my heat just stopped working') !== false) {
        $log[] = "#5 FAQ question -> 'My heat or air just stopped working ...'";
        $faq[$i]['question'] = 'My heat or air just stopped working — what should I do right now?';
        $log[] = "#6 FAQ answer -> thermostat/troubleshooting now covers heat AND AC";
        $faq[$i]['answer_html'] =
            '<p>Check your thermostat first — make sure it is set to the right mode '
          . '(heat or cool) and the temperature is set past the current room temperature. '
          . 'Then check your furnace or air-handler filter; a completely clogged filter can '
          . 'shut the system down. Also verify the circuit breaker for the system has not '
          . 'tripped. If none of that fixes it, call us. We are available 24/7 for heating '
          . 'and cooling emergencies.</p>';
        $changed = true;
    }
}
$copy['faq'] = $faq;

// ---- Report
echo "Air Johnson (client_id=" . CLIENT_ID . ") — Eric feedback patch\n";
echo "Mode: " . ($confirm ? "APPLY" : "DRY-RUN (add ?confirm=1 to write)") . "\n";
echo str_repeat('-', 60) . "\n";
foreach ($log as $line) echo " - " . $line . "\n";
echo str_repeat('-', 60) . "\n";
echo "Services now: " . implode(' | ', array_map(fn($s) => $s['name'] ?? '?', $copy['services'])) . "\n";
echo str_repeat('-', 60) . "\n";

if (!$changed) {
    exit("No changes needed — everything already applied.\n");
}
if (!$confirm) {
    exit("DRY-RUN only. Nothing written. Re-run with ?confirm=1 to apply.\n");
}

$json = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    exit("ABORT: json_encode failed (" . json_last_error_msg() . "). Nothing written.\n");
}

try {
    $up = crm_db()->prepare(
        "UPDATE client_intake SET ai_drafts_json = ?, ai_generated_at = NOW() WHERE client_id = ?"
    );
    $up->execute([$json, CLIENT_ID]);
    if (function_exists('crm_logClientEvent')) {
        crm_logClientEvent(CLIENT_ID, (int)($user['id'] ?? 0), 'note',
            "Applied Eric's website feedback (services, footer, FAQ) via one-shot patch");
    }
} catch (Throwable $e) {
    exit("ABORT: DB write failed: " . $e->getMessage() . "\n");
}

echo "APPLIED. ai_drafts_json updated.\n";

// Self-destruct so this one-shot script never lingers on the server.
@unlink(__FILE__);
echo "Script self-deleted. Preview: /crm/preview-internal.php?id=" . CLIENT_ID . "\n";
