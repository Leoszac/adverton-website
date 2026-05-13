<?php
// One-shot migration runner for schema-v13.sql.
// Adds 'cold_call' to leads.source ENUM + creates cold_prospects table.
//
// Auth: founder-only. Idempotent (catches Duplicate-entry / table-exists
// errors and reports as [skip]). Self-destructs after a clean apply.
//
// Visit: https://adverton.net/crm/_run-v13.php
// (Founder must be logged in.)

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$user = crm_requireRole(['founder']);

header('Content-Type: text/plain; charset=utf-8');

echo "── schema-v13 migration (cold-calling pipeline) ────────────\n";
echo "User: {$user['username']} (role: {$user['role']})\n\n";

$statements = [
    "ALTER TABLE leads
       MODIFY COLUMN source ENUM(
         'audit_auto','audit_manual','contact_form','inbound_call',
         'manual','ebook_growth_engine','referral','affiliate',
         'csv_import','cold_email_instantly','cold_call'
       ) NOT NULL",

    "CREATE TABLE IF NOT EXISTS cold_prospects (
       id                INT AUTO_INCREMENT PRIMARY KEY,
       phone             VARCHAR(20)  NOT NULL,
       business_name     VARCHAR(160) NULL,
       email             VARCHAR(160) NULL,
       contact_name      VARCHAR(160) NULL,
       trade             VARCHAR(80)  NULL,
       city              VARCHAR(80)  NULL,
       state             VARCHAR(40)  NULL,
       website           VARCHAR(255) NULL,
       gbp_url           VARCHAR(500) NULL,
       notes             TEXT         NULL,
       source            ENUM('outscraper','manual_csv','online','other') NOT NULL DEFAULT 'manual_csv',
       imported_batch_id VARCHAR(32)  NULL,
       imported_by       INT          NULL,
       dnc_status        ENUM(
         'pending','clean',
         'blocked_federal','blocked_state','blocked_wireless','blocked_litigator',
         'blocked_internal','scrub_error'
       ) NOT NULL DEFAULT 'pending',
       dnc_scrubbed_at   DATETIME     NULL,
       dnc_meta_json     JSON         NULL,
       call_status       ENUM(
         'not_called','no_answer','voicemail','busy','wrong_number',
         'not_interested','interested','converted','dnc_requested','dead'
       ) NOT NULL DEFAULT 'not_called',
       call_attempts     SMALLINT     NOT NULL DEFAULT 0,
       last_called_at    DATETIME     NULL,
       last_called_by    INT          NULL,
       converted_lead_id INT          NULL,
       converted_at      DATETIME     NULL,
       created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uk_cold_prospects_phone (phone),
       INDEX idx_cold_prospects_dnc_status  (dnc_status),
       INDEX idx_cold_prospects_call_status (call_status),
       INDEX idx_cold_prospects_batch       (imported_batch_id),
       INDEX idx_cold_prospects_dialable    (dnc_status, call_status, call_attempts),
       INDEX idx_cold_prospects_scrubbed_at (dnc_scrubbed_at),
       INDEX idx_cold_prospects_state_trade (state, trade)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$ok = 0; $skip = 0; $err = 0; $errMsgs = [];
foreach ($statements as $i => $sql) {
    $n = $i + 1;
    try {
        crm_db()->exec($sql);
        $ok++;
        $label = strtok(trim($sql), " \n");
        echo "[ok ] #{$n} {$label} ...\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $idempotent = (strpos($msg, 'Duplicate') !== false)
                   || (strpos($msg, 'already exists') !== false)
                   || (strpos($msg, 'Duplicate column') !== false)
                   || (strpos($msg, 'Duplicate key name') !== false);
        if ($idempotent) {
            $skip++;
            echo "[skip] #{$n} already applied: {$msg}\n";
        } else {
            $err++;
            $errMsgs[] = "#{$n}: {$msg}";
            echo "[ERR ] #{$n} {$msg}\n";
        }
    }
}

echo "\nSummary: ok={$ok} skip={$skip} err={$err}\n";

if ($err === 0) {
    // Sanity check: the table should now exist + have phone column.
    try {
        $cnt = (int) crm_db()->query("SELECT COUNT(*) FROM cold_prospects")->fetchColumn();
        echo "Verify: cold_prospects row count = {$cnt}\n";
    } catch (Throwable $e) {
        echo "Verify FAILED: " . $e->getMessage() . "\n";
        exit;
    }
    // Self-destruct.
    $self = __FILE__;
    if (@unlink($self)) {
        echo "\nSelf-destructed: {$self} removed.\n";
    } else {
        echo "\nCould not unlink {$self} — remove manually.\n";
    }
} else {
    echo "\nErrors detected — NOT self-destructing. Fix and re-run.\n";
    foreach ($errMsgs as $m) echo " - {$m}\n";
}
