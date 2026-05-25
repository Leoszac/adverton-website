<?php
// One-shot migration: add comp_expires_at to clients. Idempotent
// (skips if column already exists). Self-destructs on success.
//
// Run: https://adverton.net/crm/_install-comp-expires.php?go=comp-exp-h9k2mt

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const ONE_SHOT_TOKEN = 'comp-exp-h9k2mt';

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals(ONE_SHOT_TOKEN, (string)($_GET['go'] ?? ''))) {
    http_response_code(403);
    exit("forbidden — pass ?go=<token>\n");
}

$db = crm_db();
$stmt = $db->prepare(
    "SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'comp_expires_at'"
);
$stmt->execute();
$exists = (bool)$stmt->fetchColumn();

echo "── comp_expires_at migration ────────────────\n\n";
if ($exists) {
    echo "Column already exists — nothing to do. NOT self-destructing.\n";
    exit;
}

$db->exec('ALTER TABLE clients ADD COLUMN comp_expires_at DATE NULL');
echo "ADDED: comp_expires_at (DATE NULL)\n";

if (@unlink(__FILE__)) {
    echo "\nSelf-destructed: " . __FILE__ . " removed.\n";
} else {
    echo "\n⚠️  Self-destruct failed — remove manually.\n";
}
