<?php
// ONE-SHOT — remove role-based addresses (info@/support@/etc.) from the DRAFT
// cold-email campaigns "Cold Batch 02" and "Cold Batch 03" before activation.
// Role-based + catch-all addresses are the main bounce + zero-conversion
// segment; stripping them protects sender-domain reputation.
//
// SAFE BY DESIGN:
//   - Dry-run by default: lists campaigns, shows Batch 01's REAL bounce rate,
//     counts role-based leads in 02/03, prints a sample + the lead object shape.
//     Touches NOTHING.
//   - ?confirm=1 deletes the role-based leads (DELETE /leads/{id}).
//   - Never operates on ACTIVE campaigns (skips status=1) and only on
//     campaigns whose name contains "Batch 02" / "Batch 03".
//
//   /crm/_clean-instantly-roles.php?t=TOKEN            (dry-run)
//   /crm/_clean-instantly-roles.php?t=TOKEN&confirm=1  (delete)
//
// DELETE THIS FILE after use.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/instantly.php';

if (($_GET['t'] ?? '') !== '0c2fbc549075cdc47452691ec06f9e0be739f4533229824a') {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');

// Deleting 100+ leads = 100+ sequential API calls. Lift the time limit and
// stream output so the run survives and shows live progress. A per-run cap
// guarantees each request finishes even if the host enforces a hard limit —
// re-run to continue (the script re-reads current state each time).
@set_time_limit(0);
@ini_set('max_execution_time', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);
const DELETE_CAP_PER_RUN = 80;

$confirm = ($_GET['confirm'] ?? '') === '1';

// Same role rule used to build the *-personal.csv files.
$ROLE = ['info','support','sales','office','contact','admin','hello','service','services',
         'billing','dispatch','help','team','mail','email','noreply','no-reply','accounts',
         'accounting','customerservice','enquiries','inquiries','general','reception'];
function is_role_email(string $email, array $ROLE): bool {
    $lp = strtolower(trim(explode('@', $email)[0] ?? ''));
    $lp = explode('+', $lp)[0];
    $clean = str_replace(['.', '-', '_'], '', $lp);
    if (in_array($lp, $ROLE, true) || in_array($clean, $ROLE, true)) return true;
    foreach (['info','support','sales','office','contact'] as $p) {
        if (strpos($clean, $p) === 0) return true;
    }
    return false;
}

echo "=== Instantly role-based cleaner ===\n";
echo $confirm ? "MODE: DELETE\n\n" : "MODE: DRY-RUN (nothing is changed)\n\n";

// 1) Campaigns
$camps = crm_instantlyListCampaigns(50);
if ($camps['error']) { echo "ERROR listing campaigns: {$camps['error']}\n"; exit; }
$batch = [];
foreach ($camps['items'] as $c) {
    $name = (string)($c['name'] ?? '');
    if (stripos($name, 'Cold Batch') === 0) {
        $batch[] = $c;
        echo "found: id=" . ($c['id'] ?? '?') . "  status=" . ($c['status'] ?? '?') . "  name=\"{$name}\"\n";
    }
}
echo "\n";

// 2) Batch 01 real bounce rate (grounding the diagnosis) — read-only.
foreach ($batch as $c) {
    if (stripos((string)$c['name'], 'Batch 01') !== false && !empty($c['id'])) {
        $a = crm_instantlyRequest('GET', '/campaigns/analytics', ['id' => $c['id']]);
        $row = $a['ok'] ? ($a['data'][0] ?? $a['data'] ?? []) : [];
        $sent = (int)($row['emails_sent_count'] ?? 0);
        $bounced = (int)($row['bounced_count'] ?? $row['bounce_count'] ?? 0);
        $replies = (int)($row['reply_count'] ?? 0);
        $pct = $sent > 0 ? round(100 * $bounced / $sent, 1) : 0;
        echo "Batch 01 LIVE: sent={$sent}  bounced={$bounced} ({$pct}%)  replies={$replies}\n\n";
    }
}

// Helper: page through a campaign's leads (Instantly v2: POST /leads/list).
function list_campaign_leads(string $campaignId): array {
    $out = []; $after = null; $pages = 0;
    do {
        $body = ['campaign' => $campaignId, 'limit' => 100];
        if ($after) $body['starting_after'] = $after;
        $r = crm_instantlyRequest('POST', '/leads/list', $body);
        if (!$r['ok']) return ['error' => $r['error'], 'http' => $r['http'], 'raw' => $r['data'], 'items' => $out];
        $items = $r['data']['items'] ?? [];
        foreach ($items as $it) $out[] = $it;
        $after = $r['data']['next_starting_after'] ?? null;
        $pages++;
    } while ($after && $pages < 60);
    return ['error' => '', 'items' => $out];
}

// 3) Process Batch 02 + 03
$deletedTotal = 0;
$deleteBudget = DELETE_CAP_PER_RUN;
$roleRemaining = 0;
foreach ($batch as $c) {
    $name = (string)($c['name'] ?? '');
    $isTarget = stripos($name, 'Batch 02') !== false || stripos($name, 'Batch 03') !== false;
    if (!$isTarget) continue;
    if ((int)($c['status'] ?? 0) === 1) { echo "SKIP \"{$name}\" — campaign is ACTIVE (status=1), not touching it.\n\n"; continue; }
    $cid = (string)($c['id'] ?? '');
    if ($cid === '') continue;

    echo "── {$name} ({$cid}) ──\n";
    $res = list_campaign_leads($cid);
    if (!empty($res['error'])) {
        echo "  ERROR listing leads: {$res['error']} (http {$res['http']})\n";
        echo "  raw: " . substr(json_encode($res['raw']), 0, 300) . "\n\n";
        continue;
    }
    $leads = $res['items'];
    $roleLeads = [];
    foreach ($leads as $l) {
        $em = (string)($l['email'] ?? '');
        if ($em !== '' && is_role_email($em, $ROLE)) $roleLeads[] = $l;
    }
    echo "  total leads: " . count($leads) . "   role-based: " . count($roleLeads) . "\n";
    if ($leads) {
        echo "  lead object keys: " . implode(', ', array_keys($leads[0])) . "\n";
    }
    $sample = array_slice($roleLeads, 0, 8);
    foreach ($sample as $l) echo "    - " . ($l['email'] ?? '?') . "\n";

    if ($confirm) {
        $del = 0; $errs = 0;
        echo "  deleting: ";
        foreach ($roleLeads as $l) {
            if ($deleteBudget <= 0) { $roleRemaining++; continue; }
            $id = (string)($l['id'] ?? '');
            if ($id === '') { $errs++; continue; }
            $d = crm_instantlyRequest('DELETE', '/leads/' . rawurlencode($id));
            if ($d['ok']) { $del++; $deleteBudget--; echo "."; }
            else { $errs++; echo "x"; }
            @flush();
        }
        $deletedTotal += $del;
        echo "\n  DELETED {$del} role-based leads" . ($errs ? " ({$errs} errors)" : '') . "\n";
    }
    echo "\n";
}

if (!$confirm) {
    echo "Dry-run complete. If the counts + lead keys above look right, re-run with &confirm=1 to delete.\n";
} elseif ($roleRemaining > 0) {
    echo "Deleted {$deletedTotal} this run; hit the per-run cap. {$roleRemaining}+ still queued — re-run the SAME &confirm=1 URL to continue.\n";
} else {
    echo "Done. Deleted {$deletedTotal} role-based leads across Batch 02 + 03. You can delete this script now.\n";
}
