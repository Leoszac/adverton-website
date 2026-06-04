<?php
// Kickoff intake — answers to the 8-step wizard.
// One row per client (UNIQUE KEY uk_intake_client). Either a CRM user or
// the client themselves (via magic link) fills it; both paths funnel
// through crm_saveIntakeStep.
//
// Step → DB column mapping:
//   1  Business basics ─ display_name, tagline, story_short, story_long
//   2  Service area    ─ service_area_json
//   3  Services+hours  ─ services_json, hours_regular_json, emergency_24_7
//   4  Trust signals   ─ license_number, insurance_carrier, years_in_business,
//                        certifications_json, reviews_links_json
//   5  Visual          ─ photos_drive_url, brand_colors_json, brand_logo_path
//   6  Tone & goals    ─ competitors_admired_json, primary_goal
//   7  Template choice ─ template_choice
//   8  Review & submit ─ no save; flips status to 'ready_for_ai'

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_INTAKE_TOTAL_STEPS = 8;

// Whitelisted columns per step. Anything outside this list is ignored on save.
function crm_intakeStepColumns(int $step): array {
    static $map = [
        1 => ['display_name','tagline','story_short','story_long'],
        2 => ['service_area_json'],
        3 => ['services_json','hours_regular_json','emergency_24_7'],
        4 => ['license_number','insurance_carrier','years_in_business',
              'certifications_json','reviews_links_json'],
        5 => ['photos_drive_url','brand_colors_json','brand_logo_path'],
        6 => ['competitors_admired_json','primary_goal'],
        7 => ['template_choice'],
        8 => [], // review-only — no columns saved
    ];
    return $map[$step] ?? [];
}

function crm_intakeJsonColumns(): array {
    return [
        'service_area_json','services_json','hours_regular_json',
        'certifications_json','reviews_links_json',
        'brand_colors_json','competitors_admired_json',
        'ai_drafts_json',
    ];
}

// Fetch the intake row for a client. Decodes JSON columns into nested arrays
// (suffix `_decoded`). Returns null if no row exists (call crm_ensureIntake first).
function crm_getIntake(int $clientId): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM client_intake WHERE client_id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        foreach (crm_intakeJsonColumns() as $col) {
            $key = substr($col, 0, -5) . '_decoded';   // services_json → services_decoded
            $row[$key] = json_decode((string)($row[$col] ?? ''), true) ?: null;
        }
        return $row;
    } catch (Throwable $e) {
        error_log('[crm_getIntake] ' . $e->getMessage());
        return null;
    }
}

// Lazily create an empty intake row. Idempotent.
function crm_ensureIntake(int $clientId): void {
    try {
        $stmt = crm_db()->prepare(
            'INSERT IGNORE INTO client_intake (client_id, status, current_step) VALUES (?, "not_started", 1)'
        );
        $stmt->execute([$clientId]);
    } catch (Throwable $e) {
        error_log('[crm_ensureIntake] ' . $e->getMessage());
    }
}

// Persist the answers for one step. Validates step number, picks only whitelisted
// columns, encodes JSON where needed, advances current_step, flips status to
// 'in_progress' on the first save. Returns true on success.
function crm_saveIntakeStep(int $clientId, int $step, array $data): bool {
    if ($step < 1 || $step > CRM_INTAKE_TOTAL_STEPS) return false;
    crm_ensureIntake($clientId);
    $cols = crm_intakeStepColumns($step);
    if (!$cols) return true;  // step 8 is review-only

    $jsonCols = array_flip(crm_intakeJsonColumns());
    $sets = [];
    $params = [];
    foreach ($cols as $col) {
        if (!array_key_exists($col, $data)) continue;
        $v = $data[$col];
        if (isset($jsonCols[$col])) {
            $v = is_string($v) ? $v : json_encode($v);
        } elseif ($col === 'emergency_24_7') {
            $v = !empty($v) ? 1 : 0;
        } elseif ($col === 'years_in_business') {
            $v = ($v === '' || $v === null) ? null : (int)$v;
        } elseif ($col === 'primary_goal') {
            $allowed = ['more_calls','more_bookings','more_reviews','brand_awareness'];
            if (!in_array($v, $allowed, true)) continue;
        } elseif ($col === 'template_choice') {
            $allowed = ['trust_first','speed_first','story_first','seo_local'];
            if (!in_array($v, $allowed, true)) continue;
        } else {
            $v = ($v === '') ? null : (string)$v;
        }
        $sets[] = "{$col} = ?";
        $params[] = $v;
    }
    // Always advance current_step to max(existing, step+1)
    $sets[] = 'current_step = GREATEST(current_step, ?)';
    $params[] = min(CRM_INTAKE_TOTAL_STEPS, $step + 1);
    // Flip status from not_started → in_progress on first save
    $sets[] = "status = CASE WHEN status = 'not_started' THEN 'in_progress' ELSE status END";

    $params[] = $clientId;
    try {
        $stmt = crm_db()->prepare(
            'UPDATE client_intake SET ' . implode(', ', $sets) . ' WHERE client_id = ?'
        );
        return $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('[crm_saveIntakeStep] ' . $e->getMessage());
        return false;
    }
}

// Step the user back without losing data — only updates current_step.
// Useful so the "Back" button doesn't re-validate the step they were on.
function crm_setIntakeStep(int $clientId, int $step): bool {
    if ($step < 1 || $step > CRM_INTAKE_TOTAL_STEPS) return false;
    try {
        $stmt = crm_db()->prepare(
            'UPDATE client_intake SET current_step = ? WHERE client_id = ?'
        );
        return $stmt->execute([$step, $clientId]);
    } catch (Throwable $e) {
        error_log('[crm_setIntakeStep] ' . $e->getMessage());
        return false;
    }
}

// Final submit: status → ready_for_ai, kickoff_completed_at = now.
// Requires that all required core columns are filled (display_name + at least
// one service + template_choice). Returns ['ok'=>bool, 'missing'=>[...]].
function crm_markIntakeReady(int $clientId): array {
    $intake = crm_getIntake($clientId);
    if (!$intake) return ['ok' => false, 'missing' => ['intake row']];

    $missing = [];
    if (empty($intake['display_name']))    $missing[] = 'business name (step 1)';
    if (empty($intake['template_choice'])) $missing[] = 'template choice (step 7)';
    $svc = $intake['services_decoded'] ?? null;
    if (!is_array($svc) || count($svc) === 0) {
        $missing[] = 'at least one service (step 3)';
    }
    if ($missing) return ['ok' => false, 'missing' => $missing];

    try {
        $stmt = crm_db()->prepare(
            "UPDATE client_intake
             SET status = 'ready_for_ai',
                 kickoff_completed_at = COALESCE(kickoff_completed_at, NOW())
             WHERE client_id = ?"
        );
        $stmt->execute([$clientId]);
        return ['ok' => true, 'missing' => []];
    } catch (Throwable $e) {
        error_log('[crm_markIntakeReady] ' . $e->getMessage());
        return ['ok' => false, 'missing' => ['db error: ' . $e->getMessage()]];
    }
}

// Quick predicate for the client.php card badge.
function crm_intakeIsComplete(int $clientId): bool {
    $intake = crm_getIntake($clientId);
    if (!$intake) return false;
    return in_array($intake['status'], ['ready_for_ai','ai_generated','pending_approval','approved','provisioning_pending','deployed'], true);
}
