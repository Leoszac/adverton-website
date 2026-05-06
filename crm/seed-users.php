<?php
// One-shot user seeder. Run ONCE in the browser at /crm/seed-users.php after
// running schema.sql. It checks an in-config token to avoid being run by
// strangers who might find this file. DELETE THIS FILE after seeding.

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

$accounts = [
    // username       => [password, display name]
    'leandro' => [crm_config('SEED_PASS_LEANDRO') ?: 'change-me', 'Leandro'],
    'va'      => [crm_config('SEED_PASS_VA')      ?: 'change-me', 'VA'],
];

$db = crm_db();
foreach ($accounts as $username => [$password, $name]) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare(
        "INSERT INTO users (username, password_hash, display_name)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                                 display_name  = VALUES(display_name)"
    );
    $stmt->execute([$username, $hash, $name]);
    echo "Upserted user: {$username} ({$name})\n";
}

echo "\nDone. DELETE THIS FILE NOW (and unset SEED_PASS_* in crm-config.php).\n";
