<?php
// One-shot migration: add billing_mode + barter_monthly_value_usd +
// billing_notes columns to clients. Idempotent (skips columns that
// already exist). Self-destructs on success.
//
// Run: https://adverton.net/crm/_install-billing-modes.php?go=bill-modes-q7r3kx

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const ONE_SHOT_TOKEN = 'bill-modes-q7r3kx';

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals(ONE_SHOT_TOKEN, (string)($_GET['go'] ?? ''))) {
    http_response_code(403);
    exit("forbidden — pass ?go=<token>\n");
}

$db = crm_db();

function columnExists(\PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}

$added = [];
$existing = [];

$columns = [
    'billing_mode'             => "VARCHAR(16) NOT NULL DEFAULT 'stripe'",
    'barter_monthly_value_usd' => 'DECIMAL(10,2) NULL',
    'billing_notes'            => 'TEXT NULL',
];

foreach ($columns as $name => $type) {
    if (columnExists($db, 'clients', $name)) {
        $existing[] = $name;
        continue;
    }
    $db->exec("ALTER TABLE clients ADD COLUMN {$name} {$type}");
    $added[] = $name;
}

echo "── Billing modes migration ──────────────────\n\n";
if ($added)    echo "ADDED:    " . implode(', ', $added) . "\n";
if ($existing) echo "EXISTING: " . implode(', ', $existing) . " (skipped)\n";
echo "\nAll clients default to billing_mode='stripe' — no behavior change\nfor existing clients. Operator flips per client via /crm/client.php.\n";

if (!$added) {
    echo "\nNothing to add — already migrated. NOT self-destructing.\n";
    exit;
}

if (@unlink(__FILE__)) {
    echo "\nSelf-destructed: " . __FILE__ . " removed.\n";
} else {
    echo "\n⚠️  Self-destruct failed — remove manually.\n";
}
