<?php
// One-shot patch: append a final "create_task" step to both nurture sequences
// so leads who never reply get a manual follow-up task instead of silently
// vanishing after the last email.
//
// Run via:  curl -sS 'https://adverton.net/crm/patch-nurture-tail-task.php?token=SEED_TOKEN'
// DELETE THIS FILE after running successfully.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/sequences.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = crm_config('SEED_TOKEN');
$got      = $_GET['token'] ?? '';
if (!$expected || !hash_equals((string)$expected, (string)$got)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$db = crm_db();

// Patch payload: same task title for both, delay 1 day after the last email
$PATCHES = [
    [
        'sequence_name' => 'Audit nurture (audit_auto)',
        'delay_days'    => 15,  // 1 day after the last email at day 14
        'task_title'    => 'Audit nurture done — manual follow-up call?',
        'task_notes'    => 'Lead went through the full audit nurture (14 days, 4 emails) without replying. Decide: warm direct outreach, switch to long-term drip, or close as cold.',
    ],
    [
        'sequence_name' => 'Ebook nurture (Growth Engine)',
        'delay_days'    => 22,  // 1 day after the last email at day 21
        'task_title'    => 'Ebook nurture done — manual follow-up call?',
        'task_notes'    => 'Lead went through the full ebook nurture (21 days, 4 emails) without replying. Decide: warm direct outreach, switch to long-term drip, or close as cold.',
    ],
];

foreach ($PATCHES as $p) {
    $stmt = $db->prepare('SELECT id FROM sequences WHERE name = ?');
    $stmt->execute([$p['sequence_name']]);
    $seq = $stmt->fetch();
    if (!$seq) {
        echo "  SKIP: sequence not found — {$p['sequence_name']}\n";
        continue;
    }
    $seqId = (int)$seq['id'];

    // Find current max step_order
    $stmt = $db->prepare('SELECT MAX(step_order) AS m FROM sequence_steps WHERE sequence_id = ?');
    $stmt->execute([$seqId]);
    $maxOrder = (int)($stmt->fetch()['m'] ?? 0);

    // Check if we already added a create_task tail step (idempotent)
    $stmt = $db->prepare(
        "SELECT id FROM sequence_steps WHERE sequence_id = ? AND action = 'create_task' LIMIT 1"
    );
    $stmt->execute([$seqId]);
    if ($stmt->fetch()) {
        echo "  SKIP: tail task already exists for {$p['sequence_name']}\n";
        continue;
    }

    $payload = json_encode([
        'title' => $p['task_title'],
        'notes' => $p['task_notes'],
    ]);
    $ins = $db->prepare(
        'INSERT INTO sequence_steps (sequence_id, step_order, delay_days, action, payload) VALUES (?,?,?,?,?)'
    );
    $ins->execute([$seqId, $maxOrder + 1, $p['delay_days'], 'create_task', $payload]);
    echo "  ✓ added tail step to {$p['sequence_name']} (order=" . ($maxOrder + 1) . ", +{$p['delay_days']}d)\n";
}

echo "\nDONE. DELETE THIS FILE NOW.\n";
