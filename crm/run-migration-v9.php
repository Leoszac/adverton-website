<?php
// One-shot migration runner for schema v9.
// Adds 'ebook_growth_engine' to leads.source ENUM.
//
// Run via: curl "https://adverton.net/crm/run-migration-v9.php?token=THE_SEED_TOKEN"
// DELETE THIS FILE after running successfully.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = crm_config('SEED_TOKEN');
$got      = $_GET['token'] ?? '';
if (!$expected || !hash_equals((string)$expected, (string)$got)) {
    http_response_code(403);
    echo "Forbidden. Append ?token=... matching SEED_TOKEN in crm-config.php.\n";
    exit;
}

$db = crm_db();

// Show current ENUM definition
$stmt = $db->query("SHOW COLUMNS FROM leads LIKE 'source'");
$col = $stmt->fetch();
echo "Before migration:\n";
echo "  Type: {$col['Type']}\n\n";

// Run the ALTER
echo "Running ALTER TABLE leads MODIFY COLUMN source ENUM(...) NOT NULL ...\n";
try {
    $db->exec("ALTER TABLE leads MODIFY COLUMN source ENUM(
        'audit_auto',
        'audit_manual',
        'contact_form',
        'inbound_call',
        'manual',
        'ebook_growth_engine'
    ) NOT NULL");
    echo "  ✓ ALTER succeeded.\n\n";
} catch (Throwable $e) {
    echo "  ✗ ALTER failed: " . $e->getMessage() . "\n";
    exit;
}

// Show new ENUM
$stmt = $db->query("SHOW COLUMNS FROM leads LIKE 'source'");
$col = $stmt->fetch();
echo "After migration:\n";
echo "  Type: {$col['Type']}\n\n";

// Backfill: if any existing leads have source='' (silently truncated by MySQL),
// flag them so we can manually correct.
$stmt = $db->query("SELECT id, email, created_at FROM leads WHERE source = '' OR source IS NULL");
$orphans = $stmt->fetchAll();
if ($orphans) {
    echo "Found " . count($orphans) . " lead(s) with empty source:\n";
    foreach ($orphans as $o) {
        echo "  id={$o['id']}  email={$o['email']}  created_at={$o['created_at']}\n";
    }
    echo "\nThese were almost certainly ebook submissions before the migration. ";
    echo "Updating them to source='ebook_growth_engine' now...\n";
    $upd = $db->prepare("UPDATE leads SET source = 'ebook_growth_engine' WHERE id = ?");
    foreach ($orphans as $o) {
        $upd->execute([$o['id']]);
    }
    echo "  ✓ Backfilled " . count($orphans) . " leads.\n\n";
} else {
    echo "No leads with empty source found.\n\n";
}

echo "DONE. DELETE THIS FILE NOW (crm/run-migration-v9.php).\n";
