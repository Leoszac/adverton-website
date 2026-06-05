<?php
// ONE-SHOT — add Variant B (short / 1-question) to email 1 of Batch 02 + 03
// so the opener is A/B tested (long map-pack vs short). Email 2/3 untouched.
//
//   Dry-run (default): shows the current vs planned email-1 variants. No change.
//   ?confirm=1:        PATCHes the sequence, then re-reads + prints the result.
//
//   /crm/_ab-setup.php?t=TOKEN
//   /crm/_ab-setup.php?t=TOKEN&confirm=1
//
// After confirm: send a TEST email of each variant from Instantly before
// activating (see the test-email-before-batch rule). DELETE this file after use.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/instantly.php';

if (($_GET['t'] ?? '') !== '32eccfe9664338c626af9ac29a26de303bc56223cfb4af3e') {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
$confirm = ($_GET['confirm'] ?? '') === '1';

// Variant B — approved short opener. Nowdoc keeps {{tags}}, quotes and the
// MDS LLC footer (verbatim from the existing variants) exactly as-is.
$variantB_subject = '{{firstName}} — calls from Google?';
$variantB_body = <<<'BODY'
<div>Hey {{firstName}},</div><div><br /></div><div>Quick one — is {{companyName}} getting enough calls from your Google listing, or is it still mostly word-of-mouth and referrals?</div><div><br /></div><div>Leo from Adverton</div><div><br /></div><div>—</div><div>Adverton · operated by MDS LLC · 16192 Coastal Highway, Lewes, DE 19958, USA</div><div>Not interested? Just reply with "unsubscribe" and I'll remove you.</div>
BODY;

echo "=== A/B setup (Batch 02 + 03) ===\n" . ($confirm ? "MODE: CONFIRM (patching)\n\n" : "MODE: DRY-RUN (no changes)\n\n");

$camps = crm_instantlyListCampaigns(50);
$targets = [];
foreach ($camps['items'] as $c) {
    if (preg_match('/Cold Batch 0?([23])\b/i', (string)($c['name'] ?? ''))) $targets[] = $c;
}

$snip = static function ($html) { return substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$html))), 0, 70); };

foreach ($targets as $c) {
    $cid = (string)($c['id'] ?? '');
    echo "── {$c['name']} ({$cid}) ──\n";

    $r = crm_instantlyRequest('GET', '/campaigns/' . rawurlencode($cid));
    if (!$r['ok']) { echo "  GET FAILED: http={$r['http']} {$r['error']}\n\n"; continue; }
    $camp = $r['data'] ?? [];
    $seq  = $camp['sequences'] ?? [];

    if (!isset($seq[0]['steps'][0]['variants'][0])) {
        echo "  ! could not locate sequence[0].steps[0].variants[0] — skipping\n\n";
        continue;
    }
    $variantA = $seq[0]['steps'][0]['variants'][0];   // keep current opener as-is

    echo "  current email-1 variants: " . count($seq[0]['steps'][0]['variants']) . "\n";
    echo "  PLANNED:\n";
    echo "    A (keep): subj=\"" . ($variantA['subject'] ?? '') . "\" | " . $snip($variantA['body'] ?? '') . "\n";
    echo "    B (new):  subj=\"{$variantB_subject}\" | " . $snip($variantB_body) . "\n";

    if ($confirm) {
        $seq[0]['steps'][0]['variants'] = [
            $variantA,
            ['subject' => $variantB_subject, 'body' => $variantB_body],
        ];
        $p = crm_instantlyRequest('PATCH', '/campaigns/' . rawurlencode($cid), ['sequences' => $seq]);
        if (!$p['ok']) {
            echo "  PATCH FAILED: http={$p['http']} error=\"{$p['error']}\" raw="
               . substr(json_encode($p['data']), 0, 250) . "\n";
        } else {
            // Verify: re-read and show the resulting variants.
            $r2 = crm_instantlyRequest('GET', '/campaigns/' . rawurlencode($cid));
            $v  = $r2['ok'] ? ($r2['data']['sequences'][0]['steps'][0]['variants'] ?? []) : [];
            echo "  PATCH ok. email-1 now has " . count($v) . " variants:\n";
            foreach ($v as $i => $vv) {
                echo "    [{$i}] subj=\"" . ($vv['subject'] ?? '') . "\" | " . $snip($vv['body'] ?? '') . "\n";
            }
        }
    }
    echo "\n";
}

echo $confirm
    ? "Done. NEXT: in Instantly, send a TEST email of BOTH variants to your inbox, confirm rendering + footer, THEN activate.\n"
    : "Dry-run. Re-run with &confirm=1 to apply the A/B.\n";
