<?php
// One-shot migration runner for schema-v10.sql.
// Adds 'referral', 'affiliate', 'csv_import' to the leads.source ENUM.
//
// Auth: founder-only (requires CRM login as founder).
// Idempotent: re-running just replays the same ENUM definition (no-op).
// Self-destructs on success so it can't be re-run accidentally.
//
// .cpanel.yml also wipes this file on the next deploy as backup.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$user = crm_requireRole(['founder']);

header('Content-Type: text/plain; charset=utf-8');

$sql = <<<SQL
ALTER TABLE leads
  MODIFY COLUMN source ENUM(
    'audit_auto', 'audit_manual', 'contact_form',
    'inbound_call', 'manual', 'ebook_growth_engine',
    'referral', 'affiliate', 'csv_import'
  ) NOT NULL
SQL;

echo "── schema-v10 migration ─────────────────────────────────────\n";
echo "User: {$user['username']} (role: {$user['role']})\n\n";

// Snapshot current ENUM values so we know what changed.
try {
    $stmt = crm_db()->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'leads'
           AND COLUMN_NAME  = 'source'"
    );
    $before = (string)($stmt->fetchColumn() ?: '');
    echo "Before: {$before}\n";
} catch (Throwable $e) {
    echo "Could not read current schema: " . $e->getMessage() . "\n";
    exit(1);
}

// Execute the ALTER
try {
    crm_db()->exec($sql);
    echo "ALTER ok.\n";
} catch (Throwable $e) {
    echo "ALTER FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Snapshot after
try {
    $stmt = crm_db()->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'leads'
           AND COLUMN_NAME  = 'source'"
    );
    $after = (string)($stmt->fetchColumn() ?: '');
    echo "After:  {$after}\n\n";
} catch (Throwable $e) {
    echo "Could not read post-migration schema: " . $e->getMessage() . "\n";
}

// Self-destruct
if (@unlink(__FILE__)) {
    echo "[ok]  _run-v10.php self-destructed.\n";
} else {
    echo "[warn] could not remove _run-v10.php — delete via cPanel File Manager.\n";
}

echo "\nDone. You can now select referral / affiliate / csv_import from the source dropdown.\n";
