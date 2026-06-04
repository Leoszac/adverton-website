<?php
// ONE-SHOT cleanup — remove cold-email leads that the Instantly webhook
// auto-created as "LOST" (bounced / unsubscribed dead addresses). These are
// not real prospects; they only clutter the Leads list.
//
// Target rows: source='cold_email_instantly' AND status='lost'.
// FK constraints are ON DELETE CASCADE (activities, tasks, tags, files,
// email_sends, enrollments), and clients.lead_id is ON DELETE SET NULL, so a
// plain DELETE on leads cleans up everything and never orphans a client.
//
// Usage:
//   Dry-run (default): /crm/_clean-cold-lost.php?t=TOKEN      → shows count + sample
//   Delete:            /crm/_clean-cold-lost.php?t=TOKEN&confirm=1
//
// DELETE THIS FILE after running it once.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== '2f6fb915430d4f532e3ae92fa6e198fc597b8a73609d0b61') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/html; charset=utf-8');
$db = crm_db();
$where = "source = 'cold_email_instantly' AND status = 'lost'";

$count = (int)$db->query("SELECT COUNT(*) FROM leads WHERE {$where}")->fetchColumn();
$sample = $db->query(
    "SELECT id, created_at, email, source_page, score
     FROM leads WHERE {$where} ORDER BY created_at DESC LIMIT 25"
)->fetchAll();

echo '<style>body{font-family:-apple-system,Segoe UI,sans-serif;padding:30px;color:#111;max-width:820px;margin:0 auto}'
   . 'table{border-collapse:collapse;width:100%;font-size:13px;margin:14px 0}td,th{border:1px solid #e5e7eb;padding:6px 9px;text-align:left}'
   . '.btn{display:inline-block;background:#dc2626;color:#fff;padding:11px 20px;border-radius:8px;font-weight:700;text-decoration:none}'
   . 'code{background:#f3f4f6;padding:1px 5px;border-radius:4px}</style>';

echo "<h2>Cold-email LOST cleanup</h2>";
echo "<p>Matching rows (<code>{$where}</code>): <strong>{$count}</strong></p>";

if ($count === 0) {
    echo "<p>Nothing to delete. You can delete this script now.</p>";
    exit;
}

echo "<p>Sample (most recent 25):</p><table><tr><th>id</th><th>created</th><th>email</th><th>source_page</th><th>score</th></tr>";
foreach ($sample as $r) {
    echo '<tr><td>' . (int)$r['id'] . '</td><td>' . htmlspecialchars((string)$r['created_at'])
       . '</td><td>' . htmlspecialchars((string)$r['email'])
       . '</td><td>' . htmlspecialchars((string)$r['source_page'])
       . '</td><td>' . htmlspecialchars((string)$r['score']) . '</td></tr>';
}
echo '</table>';

if (($_GET['confirm'] ?? '') !== '1') {
    $url = '?t=' . urlencode((string)$_GET['t']) . '&confirm=1';
    echo "<p><strong>This is a dry run.</strong> Review the list above. To permanently delete all {$count} rows:</p>";
    echo '<p><a class="btn" href="' . htmlspecialchars($url) . '">Delete ' . $count . ' rows</a></p>';
    exit;
}

// Confirmed — delete. FK cascade handles related rows.
$deleted = $db->exec("DELETE FROM leads WHERE {$where}");
echo "<h3>Deleted {$deleted} cold-email LOST leads.</h3>";
echo "<p>Related activities/tasks/tags were removed by cascade; linked clients (if any) were kept. "
   . "You can delete this script now.</p>";
