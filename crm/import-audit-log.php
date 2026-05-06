<?php
// One-shot importer: read /home2/advertonnet/logs/audit.log and back-fill leads.
// Run via browser:
//   /crm/import-audit-log.php?token=THE_SEED_TOKEN
// Idempotent: dedup logic in crm_insertLead skips entries already present
// (matched by email). DELETE THIS FILE after running.
//
// audit.log format (one JSON object per line, prefixed with ISO timestamp):
//   2025-04-12T18:33:14Z {"audit_id":"abc","manual":0,"email":"x@y.com",
//                          "gbp_url":"...","trade":"Plumbing","ip":"1.2.3.4","ua":"..."}

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = crm_config('SEED_TOKEN');
$got      = $_GET['token'] ?? '';
if (!$expected || !hash_equals((string)$expected, (string)$got)) {
    http_response_code(403);
    echo "Forbidden. Append ?token=... matching SEED_TOKEN in crm-config.php.\n";
    exit;
}

$logPath = $_GET['path'] ?? '/home2/advertonnet/logs/audit.log';
if (!is_readable($logPath)) {
    http_response_code(404);
    echo "Log not readable: {$logPath}\n";
    exit;
}

$fh = fopen($logPath, 'r');
if (!$fh) { echo "fopen failed\n"; exit; }

$total = 0; $created = 0; $deduped = 0; $skipped = 0;
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $total++;

    // Split "ISOTIMESTAMP {json}"
    $jsonStart = strpos($line, '{');
    if ($jsonStart === false) { $skipped++; continue; }
    $tsRaw = trim(substr($line, 0, $jsonStart));
    $row = json_decode(substr($line, $jsonStart), true);
    if (!is_array($row) || empty($row['email'])) { $skipped++; continue; }

    $isManual = !empty($row['manual']);
    $source   = $isManual ? 'audit_manual' : 'audit_auto';

    // Dedup pre-check: if this email already exists, skip (also covers re-imports)
    $found = crm_findDuplicateLead(strtolower((string)$row['email']), '');
    if ($found) { $deduped++; continue; }

    $id = crm_insertLead([
        'source'      => $source,
        'email'       => $row['email'],
        'trade'       => $row['trade']   ?? null,
        'gbp_url'     => $row['gbp_url'] ?? null,
        'audit_id'    => $row['audit_id'] ?? null,
        'ip'          => $row['ip']      ?? null,
        'user_agent'  => $row['ua']      ?? null,
        'source_page' => 'imported from audit.log @ ' . $tsRaw,
    ]);
    if ($id) {
        $created++;
        // Backdate created_at to the original timestamp from the log
        if ($tsRaw && $ts = strtotime($tsRaw)) {
            try {
                $stmt = crm_db()->prepare('UPDATE leads SET created_at = FROM_UNIXTIME(?) WHERE id = ?');
                $stmt->execute([$ts, $id]);
            } catch (Throwable $e) { /* ignore */ }
        }
    } else {
        $skipped++;
    }
}
fclose($fh);

echo "Import summary\n";
echo "--------------\n";
echo "Lines read:      {$total}\n";
echo "Leads created:   {$created}\n";
echo "Already in CRM:  {$deduped}\n";
echo "Skipped/errors:  {$skipped}\n";
echo "\n";
echo "DELETE THIS FILE NOW.\n";
