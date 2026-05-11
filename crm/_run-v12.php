<?php
// One-shot migration runner for schema-v12.sql.
// Token-gated. Self-destructs after a successful apply.
// Call: GET /crm/_run-v12.php?go=SEED_TOKEN
//
// Idempotent: schema-v12.sql uses CREATE TABLE IF NOT EXISTS, so re-runs
// are safe (but the file should self-delete on first success anyway).

declare(strict_types=1);
define('CRM_ENTRY', 1);

require_once __DIR__ . '/lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

$want = (string) crm_config('SEED_TOKEN');
$got  = (string)($_GET['go'] ?? '');
if ($want === '' || !hash_equals($want, $got)) {
    http_response_code(403);
    exit("forbidden — pass ?go=<SEED_TOKEN>\n");
}

$sqlPath = __DIR__ . '/schema-v12.sql';
if (!is_readable($sqlPath)) {
    http_response_code(500);
    exit("schema-v12.sql missing\n");
}
$sql = (string) file_get_contents($sqlPath);

// Strip comments + split on semicolons (naïve but works for our DDL files
// since they don't contain string literals with semicolons).
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_values(array_filter(array_map('trim', explode(';', $sql))));

$pdo = crm_db();
$applied = 0;
$errors  = [];
foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    try {
        $pdo->exec($stmt);
        $applied++;
    } catch (Throwable $e) {
        $errors[] = ['stmt' => substr($stmt, 0, 120) . '…', 'err' => $e->getMessage()];
    }
}

echo "[v12] applied {$applied} statements\n";
foreach ($errors as $e) {
    echo "  ! " . $e['err'] . "\n    on: " . $e['stmt'] . "\n";
}

// Verify the table exists.
try {
    $col = $pdo->query("SHOW TABLES LIKE 'client_form_submissions'")->fetchColumn();
    if ($col === 'client_form_submissions') {
        echo "[v12] verified table client_form_submissions exists\n";
        // Self-destruct only if everything went clean
        if (empty($errors)) {
            @unlink(__FILE__);
            echo "[ok] _run-v12.php self-destructed\n";
        } else {
            echo "[warn] keeping _run-v12.php (errors above) — investigate, fix, retry\n";
        }
    } else {
        echo "[fail] client_form_submissions still missing\n";
    }
} catch (Throwable $e) {
    echo "[verify error] " . $e->getMessage() . "\n";
}
