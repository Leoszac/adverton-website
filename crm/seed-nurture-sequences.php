<?php
// One-shot seeder: creates 8 email templates + 2 nurture sequences
// (one for audit_auto leads, one for ebook_growth_engine leads).
//
// Idempotent: running twice doesn't duplicate. Templates and sequences are
// keyed by name; if a name already exists it's UPDATED, not duplicated.
//
// Run via:  curl -sS 'https://adverton.net/crm/seed-nurture-sequences.php?token=SEED_TOKEN'
//
// DELETE THIS FILE after running successfully.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/sequences.php';
require_once __DIR__ . '/lib/templates.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = crm_config('SEED_TOKEN');
$got      = $_GET['token'] ?? '';
if (!$expected || !hash_equals((string)$expected, (string)$got)) {
    http_response_code(403);
    echo "Forbidden.\n";
    exit;
}

$db = crm_db();

// ---------------------------------------------------------------- Templates
// 4 audit-nurture templates + 4 ebook-nurture templates.
// All use {first_name} and {trade}; some use {audit_score}, {business_name}.
//
// Hard-coded HTML body — keeps the same look/feel as the audit delivery email.
// Subject lines are short, contractor-language, no agency speak.

function tpl_html(string $bodyHtml): string {
    // Wrap in a minimal Resend-friendly container. Keeps brand consistency
    // with the rest of the system (purple header rule, charter italic asides).
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f5f4f9;font-family:-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f5f4f9;"><tr><td style="padding:24px 12px;">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 6px 18px rgba(13,11,30,0.05);">'
         . '<tr><td style="padding:18px 28px;border-bottom:1px solid #e7e4ee;">'
         . '<a href="https://adverton.net" style="text-decoration:none;color:#6d28d9;font-weight:700;font-size:16px;">adverton</a>'
         . '</td></tr>'
         . '<tr><td style="padding:28px;font-size:15px;color:#383640;line-height:1.65;">'
         . $bodyHtml
         . '</td></tr>'
         . '<tr><td style="padding:14px 28px 22px;border-top:1px solid #e7e4ee;font-size:11px;color:#6b6877;line-height:1.5;">'
         . 'Adverton · MDS LLC · 16192 Coastal Highway, Lewes, DE 19958, USA<br>'
         . 'Reply to this email to unsubscribe — we read every reply.'
         . '</td></tr></table></td></tr></table></body></html>';
}

$TEMPLATES = [
    // --- AUDIT NURTURE ---
    [
        'name'    => 'audit-nurture-1',
        'subject' => 'Did you fix the top issue we flagged?',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>Couple days ago you ran our free Google Business Profile audit. Wanted to check in: did you tackle the #1 issue we flagged?</p>'
            . '<p>Most contractors don\'t — not because they don\'t care, but because they\'re running jobs. The fix sits on a list and the next emergency call eats the day.</p>'
            . '<p>If you want me to look at it personally and tell you whether it\'s actually moving the needle, just reply to this email with "look at mine" and I\'ll get back to you within the day.</p>'
            . '<p>— Leo from Adverton</p>'
        ),
    ],
    [
        'name'    => 'audit-nurture-2',
        'subject' => 'The 5-minute thing 70% of contractors skip',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>Quick fact most {trade} owners don\'t know:</p>'
            . '<p><strong>Calling a lead within 5 minutes vs. 30 minutes makes you 21× more likely to qualify them</strong> (MIT lead-response study, 15K leads).</p>'
            . '<p>Translation: if your phone rings during business hours and a tech\'s on a roof, you\'re paying ads to feed your competitor.</p>'
            . '<p>The fix isn\'t hiring. It\'s an automated text-back when a call doesn\'t connect — recovers 30–50% of would-be lost leads with zero added human effort.</p>'
            . '<p>Want me to show you what your current miss-rate actually is? Reply "audit my calls" and I\'ll give you a 10-minute look at last 30 days. Free.</p>'
            . '<p>— Leo</p>'
        ),
    ],
    [
        'name'    => 'audit-nurture-3',
        'subject' => 'From 47 reviews to 318 in 6 months',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>One of the contractors we work with — 5-truck plumbing shop, top-50 metro — went from <strong>47 Google reviews to 318</strong> in six months.</p>'
            . '<p>Same number of jobs. Same customers. The difference: the post-job sequence ran on autopilot, every closed job, four touches over 7 days, by SMS not email.</p>'
            . '<p>Why does that matter? Harvard Business School research (Michael Luca, working paper 12-016) shows a one-star rating increase drives a 5–9% revenue lift for independent businesses. Ratings move money.</p>'
            . '<p>If your shop\'s under 100 reviews and you\'re running real jobs, this is the highest-ROI thing you\'re not doing.</p>'
            . '<p>15-minute call to show you what we\'d set up: <a href="https://adverton.net/#contact" style="color:#6d28d9;font-weight:600;">adverton.net/#contact</a></p>'
            . '<p>— Leo</p>'
        ),
    ],
    [
        'name'    => 'audit-nurture-4',
        'subject' => 'Want me to look at your numbers myself?',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>Last note from me on this one.</p>'
            . '<p>You ran our audit a couple weeks ago. If you read it, fixed a few things, and you\'re good — perfect, that\'s the point of the audit.</p>'
            . '<p>If you read it and felt overwhelmed by the list, that\'s the part where most contractors give up. We exist to run that list for you. Site, GBP, Google ads, reviews, CRM — bundled. <strong>$799/month flat.</strong> One team, one bill.</p>'
            . '<p>Whether or not we\'re a fit, I\'ll happily spend 15 minutes looking at your specific numbers and tell you what I\'d do first. No pitch deck, no upsell. If we\'re not the right fit I\'ll tell you straight.</p>'
            . '<p><a href="https://adverton.net/#contact" style="display:inline-block;background:#6d28d9;color:#fff;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;">Book a 15-min call →</a></p>'
            . '<p>— Leo</p>'
        ),
    ],

    // --- EBOOK NURTURE ---
    [
        'name'    => 'ebook-nurture-1',
        'subject' => 'Did you make it past chapter 2?',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>Most contractors don\'t read past chapter 2 of the Growth Engine guide. If you\'re still reading, you\'re in the top 5%.</p>'
            . '<p>If you skim one more chapter, make it <strong>Chapter 11 — The 30-day rollout</strong>. It\'s the part most owners say they wish they\'d started with: four phases, in order, that have to happen for the rest to work.</p>'
            . '<p>Question for you: of the four phases (Foundation, Visibility, Response, Amplification) — which one is your shop weakest at right now? Reply to this email with the number. I\'ll send back the one specific thing I\'d tackle first if it were my shop.</p>'
            . '<p>— Leo from Adverton</p>'
        ),
    ],
    [
        'name'    => 'ebook-nurture-2',
        'subject' => 'Run a free audit on your own profile',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>The Growth Engine guide tells you <em>what</em> to fix on your Google Business Profile. The free audit at <a href="https://adverton.net/audit" style="color:#6d28d9;font-weight:600;">adverton.net/audit</a> tells you <em>which ones</em> are actually broken on your specific profile, today.</p>'
            . '<p>Takes 60 seconds. No card. No call. We grade your profile against what top {trade} contractors actually do, and email you a written report with your top 5 fixes.</p>'
            . '<p>Most owners run it once and immediately spot 2-3 things they didn\'t realize were costing them calls.</p>'
            . '<p><a href="https://adverton.net/audit" style="display:inline-block;background:#6d28d9;color:#fff;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;">Run my free audit →</a></p>'
            . '<p>— Leo</p>'
        ),
    ],
    [
        'name'    => 'ebook-nurture-3',
        'subject' => 'The number 95% of contractors get wrong',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>Ask any contractor what % of inbound calls they answer. The answer is almost always <em>"90%, maybe 95."</em></p>'
            . '<p>Then we install call tracking and the real number comes back: <strong>between 60% and 75%</strong>.</p>'
            . '<p>Translation for a 5-truck shop: $8,000–$15,000 a month walking out the door, every month, because ads are generating calls the team isn\'t picking up.</p>'
            . '<p>The ads aren\'t broken. The connection between ads and answered calls is.</p>'
            . '<p>If you want me to look at your specific shop and give you a real read on your miss-rate, that\'s exactly what the 15-min call is for. No pitch, no obligation — if you\'re already at 95% you\'re ahead of the pack and I\'ll tell you so.</p>'
            . '<p><a href="https://adverton.net/#contact" style="color:#6d28d9;font-weight:600;">Book the 15 minutes →</a></p>'
            . '<p>— Leo</p>'
        ),
    ],
    [
        'name'    => 'ebook-nurture-4',
        'subject' => '15 minutes — straight assessment, no pitch',
        'body'    => tpl_html(
            '<p>Hi {first_name},</p>'
            . '<p>Three weeks ago you grabbed the Growth Engine guide. Last note from me.</p>'
            . '<p>If you ran the 30-day rollout yourself — perfect, that\'s exactly what the guide is for. Reply and tell me how it went, I\'ll read every word.</p>'
            . '<p>If the guide sat in your inbox because the days got eaten by jobs, hires, or the ten other things only the owner can do — that\'s when most contractors call us.</p>'
            . '<p>Adverton bundles the entire Growth Engine — site, Google ads, GBP, reviews, CRM — at <strong>$799/month flat</strong>. One team. One bill. Built only for U.S. trades.</p>'
            . '<p>15 minutes. I\'ll show you what we\'d do, what it costs, and whether it makes sense. If it\'s not a fit, I\'ll tell you.</p>'
            . '<p><a href="https://adverton.net/#contact" style="display:inline-block;background:#6d28d9;color:#fff;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;">Book a 15-min call →</a></p>'
            . '<p>— Leo</p>'
        ),
    ],
];

echo "=== Seeding email templates ===\n";
$tplIds = [];
foreach ($TEMPLATES as $t) {
    $stmt = $db->prepare('SELECT id FROM email_templates WHERE name = ?');
    $stmt->execute([$t['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        $upd = $db->prepare('UPDATE email_templates SET subject=?, body=? WHERE id=?');
        $upd->execute([$t['subject'], $t['body'], $existing['id']]);
        $tplIds[$t['name']] = (int)$existing['id'];
        echo "  updated  id={$existing['id']}  {$t['name']}\n";
    } else {
        $ins = $db->prepare('INSERT INTO email_templates (name, subject, body) VALUES (?,?,?)');
        $ins->execute([$t['name'], $t['subject'], $t['body']]);
        $newId = (int)$db->lastInsertId();
        $tplIds[$t['name']] = $newId;
        echo "  created  id={$newId}  {$t['name']}\n";
    }
}

// ---------------------------------------------------------------- Sequences
$SEQUENCES = [
    [
        'name'          => 'Audit nurture (audit_auto)',
        'trigger_event' => 'lead_created',
        'trigger_value' => 'audit_auto',
        'active'        => 1,
        'steps' => [
            ['delay_days' => 3,  'tpl' => 'audit-nurture-1'],
            ['delay_days' => 7,  'tpl' => 'audit-nurture-2'],
            ['delay_days' => 11, 'tpl' => 'audit-nurture-3'],
            ['delay_days' => 14, 'tpl' => 'audit-nurture-4'],
        ],
    ],
    [
        'name'          => 'Ebook nurture (Growth Engine)',
        'trigger_event' => 'lead_created',
        'trigger_value' => 'ebook_growth_engine',
        'active'        => 1,
        'steps' => [
            ['delay_days' => 3,  'tpl' => 'ebook-nurture-1'],
            ['delay_days' => 7,  'tpl' => 'ebook-nurture-2'],
            ['delay_days' => 14, 'tpl' => 'ebook-nurture-3'],
            ['delay_days' => 21, 'tpl' => 'ebook-nurture-4'],
        ],
    ],
];

echo "\n=== Seeding sequences ===\n";
foreach ($SEQUENCES as $s) {
    $stmt = $db->prepare('SELECT id FROM sequences WHERE name = ?');
    $stmt->execute([$s['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        $upd = $db->prepare('UPDATE sequences SET trigger_event=?, trigger_value=?, active=? WHERE id=?');
        $upd->execute([$s['trigger_event'], $s['trigger_value'], $s['active'], $existing['id']]);
        $seqId = (int)$existing['id'];
        echo "  updated  id={$seqId}  {$s['name']}\n";
    } else {
        $ins = $db->prepare('INSERT INTO sequences (name, trigger_event, trigger_value, active) VALUES (?,?,?,?)');
        $ins->execute([$s['name'], $s['trigger_event'], $s['trigger_value'], $s['active']]);
        $seqId = (int)$db->lastInsertId();
        echo "  created  id={$seqId}  {$s['name']}\n";
    }
    // Replace steps cleanly
    $db->prepare('DELETE FROM sequence_steps WHERE sequence_id = ?')->execute([$seqId]);
    $stepIns = $db->prepare(
        'INSERT INTO sequence_steps (sequence_id, step_order, delay_days, action, payload) VALUES (?,?,?,?,?)'
    );
    foreach ($s['steps'] as $i => $st) {
        $tplId = $tplIds[$st['tpl']] ?? null;
        if (!$tplId) {
            echo "    WARN: template {$st['tpl']} not found, skipping step\n";
            continue;
        }
        $payload = json_encode(['template_id' => $tplId]);
        $stepIns->execute([$seqId, $i + 1, $st['delay_days'], 'send_template', $payload]);
        echo "    step " . ($i+1) . "  +{$st['delay_days']}d  {$st['tpl']}  (template id={$tplId})\n";
    }
}

echo "\nDONE. DELETE THIS FILE NOW (crm/seed-nurture-sequences.php).\n";
