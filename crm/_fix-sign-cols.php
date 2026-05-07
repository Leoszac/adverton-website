<?php
// Per-column verifier+adder for the click-wrap T&C fields.
// _bootstrap-v11.php's clients-ALTER ran as one statement; if any column
// in that batch was already applied by a previous _run-v11.php run, MySQL
// aborts the whole thing and the 6 click-wrap columns this PR added never
// landed. This script checks INFORMATION_SCHEMA per column and adds only
// what's missing. Token-gated, self-destructs.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

const FIX_TOKEN = '272fd33310b88b4bc58e5afd9d54353f';

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals(FIX_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

echo "── click-wrap column fix ──────────────────────────────\n";

$want = [
    'sign_provider'      => "ADD COLUMN sign_provider VARCHAR(20) NULL",
    'sign_doc_id'        => "ADD COLUMN sign_doc_id VARCHAR(120) NULL",
    'contract_signed_at' => "ADD COLUMN contract_signed_at DATETIME NULL",
    'tos_consented_at'   => "ADD COLUMN tos_consented_at DATETIME NULL",
    'tos_consented_ip'   => "ADD COLUMN tos_consented_ip VARCHAR(45) NULL",
];

$db = crm_db();

// Discover the actual DB name (for INFORMATION_SCHEMA queries)
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
echo "DB: {$dbName}\n\n";

$existingStmt = $db->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clients'"
);
$existingStmt->execute([$dbName]);
$existing = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));

$added = 0; $skipped = 0; $errors = [];
foreach ($want as $col => $sql) {
    if (isset($existing[$col])) {
        echo "[skip] {$col} — already exists\n";
        $skipped++;
        continue;
    }
    try {
        $db->exec("ALTER TABLE clients {$sql}");
        echo "[ok]   {$col} added\n";
        $added++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (preg_match('/1060|Duplicate column/i', $msg)) {
            echo "[skip] {$col} — race-added by parallel run\n";
            $skipped++;
        } else {
            echo "[FAIL] {$col}: " . substr($msg, 0, 200) . "\n";
            $errors[] = "{$col}: {$msg}";
        }
    }
}

// Index on contract_signed_at — separate from columns since INFORMATION_SCHEMA
// path is INDEXES not COLUMNS, and there's no IF NOT EXISTS for ADD INDEX
// pre-MySQL 8.0.29. Just try-and-catch.
echo "\n── index ──────────────────────────────────────────────\n";
try {
    $db->exec("ALTER TABLE clients ADD INDEX idx_contract_signed (contract_signed_at)");
    echo "[ok]   idx_contract_signed added\n";
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (preg_match('/1061|Duplicate key|already exists/i', $msg)) {
        echo "[skip] idx_contract_signed — already exists\n";
    } else {
        echo "[FAIL] idx_contract_signed: " . substr($msg, 0, 200) . "\n";
        $errors[] = "idx_contract_signed: {$msg}";
    }
}

echo "\nSummary: added={$added} skipped={$skipped} errors=" . count($errors) . "\n";

if ($errors) {
    echo "\nErrors persist — DO NOT delete this file. Resolve and re-run.\n";
    exit(1);
}

if (@unlink(__FILE__)) {
    echo "\n[ok] _fix-sign-cols.php self-destructed.\n";
} else {
    echo "\n[warn] could not remove _fix-sign-cols.php — delete via cPanel File Manager.\n";
}
echo "\nDone. Click-wrap columns are present on the clients table.\n";
