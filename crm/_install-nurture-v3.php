<?php
// One-shot: rewrite the Post-Audit nurture flow (v3 — story + hard CTA copy).
// Upserts the 5 audit templates by name (UPDATE in place) and re-asserts the
// sequence + steps so it's self-healing. Idempotent. Self-destructs on success.
//
// Run:  https://adverton.net/crm/_install-nurture-v3.php?go=nurt-v3-m4q8zt

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const ONE_SHOT_TOKEN = 'nurt-v3-m4q8zt';

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

echo "── Post-Audit nurture v3 (story + hard CTA) ──────────────\n\n";

// ============================================================
// POST-AUDIT NURTURE — triggers when lead source = audit_auto
// ============================================================

$t1 = upsertTemplate(
    'nurture_audit_d2_followup',
    "{first_name}, your audit found the problem. Here's who fixes it.",
    "Hi {first_name},\n\n" .
    "A couple days ago you ran the audit for {company} and got back a scorecard with your top 5 fixes. Good — most contractors never look this honestly at their own marketing.\n\n" .
    "But here's the trap I see all the time: the audit gets opened, nodded at, and then it sits in an inbox. Three months later the calls still aren't coming, and the report's still sitting there.\n\n" .
    "A contractor I worked with — call him Ray, ran a small electrical shop — did exactly that. Audit, nod, inbox. He finally called me first thing on a Monday, after spending the weekend watching a competitor he'd never heard of show up above him on every search. Within a few weeks we'd handled his #1 issue, rebuilt his site to actually turn visits into calls, and pointed his ads at people ready to hire. The competitor stopped being a problem.\n\n" .
    "Your audit already did the hard part — it found what's wrong. The only thing between that report and a phone that rings more is someone actually doing the work.\n\n" .
    "That's the whole job we do for contractors: website, reviews, and ads, handled. Want me to walk through your specific report and what I'd fix first? Grab a free 15-minute call: https://calendly.com/meet-adverton/15\n\n" .
    "Leo from Adverton"
);

$t2 = upsertTemplate(
    'nurture_audit_d5_casestudy',
    "The HVAC guy who thought great work was enough",
    "Hi {first_name},\n\n" .
    "Quick story. HVAC contractor, 6 trucks, four years in — call him Marcus. Great installs, loyal customers, the kind of guy who figured the work would speak for itself.\n\n" .
    "For a while it did. Then a slow stretch hit, and he realized \"the work speaks for itself\" only works if enough people can hear it.\n\n" .
    "His audit looked a lot like yours probably does: a solid business with a leaky marketing foundation. Service categories missing off his profile. A website that looked fine but didn't push anyone to pick up the phone. Ad money going out the door with nobody really steering it.\n\n" .
    "So we fixed the foundation — all three parts of it: a site built to turn a click into a call, a Business Profile working every angle, and ad budget pointed only at people actively searching to hire. Reviews started stacking up on their own once we made it easy.\n\n" .
    "A season later, the slow stretches don't scare him. Same trucks, same crew, same quality — just finally visible to the people looking.\n\n" .
    "Your audit is the exact starting line his was. Want to see what we'd build for {company}? Book a free 15-minute call: https://calendly.com/meet-adverton/15\n\n" .
    "Leo from Adverton"
);

$t3 = upsertTemplate(
    'nurture_audit_d10_softask',
    "What your audit can't tell you, {first_name}",
    "Hi {first_name},\n\n" .
    "Your audit gave you a score and a top-5 list. It's a good snapshot. But a PDF can't answer the two questions that actually matter:\n\n" .
    "\"Which of these fixes puts money in my pocket fastest — and is doing this myself even worth my time?\"\n\n" .
    "That's a 15-minute conversation, not a document.\n\n" .
    "Here's how it goes: you and me, your audit open on the screen. I walk through what each fix really means for {company}, which ones move the needle first, and exactly how we'd handle the whole list for you. It's me — not a sales rep — and it's free.\n\n" .
    "One thing worth saying plainly: an audit is a snapshot of today. Every week those fixes sit untouched, the calls they'd bring in don't disappear — they go to whichever contractor did fix them. That's the real cost of \"I'll get to it later.\"\n\n" .
    "Pick a time that works: https://calendly.com/meet-adverton/15\n\n" .
    "Leo from Adverton"
);

$t4 = upsertTemplate(
    'nurture_audit_d18_value',
    "Two fixes from your audit you can do yourself this week",
    "Hi {first_name},\n\n" .
    "Maybe now isn't the moment to bring on help — fair enough. So here are two fixes straight off your audit you can knock out yourself this week, no agency required:\n\n" .
    "1. Add every service category to your Google Business Profile. Most contractors list one or two; you're allowed up to ten. Each one is another door customers can walk through to find you — right now most of those doors are shut.\n\n" .
    "2. Reply to your last five reviews. Even a single line. Google rewards how consistently you respond, not just how many reviews you have — and the next customer reads your replies as closely as the reviews themselves.\n\n" .
    "Ten minutes total. Both genuinely move the needle. Go do them — I mean it.\n\n" .
    "Leo from Adverton\n\n" .
    "P.S. Those are 2 of your 5 fixes. If you'd rather have all five — plus a website and ads built to bring in calls — simply handled, that's the job we do for contractors. Book a free 15-minute call and I'll show you exactly how: https://calendly.com/meet-adverton/15"
);

$t5 = upsertTemplate(
    'nurture_audit_d30_breakup',
    "Closing your audit file, {first_name}",
    "Hi {first_name},\n\n" .
    "This is the last email I'll send about your audit, so I'll be straight.\n\n" .
    "You ran it because some part of you already knew the phone could ring more than it does. The report just put numbers on it. And numbers like that don't fade — they sit there until someone acts on them.\n\n" .
    "Most contractors I talk to don't lack the will to fix it. They lack a free evening. They're on the job all day, and \"deal with the marketing\" loses every single time to an actual paying customer. That's not a failing — it's just the math of running a crew.\n\n" .
    "It's also the exact reason Adverton exists: \$799 a month, and your website, your reviews, and your ads are handled — a full marketing team without the hire, the salaries, or the management.\n\n" .
    "If you want it off your plate, here's my calendar one more time: https://calendly.com/meet-adverton/15\n\n" .
    "If not, no hard feelings — the audit's yours to keep and the door stays open. Wishing {company} a strong rest of the year.\n\n" .
    "Leo from Adverton\n\n" .
    "P.S. If even a 15-minute call feels like too much to fit in right now — that's usually the clearest sign you're stretched too thin to be running your own marketing. Worth sitting with for a second."
);

foreach (['d2'=>$t1,'d5'=>$t2,'d10'=>$t3,'d18'=>$t4,'d30'=>$t5] as $k => $r) {
    echo "  template {$k}: #{$r['id']} {$r['action']}\n";
}

$seqId = upsertSequence('Post-Audit Nurture (5 touches)', 'lead_created', 'audit_auto');
replaceSequenceSteps($seqId, [
    ['delay_days' => 2,  'template_id' => $t1['id']],
    ['delay_days' => 5,  'template_id' => $t2['id']],
    ['delay_days' => 10, 'template_id' => $t3['id']],
    ['delay_days' => 18, 'template_id' => $t4['id']],
    ['delay_days' => 30, 'template_id' => $t5['id']],
]);
echo "  sequence #{$seqId} 'Post-Audit Nurture (5 touches)' — 5 steps re-asserted (d2/5/10/18/30)\n\n";

$created = count(array_filter([$t1,$t2,$t3,$t4,$t5], fn($r) => $r['action'] === 'created'));
if ($created > 0) {
    echo "⚠️  {$created} template(s) were CREATED, not updated — names may have drifted. NOT self-destructing; review.\n";
    exit;
}

echo "All 5 audit templates updated in place. Sequence healthy.\n";
if (@unlink(__FILE__)) {
    echo "Self-destructed: " . __FILE__ . " removed.\n";
} else {
    echo "Could not unlink — remove manually.\n";
}
