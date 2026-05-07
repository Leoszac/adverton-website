<?php
// CRM-side entry to the kickoff wizard.
// Same UI as /kickoff.php (the client-facing magic-link path), but:
//   - requires CRM login (founder | sales)
//   - the form posts to /crm/update.php (mode=intake_save), not back to itself
//
// /crm/client-kickoff.php?id=N&step=X

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/clients.php';
require_once __DIR__ . '/lib/intake.php';
require_once __DIR__ . '/lib/intake-wizard.php';

$user = crm_requireRole(['founder','sales']);

$clientId = (int)($_GET['id'] ?? 0);
$client = $clientId > 0 ? crm_getClient($clientId) : null;
if (!$client) {
    http_response_code(404);
    header('Location: /crm/clients.php');
    exit;
}

crm_ensureIntake($clientId);
$intake = crm_getIntake($clientId);

// Pick the step to render. URL ?step= takes priority; otherwise the
// resume-point stored in current_step.
$step = (int)($_GET['step'] ?? ($intake['current_step'] ?? 1));
$step = max(1, min(CRM_INTAKE_TOTAL_STEPS, $step));

intake_renderShellOpen($client, $intake, $step, 'crm');
intake_renderStep($step, $intake, '/crm/update.php', null, 'crm');
intake_renderShellClose();
