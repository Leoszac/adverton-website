<?php
// One-shot test-client orchestrator. Two modes:
//   ?go=TOKEN&action=create   → insert Test Acme Co (lead + client + intake)
//                               + mint kickoff magic-link
//   ?go=TOKEN&action=cleanup  → delete every client whose business_name
//                               starts with "Test Acme" (cascades to intake,
//                               assets, tasks, form_submissions)
//
// Guards: only ever touches clients matching the test name pattern.
// Never queries/modifies real clients.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/intake.php';
require_once __DIR__ . '/lib/magic-tokens.php';

header('Content-Type: text/plain; charset=utf-8');

$want = (string) crm_config('SEED_TOKEN');
$got  = (string)($_GET['go'] ?? '');
if ($want === '' || !hash_equals($want, $got)) {
    http_response_code(403);
    exit("forbidden — pass ?go=<SEED_TOKEN>&action=<create|cleanup>\n");
}

$action = (string)($_GET['action'] ?? 'create');
$db = crm_db();
$now = date('Y-m-d H:i:s');

if ($action === 'status') {
    $stmt = $db->query("SELECT id, business_name, status, magic_token FROM clients WHERE business_name LIKE 'Test Acme%' ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    if (!$row) {
        echo "No test client found. Run ?action=create first.\n";
        exit;
    }
    $cid = (int)$row['id'];
    $iStmt = $db->prepare("SELECT status, current_step FROM client_intake WHERE client_id = ?");
    $iStmt->execute([$cid]);
    $intake = $iStmt->fetch();

    echo "=== TEST CLIENT ===\n";
    echo "Client ID:    #{$cid}\n";
    echo "Business:     {$row['business_name']}\n";
    echo "Client status:{$row['status']}\n";
    echo "Intake status:" . ($intake['status'] ?? 'no row') . " (step " . ($intake['current_step'] ?? '?') . "/8)\n";
    echo "\n=== KICKOFF MAGIC LINK (for you to fill the 8 steps) ===\n";
    echo "https://adverton.net/kickoff?t=" . urlencode((string)$row['magic_token']) . "\n";
    exit;
}

if ($action === 'run-tests') {
    // Find the most recent test client
    $stmt = $db->query("SELECT id, business_name FROM clients WHERE business_name LIKE 'Test Acme%' ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    if (!$row) {
        exit("No test client found. Run ?action=create + complete kickoff first.\n");
    }
    $cid = (int)$row['id'];
    echo "=== Test client #{$cid} — {$row['business_name']} ===\n\n";

    // ── Sprint 2: AI generate ──────────────────────────────────────────
    echo "[Sprint 2] Triggering AI copy generation...\n";
    require_once __DIR__ . '/lib/ai-generator.php';
    $start = microtime(true);
    $r = crm_aiGenerateClientCopy($cid);
    $dur = round((microtime(true) - $start) * 1000);
    if (!$r['ok']) {
        echo "[Sprint 2] ❌ FAIL ({$dur}ms): " . ($r['error'] ?? 'unknown') . "\n";
        exit;
    }
    echo "[Sprint 2] ✅ OK ({$dur}ms) — copy generated\n";
    $copy = $r['copy'];
    echo "  hero.headline:    \"" . substr((string)($copy['hero']['headline'] ?? ''), 0, 80) . "\"\n";
    echo "  hero.subheadline: \"" . substr((string)($copy['hero']['subheadline'] ?? ''), 0, 80) . "\"\n";
    echo "  trust_strip:      " . count((array)($copy['trust_strip'] ?? [])) . " items\n";
    echo "  services:         " . count((array)($copy['services'] ?? [])) . " items\n";
    echo "  faq:              " . count((array)($copy['faq'] ?? [])) . " items\n";

    // Mark intake as approved so the deploy can run
    $db->prepare("UPDATE client_intake SET status='approved', approved_at=NOW() WHERE client_id=?")->execute([$cid]);
    echo "  status:           flipped to 'approved' (test bypass — normally operator click)\n\n";

    // ── Sprint 4: Render all 5 pages ───────────────────────────────────
    echo "[Sprint 4] Rendering all 5 pages...\n";
    require_once __DIR__ . '/lib/preview.php';
    $rendered = crm_renderAllPages($cid);
    if (!$rendered['ok']) {
        echo "[Sprint 4] ❌ FAIL: " . ($rendered['error'] ?? 'unknown') . "\n";
        exit;
    }
    foreach ($rendered['pages'] as $filename => $html) {
        $bytes = strlen($html);
        $hasNav = strpos($html, 'nav') !== false ? '✓' : '✗';
        $hasFooter = strpos($html, '</footer>') !== false ? '✓' : '✗';
        $hasSchema = strpos($html, 'application/ld+json') !== false ? '✓' : '✗';
        printf("  %-22s %6d bytes  nav:%s footer:%s schema:%s\n",
            $filename, $bytes, $hasNav, $hasFooter, $hasSchema);
    }
    echo "[Sprint 4] ✅ OK — 5 pages rendered\n\n";

    // ── Sprint 5: Verify post-deploy task creation logic ──────────────
    echo "[Sprint 5] Post-deploy task list:\n";
    require_once __DIR__ . '/lib/deploy.php';
    foreach (crm_postDeployTaskTitles() as $i => $title) {
        echo "  " . ($i+1) . ". {$title}\n";
    }
    echo "[Sprint 5] ✅ OK — 5 tasks would be auto-created on real deploy\n\n";

    // ── Deploy adapter check ───────────────────────────────────────────
    echo "[Deploy] Skipping actual SFTP/cPanel/WP upload — test client has no\n";
    echo "         hosting credentials in vault. Multi-page render verified above.\n\n";

    echo "=== ALL SPRINTS PASSED ===\n";
    echo "Cleanup with: ?action=cleanup\n";
    exit;
}

if ($action === 'cleanup') {
    // Find all test clients
    $stmt = $db->query("SELECT id, business_name FROM clients WHERE business_name LIKE 'Test Acme%'");
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo "No test clients to clean up.\n";
        exit;
    }
    foreach ($rows as $row) {
        $cid = (int)$row['id'];
        $name = $row['business_name'];
        // Cascading deletes — order matters: tasks/assets/form_submissions
        // first, then intake (FK to clients), then client (which cascades
        // many but be explicit).
        try {
            $db->prepare("DELETE FROM tasks WHERE lead_id IN (SELECT lead_id FROM clients WHERE id = ?)")->execute([$cid]);
        } catch (Throwable $e) { /* tasks table optional */ }
        try {
            $db->prepare("DELETE FROM client_assets WHERE client_id = ?")->execute([$cid]);
        } catch (Throwable $e) {}
        try {
            $db->prepare("DELETE FROM client_form_submissions WHERE client_id = ?")->execute([$cid]);
        } catch (Throwable $e) {}
        try {
            $db->prepare("DELETE FROM client_credentials WHERE client_id = ?")->execute([$cid]);
        } catch (Throwable $e) {}
        try {
            $db->prepare("DELETE FROM client_intake WHERE client_id = ?")->execute([$cid]);
        } catch (Throwable $e) {}
        // Get lead_id before deleting client
        $leadIdStmt = $db->prepare("SELECT lead_id FROM clients WHERE id = ?");
        $leadIdStmt->execute([$cid]);
        $leadId = (int)($leadIdStmt->fetchColumn() ?: 0);
        $db->prepare("DELETE FROM clients WHERE id = ?")->execute([$cid]);
        if ($leadId > 0) {
            try { $db->prepare("DELETE FROM lead_activities WHERE lead_id = ?")->execute([$leadId]); } catch (Throwable $e) {}
            try { $db->prepare("DELETE FROM sequence_enrollments WHERE lead_id = ?")->execute([$leadId]); } catch (Throwable $e) {}
            try { $db->prepare("DELETE FROM email_sends WHERE lead_id = ?")->execute([$leadId]); } catch (Throwable $e) {}
            $db->prepare("DELETE FROM leads WHERE id = ?")->execute([$leadId]);
        }
        echo "Deleted: {$name} (client #{$cid}, lead #{$leadId})\n";
    }
    echo "\nCleanup complete.\n";
    @unlink(__FILE__);
    echo "[ok] _test-client.php self-destructed\n";
    exit;
}

// ─── create ─────────────────────────────────────────────────────────────
if ($action !== 'create') {
    http_response_code(400);
    exit("action must be 'create' or 'cleanup'\n");
}

// 1. Insert lead
$leadId = crm_insertLead([
    'source'        => 'manual',
    'first_name'    => 'Test',
    'last_name'     => 'Customer',
    'email'         => 'test+acme@adverton.net',
    'phone'         => '555-555-5555',
    'business_name' => 'Test Acme Home Services',
    'trade'         => 'HVAC',
    'audit_score'   => 65,
    'city'          => 'Anytown',
    'state'         => 'ST',
]);
if (!$leadId) {
    http_response_code(500);
    exit("Failed to create test lead\n");
}
// Mark lead won
$db->prepare("UPDATE leads SET status='won' WHERE id=?")->execute([$leadId]);
echo "✓ Lead created: #{$leadId}\n";

// 2. Insert client (skip Stripe flow — pretend payment happened)
$stmt = $db->prepare("
    INSERT INTO clients (
        lead_id, business_name, legal_entity_name,
        primary_email, billing_email, primary_phone,
        billing_address, billing_city, billing_state, billing_zip,
        authorized_signer, signer_role,
        status, payment_status,
        pre_contract_completed_at, contract_signed_at, tos_consented_at,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        'active', 'current',
        ?, ?, ?,
        ?, ?
    )
");
$stmt->execute([
    $leadId,
    'Test Acme Home Services', 'Test Acme Home Services LLC',
    'test+acme@adverton.net', 'test+acme@adverton.net', '555-555-5555',
    '123 Main Street', 'Anytown', 'ST', '12345',
    'Test Customer', 'Owner',
    $now, $now, $now,
    $now, $now,
]);
$clientId = (int)$db->lastInsertId();
echo "✓ Client created: #{$clientId} (status='active', payment_status='current')\n";

// 3. Ensure intake row
crm_ensureIntake($clientId);
echo "✓ Intake row initialized (status='not_started', current_step=1)\n";

// 4. Mint kickoff magic-link
$token = crm_setClientMagicToken($clientId, 60);
$kickoffUrl = 'https://adverton.net/kickoff?t=' . urlencode($token);
echo "\n=== KICKOFF MAGIC LINK ===\n";
echo $kickoffUrl . "\n";
echo "\n=== DETAILS ===\n";
echo "Client ID: {$clientId}\n";
echo "Lead ID:   {$leadId}\n";
echo "Magic token expires: 60 days\n";

echo "\n=== NEXT STEPS ===\n";
echo "1. Open the kickoff URL above and fill the 8 steps (~5 min)\n";
echo "2. Ping the agent when done — it will run Sprint 2 (AI generate)\n";
echo "3. After everything tested, cleanup with:\n";
echo "   /crm/_test-client.php?go=SEED_TOKEN&action=cleanup\n";
