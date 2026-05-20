<?php
// One-shot: rewrite the Post-Ebook nurture flow (v2 — story + hard CTA copy).
// Upserts the 5 ebook templates by name (UPDATE in place) and re-asserts the
// sequence + steps so it's self-healing. Idempotent. Self-destructs on success.
//
// Run:  https://adverton.net/crm/_install-nurture-v2.php?go=nurt-v2-k9w3xq

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const ONE_SHOT_TOKEN = 'nurt-v2-k9w3xq';

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals(ONE_SHOT_TOKEN, (string)($_GET['go'] ?? ''))) {
    http_response_code(403);
    exit("forbidden — pass ?go=<token>\n");
}

$db = crm_db();

function upsertTemplate(string $name, string $subject, string $body): array {
    $db = crm_db();
    $stmt = $db->prepare('SELECT id FROM email_templates WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) {
        $upd = $db->prepare('UPDATE email_templates SET subject = ?, body = ? WHERE id = ?');
        $upd->execute([$subject, $body, (int)$row['id']]);
        return ['id' => (int)$row['id'], 'action' => 'updated'];
    }
    $ins = $db->prepare('INSERT INTO email_templates (name, subject, body, created_by) VALUES (?,?,?,NULL)');
    $ins->execute([$name, $subject, $body]);
    return ['id' => (int)$db->lastInsertId(), 'action' => 'created'];
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

echo "── Post-Ebook nurture v2 (story + hard CTA) ──────────────\n\n";

// ============================================================
// POST-EBOOK NURTURE — triggers when lead source = ebook_growth_engine
// ============================================================

$t1 = upsertTemplate(
    'nurture_ebook_d4_question',
    "The one chapter I'd read first, {first_name}",
    "Hi {first_name},\n\n" .
    "A contractor I know — call him Dave, runs a 3-truck HVAC shop — downloaded a guide just like the one you grabbed. It sat in his inbox for a month. He finally opened it on a rained-out Tuesday with nothing else to do.\n\n" .
    "He read one chapter. Applied one fix that afternoon. Three weeks later he told me his phone was ringing enough that he stopped answering it himself and put his oldest tech on it.\n\n" .
    "The chapter? Chapter 5 — the Google Business Profile checklist. It's the only part of the ebook where 90 minutes of work can show up as real phone calls inside a month. Everything else compounds slower.\n\n" .
    "So here's what I'd do if I were you: open Chapter 5 today, not someday. It's the fastest win in the whole book.\n\n" .
    "Leo from Adverton\n\n" .
    "P.S. Want more calls without spending your Tuesday nights on it? That's exactly what we do for contractors — book a free 15-minute call and I'll show you how it'd work for {company}: https://calendly.com/meet-adverton/15"
);

$t2 = upsertTemplate(
    'nurture_ebook_d10_tactic',
    "The 30-second text that doubles your reviews",
    "Hi {first_name},\n\n" .
    "Quick one. Out of everything in the ebook, this is the highest payoff for the least effort — so I'm pulling it out so you don't miss it.\n\n" .
    "After every finished job, send the customer one text:\n\n" .
    "\"Hey [name] — thanks for trusting us with that. If you've got 30 seconds, a quick Google review really helps our small crew. Here's the link: [your review link]\"\n\n" .
    "That's it. No app, no system. A roofer I worked with started doing this and went from 11 reviews to 40-something in a single season. His words: \"It's the only marketing I've ever done that I can actually feel.\"\n\n" .
    "Here's the bigger picture though. Reviews are just one of three things that decide whether a contractor's phone rings:\n\n" .
    "- A website built to turn a click into a booked call — not just a pretty page\n" .
    "- Reviews that make a stranger trust you before they dial\n" .
    "- Ads that put you in front of people ready to hire right now\n\n" .
    "Do all three well and the calls don't stop. Most contractors do one, badly, in whatever time is left after a 10-hour day.\n\n" .
    "We do all three — done for you — for \$799 a month. Want to see what that looks like for {company}? Grab a free 15-minute call with me: https://calendly.com/meet-adverton/15\n\n" .
    "Leo from Adverton"
);

$t3 = upsertTemplate(
    'nurture_ebook_d18_casestudy',
    "He was paying \$140 a lead. Then he stopped.",
    "Hi {first_name},\n\n" .
    "Want to tell you about a plumber — two guys, one van, working out of a garage.\n\n" .
    "When we met, he was buying leads off one of those pay-per-lead apps. \$130–\$150 every time the phone buzzed, half of them tire-kickers, and he was competing with four other plumbers for the same job before he'd even said hello.\n\n" .
    "He felt like he was renting his own business back from someone else.\n\n" .
    "So we built him something he actually owned:\n\n" .
    "- Local Services Ads — the \"Google Guaranteed\" green badge — so he showed up first for people searching to hire\n" .
    "- A website built to do one job: turn that click into a phone call, fast, on a phone screen\n" .
    "- A Business Profile and a steady stream of reviews so he looked like the obvious choice\n\n" .
    "A couple months in, most of his work came from people who searched, saw the badge, saw the reviews, called him, and landed on a site that made booking easy. Leads that were his — not rented, not split four ways.\n\n" .
    "That's the picture I'd love for {company}: a phone that rings with your own customers, on repeat.\n\n" .
    "If you want to see how we'd build that for you, grab a free 15-minute call here: https://calendly.com/meet-adverton/15\n\n" .
    "Leo from Adverton"
);

$t4 = upsertTemplate(
    'nurture_ebook_d30_audit',
    "Reading about it vs. knowing where YOU stand",
    "Hi {first_name},\n\n" .
    "The ebook tells you what good looks like. It can't tell you where {company} actually stands today — and that gap is where most contractors get stuck.\n\n" .
    "So we built a free tool that closes it.\n\n" .
    "Drop your business in here and in about 24 hours you get a plain-English scorecard: where your Google presence is strong, where it's leaking calls, and the top 5 fixes ranked by what moves the needle fastest.\n\n" .
    "https://adverton.net/audit\n\n" .
    "No call. No signup. No salesperson. It's the same audit we run before taking on any new contractor — except you get it free and owe us nothing.\n\n" .
    "One contractor told me the audit was \"the first time anyone showed me my own business the way Google sees it.\" That's the point.\n\n" .
    "Two minutes to start it. Worth it.\n\n" .
    "Leo from Adverton\n\n" .
    "P.S. Want more calls without doing it yourself? Book a free 15-minute call and I'll walk you through exactly how we'd grow {company}: https://calendly.com/meet-adverton/15"
);

$t5 = upsertTemplate(
    'nurture_ebook_d45_softask',
    "Last one from me, {first_name}",
    "Hi {first_name},\n\n" .
    "This is the last email in this series, so I'll be straight with you.\n\n" .
    "You downloaded the Growth Engine ebook because something about your marketing isn't where you want it — not enough calls, too much spent on leads that go nowhere, or a Google presence that doesn't match how good your actual work is.\n\n" .
    "Reading about the fix and having it handled are two very different things. Most contractors I talk to don't have a marketing problem — they have a \"no time to do the marketing\" problem. They're on a roof or under a sink, not writing review requests and tuning ad campaigns.\n\n" .
    "That's the whole reason Adverton exists: \$799 a month, and the website, the reviews, and the ads are simply handled — by us, like a marketing team you don't have to hire.\n\n" .
    "If that's worth 15 minutes, here's my calendar: https://calendly.com/meet-adverton/15\n\n" .
    "If the timing's not right, no hard feelings — keep the ebook, it's yours. But if you've read this far, the timing might be more right than you think.\n\n" .
    "Leo from Adverton\n\n" .
    "P.S. The call is 15 minutes and it's me, not a sales rep. Worst case you walk away with one or two things to fix yourself. Best case, we take the whole headache off your plate."
);

foreach (['d4'=>$t1,'d10'=>$t2,'d18'=>$t3,'d30'=>$t4,'d45'=>$t5] as $k => $r) {
    echo "  template {$k}: #{$r['id']} {$r['action']}\n";
}

$seqId = upsertSequence('Post-Ebook Nurture (5 touches)', 'lead_created', 'ebook_growth_engine');
replaceSequenceSteps($seqId, [
    ['delay_days' => 4,  'template_id' => $t1['id']],
    ['delay_days' => 10, 'template_id' => $t2['id']],
    ['delay_days' => 18, 'template_id' => $t3['id']],
    ['delay_days' => 30, 'template_id' => $t4['id']],
    ['delay_days' => 45, 'template_id' => $t5['id']],
]);
echo "  sequence #{$seqId} 'Post-Ebook Nurture (5 touches)' — 5 steps re-asserted (d4/10/18/30/45)\n\n";

$created = count(array_filter([$t1,$t2,$t3,$t4,$t5], fn($r) => $r['action'] === 'created'));
if ($created > 0) {
    echo "⚠️  {$created} template(s) were CREATED, not updated — names may have drifted. NOT self-destructing; review.\n";
    exit;
}

echo "All 5 ebook templates updated in place. Sequence healthy.\n";
if (@unlink(__FILE__)) {
    echo "Self-destructed: " . __FILE__ . " removed.\n";
} else {
    echo "Could not unlink — remove manually.\n";
}
