<?php
// One-shot: load post-audit + post-ebook nurture sequences (templates + sequences + steps).
// Idempotent: checks if a template/sequence with the same name exists, skips if so.
// DELETE after successful run.
//
// Usage:
//   curl -sX POST 'https://adverton.net/crm/_install-nurture-sequences.php?token=nurt-9k7m2q' -d ''

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/sequences.php';
require_once __DIR__ . '/lib/templates.php';

const ONE_SHOT_TOKEN = 'nurt-9k7m2q';

header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== ONE_SHOT_TOKEN) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')      { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$db = crm_db();

function upsertTemplate(string $name, string $subject, string $body): int {
    $db = crm_db();
    $stmt = $db->prepare('SELECT id FROM email_templates WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        $upd = $db->prepare('UPDATE email_templates SET subject = ?, body = ? WHERE id = ?');
        $upd->execute([$subject, $body, (int)$row['id']]);
        return (int)$row['id'];
    }
    $ins = $db->prepare('INSERT INTO email_templates (name, subject, body, created_by) VALUES (?,?,?,NULL)');
    $ins->execute([$name, $subject, $body]);
    return (int)$db->lastInsertId();
}

function upsertSequence(string $name, string $triggerEvent, string $triggerValue): int {
    $db = crm_db();
    $stmt = $db->prepare('SELECT id FROM sequences WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        $upd = $db->prepare('UPDATE sequences SET trigger_event = ?, trigger_value = ?, active = TRUE WHERE id = ?');
        $upd->execute([$triggerEvent, $triggerValue, (int)$row['id']]);
        return (int)$row['id'];
    }
    $ins = $db->prepare('INSERT INTO sequences (name, trigger_event, trigger_value, active, created_by) VALUES (?, ?, ?, TRUE, NULL)');
    $ins->execute([$name, $triggerEvent, $triggerValue]);
    return (int)$db->lastInsertId();
}

function replaceSequenceSteps(int $sequenceId, array $steps): void {
    $db = crm_db();
    $db->prepare('DELETE FROM sequence_steps WHERE sequence_id = ?')->execute([$sequenceId]);
    $ins = $db->prepare('INSERT INTO sequence_steps (sequence_id, step_order, delay_days, action, payload) VALUES (?,?,?,?,?)');
    $order = 0;
    foreach ($steps as $s) {
        $ins->execute([$sequenceId, ++$order, (int)$s['delay_days'], 'send_template', json_encode(['template_id' => (int)$s['template_id']])]);
    }
}

// ============================================================
// POST-AUDIT NURTURE — triggers when lead source = audit_auto
// ============================================================

$auditTpls = [
    upsertTemplate(
        'nurture_audit_d2_followup',
        "Quick note on your GBP audit, {first_name}",
        "Hi {first_name},\n\n" .
        "Saw you ran the GBP audit for {company} a couple days ago. The #1 fix in your report — that one's typically worth more than the next 4 combined. Most contractors gain 5-15 calls/month from fixing only that.\n\n" .
        "If anything in the report is unclear, hit reply and I'll walk you through it.\n\n" .
        "Leo Szachtman\nAdverton"
    ),
    upsertTemplate(
        'nurture_audit_d5_casestudy',
        "How a Phoenix HVAC went from 8 → 31 calls/week",
        "Hi {first_name},\n\n" .
        "Quick story: HVAC contractor in Phoenix, 6 trucks, 4 years in. We ran the same audit you just got, found the same kind of fixes — service categories missing, stale GBP posts, low review-response rate.\n\n" .
        "90 days later: 31 calls/week (was 8), \$52 cost per qualified lead, ~\$18,400 added monthly revenue. No new ad spend — just fixing the foundation.\n\n" .
        "Same playbook works for {company}. If you want to talk through how we'd apply it specifically, my calendar's at adverton.net (see Contact).\n\n" .
        "Leo"
    ),
    upsertTemplate(
        'nurture_audit_d10_softask',
        "Want me to walk through your audit live? 15 min",
        "Hi {first_name},\n\n" .
        "If your audit raised more questions than it answered, want to do a quick 15-min call where I walk through your specific report?\n\n" .
        "No pitch. Just the audit, your context, and what I'd do first if it were my business.\n\n" .
        "Reply with a day/time that works (any weekday 9am-5pm ET).\n\n" .
        "Leo"
    ),
    upsertTemplate(
        'nurture_audit_d18_value',
        "If now isn't the right time — 2 things to fix yourself this week",
        "Hi {first_name},\n\n" .
        "Totally get if now's not the moment to talk about marketing. Two things you can fix yourself this week from your audit, no agency needed:\n\n" .
        "1. Add ALL your service categories to your GBP (most contractors only have 1-2; you can have up to 10). Each one matches different searches.\n\n" .
        "2. Reply to your last 5 reviews — even a one-liner. Google's algorithm rewards response rate over review count.\n\n" .
        "Both take 10 minutes total and move the needle.\n\n" .
        "Leo"
    ),
    upsertTemplate(
        'nurture_audit_d30_breakup',
        "Closing your file, {first_name}",
        "Hi {first_name},\n\n" .
        "I'll stop bugging you after this one. If your audit is still useful or you want to pick this up later, just reply — the door's open.\n\n" .
        "Otherwise wishing {company} a great quarter.\n\n" .
        "Leo"
    ),
];

$auditSeqId = upsertSequence('Post-Audit Nurture (5 touches)', 'lead_created', 'audit_auto');
replaceSequenceSteps($auditSeqId, [
    ['delay_days' => 2,  'template_id' => $auditTpls[0]],
    ['delay_days' => 5,  'template_id' => $auditTpls[1]],
    ['delay_days' => 10, 'template_id' => $auditTpls[2]],
    ['delay_days' => 18, 'template_id' => $auditTpls[3]],
    ['delay_days' => 30, 'template_id' => $auditTpls[4]],
]);

// ============================================================
// POST-EBOOK NURTURE — triggers when lead source = ebook_growth_engine
// ============================================================

$ebookTpls = [
    upsertTemplate(
        'nurture_ebook_d4_question',
        "Question that came up most about the Growth Engine ebook",
        "Hi {first_name},\n\n" .
        "Hope the ebook's been useful. The question I get most after people read it: \"OK, but where do I actually start?\"\n\n" .
        "Honest answer: Chapter 5 (the GBP optimization checklist). It's the only chapter where 90 minutes of work moves the needle within 30 days — everything else compounds slower.\n\n" .
        "Reply if you want me to expand on it.\n\n" .
        "Leo Szachtman\nAdverton"
    ),
    upsertTemplate(
        'nurture_ebook_d10_tactic',
        "One specific tactic from the ebook (5 min to apply)",
        "Hi {first_name},\n\n" .
        "Pulling out one specific play from the ebook because it's the highest ROI / lowest effort thing in there:\n\n" .
        "After every job, send the customer one SMS: \"Hey, thanks for letting us help. If you have 30 seconds, the team would really appreciate a Google review: [link to your GBP review form].\"\n\n" .
        "That's it. Most contractors who do this consistently double their review count in 90 days. Reviews = trust = phone rings more.\n\n" .
        "Leo"
    ),
    upsertTemplate(
        'nurture_ebook_d18_casestudy',
        "Tampa contractor applied Chapter 7 — here's what happened",
        "Hi {first_name},\n\n" .
        "Plumber in Tampa read the ebook, applied the Chapter 7 framework (the one about Local Services Ads). 60 days later he was approved by Google and his cost per qualified lead dropped from \$140 (Angi) to \$38 (LSA).\n\n" .
        "If you're paying for Angi/Thumbtack/Networx leads right now, the chapter is worth re-reading.\n\n" .
        "Leo"
    ),
    upsertTemplate(
        'nurture_ebook_d30_audit',
        "Free GBP audit for {company}?",
        "Hi {first_name},\n\n" .
        "Reading the ebook is one thing — knowing where YOUR business specifically stands is another. We built a free 2-minute GBP audit tool that scores your profile and lists the top 5 fixes:\n\n" .
        "https://adverton.net/audit\n\n" .
        "No call. No signup. Report lands in your inbox in 24 hours.\n\n" .
        "Leo"
    ),
    upsertTemplate(
        'nurture_ebook_d45_softask',
        "Want to talk through how it'd work for {company}?",
        "Hi {first_name},\n\n" .
        "Last note from me. If anything in the ebook clicked and you want to talk through how Adverton would apply it specifically to {company}, I'm a 15-min call away.\n\n" .
        "No pitch deck. No commitment. Just your situation, what's working, what's not, and what I'd do first.\n\n" .
        "Reply with a day/time (any weekday 9am-5pm ET).\n\n" .
        "Leo"
    ),
];

$ebookSeqId = upsertSequence('Post-Ebook Nurture (5 touches)', 'lead_created', 'ebook_growth_engine');
replaceSequenceSteps($ebookSeqId, [
    ['delay_days' => 4,  'template_id' => $ebookTpls[0]],
    ['delay_days' => 10, 'template_id' => $ebookTpls[1]],
    ['delay_days' => 18, 'template_id' => $ebookTpls[2]],
    ['delay_days' => 30, 'template_id' => $ebookTpls[3]],
    ['delay_days' => 45, 'template_id' => $ebookTpls[4]],
]);

echo json_encode([
    'audit_sequence_id'    => $auditSeqId,
    'audit_template_ids'   => $auditTpls,
    'ebook_sequence_id'    => $ebookSeqId,
    'ebook_template_ids'   => $ebookTpls,
    'audit_steps'          => 5,
    'ebook_steps'          => 5,
    'total_templates'      => count($auditTpls) + count($ebookTpls),
], JSON_PRETTY_PRINT);
