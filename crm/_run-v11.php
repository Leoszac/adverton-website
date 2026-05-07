<?php
// One-shot migration runner for schema-v11.sql.
// Adds billing fields to clients + magic_token to leads/clients + creates
// client_intake, client_credentials, and client_assets tables.
//
// Auth: founder-only. Idempotent (CREATE TABLE IF NOT EXISTS + ALTERs are
// caught and ignored if columns already exist). Self-destructs on success.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$user = crm_requireRole(['founder']);

header('Content-Type: text/plain; charset=utf-8');

echo "── schema-v11 migration ─────────────────────────────────────\n";
echo "User: {$user['username']} (role: {$user['role']})\n\n";

// Each statement is run independently. ALTER TABLE ... ADD COLUMN that
// already exists throws "Duplicate column"; we treat that as a no-op so
// re-running is safe. CREATE TABLE IF NOT EXISTS handles its own idempotency.
$statements = [
    // ALTER leads: magic-link token columns for the pre-contract flow
    "ALTER TABLE leads
       ADD COLUMN magic_token            CHAR(64) NULL,
       ADD COLUMN magic_token_expires_at DATETIME NULL,
       ADD COLUMN pre_contract_sent_at   DATETIME NULL,
       ADD UNIQUE KEY uk_leads_magic_token (magic_token)",

    // ALTER clients: billing fields + magic-link token for kickoff
    "ALTER TABLE clients
       ADD COLUMN legal_entity_name        VARCHAR(255) NULL AFTER business_name,
       ADD COLUMN billing_email            VARCHAR(160) NULL AFTER primary_email,
       ADD COLUMN billing_address          TEXT         NULL,
       ADD COLUMN billing_city             VARCHAR(80)  NULL,
       ADD COLUMN billing_state            VARCHAR(40)  NULL,
       ADD COLUMN billing_zip              VARCHAR(20)  NULL,
       ADD COLUMN tax_id                   VARCHAR(40)  NULL,
       ADD COLUMN authorized_signer        VARCHAR(160) NULL,
       ADD COLUMN signer_role              VARCHAR(80)  NULL,
       ADD COLUMN pre_contract_completed_at DATETIME    NULL,
       ADD COLUMN magic_token              CHAR(64)     NULL,
       ADD COLUMN magic_token_expires_at   DATETIME     NULL,
       ADD UNIQUE KEY uk_clients_magic_token (magic_token),
       ADD INDEX idx_pre_contract_status   (pre_contract_completed_at)",

    "CREATE TABLE IF NOT EXISTS client_intake (
       id                       INT AUTO_INCREMENT PRIMARY KEY,
       client_id                INT NOT NULL,
       display_name             VARCHAR(160) NULL,
       tagline                  VARCHAR(255) NULL,
       story_short              TEXT NULL,
       story_long               TEXT NULL,
       service_area_json        JSON NULL,
       services_json            JSON NULL,
       hours_regular_json       JSON NULL,
       emergency_24_7           BOOLEAN DEFAULT FALSE,
       license_number           VARCHAR(80)  NULL,
       insurance_carrier        VARCHAR(160) NULL,
       years_in_business        TINYINT      NULL,
       certifications_json      JSON NULL,
       reviews_links_json       JSON NULL,
       photos_drive_url         VARCHAR(500) NULL,
       brand_colors_json        JSON NULL,
       brand_logo_path          VARCHAR(255) NULL,
       competitors_admired_json JSON NULL,
       primary_goal             ENUM('more_calls','more_bookings','more_reviews','brand_awareness') NULL,
       template_choice          ENUM('trust_first','speed_first','story_first') NULL,
       hosting_status           ENUM('client_has','adverton_provisions','unknown') NULL,
       domain_status            ENUM('client_has','adverton_buys','unknown') NULL,
       ai_drafts_json           JSON NULL,
       status ENUM('not_started','in_progress','ready_for_ai','ai_generated','pending_approval','approved','provisioning_pending','deployed') NOT NULL DEFAULT 'not_started',
       current_step             TINYINT NOT NULL DEFAULT 1,
       kickoff_completed_at     DATETIME NULL,
       ai_generated_at          DATETIME NULL,
       approved_at              DATETIME NULL,
       approved_by              INT NULL,
       deployed_at              DATETIME NULL,
       deployed_url             VARCHAR(500) NULL,
       created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uk_intake_client (client_id),
       INDEX idx_intake_status (status),
       CONSTRAINT fk_intake_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS client_credentials (
       id          INT AUTO_INCREMENT PRIMARY KEY,
       client_id   INT NOT NULL,
       kind        ENUM('cpanel','sftp','wordpress','domain_registrar','google_business_profile','google_local_services','google_ads','facebook_business','yelp_for_business','custom') NOT NULL,
       label       VARCHAR(120) NULL,
       url         VARCHAR(255) NULL,
       username    VARCHAR(160) NULL,
       value_enc   BLOB NULL,
       notes       TEXT NULL,
       rotated_at  DATETIME NULL,
       expires_at  DATETIME NULL,
       created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       INDEX idx_credentials_client_kind (client_id, kind),
       CONSTRAINT fk_credentials_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS client_assets (
       id             INT AUTO_INCREMENT PRIMARY KEY,
       client_id      INT NOT NULL,
       source         ENUM('email_inbound','manual_upload') NOT NULL,
       category       ENUM('job','team','vehicle','equipment','before_after','logo','interior','exterior','other') NOT NULL DEFAULT 'other',
       original_name  VARCHAR(255) NULL,
       stored_name    VARCHAR(120) NOT NULL,
       mime           VARCHAR(80)  NOT NULL,
       size_bytes     INT NOT NULL,
       exif_json      JSON NULL,
       ai_description TEXT NULL,
       ai_tags_json   JSON NULL,
       ai_confidence  FLOAT NULL,
       approved       BOOLEAN DEFAULT FALSE,
       uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       INDEX idx_assets_client_category (client_id, category),
       CONSTRAINT fk_assets_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$db = crm_db();
$ok = 0; $skipped = 0; $errors = [];
foreach ($statements as $i => $sql) {
    $label = "stmt #" . ($i + 1);
    try {
        $db->exec($sql);
        echo "[ok]   {$label}\n";
        $ok++;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // 1060 = duplicate column, 1061 = duplicate key, 1050 = table exists,
        // 1091 = can't drop key (already gone). All idempotent skips.
        if (preg_match('/1060|1061|1050|1091|Duplicate column|Duplicate key|already exists/i', $msg)) {
            echo "[skip] {$label} — already applied\n";
            $skipped++;
        } else {
            echo "[FAIL] {$label}: " . substr($msg, 0, 200) . "\n";
            $errors[] = $msg;
        }
    }
}

echo "\nSummary: ok={$ok} skipped={$skipped} errors=" . count($errors) . "\n";

if ($errors) {
    echo "\nErrors persist — DO NOT delete this file. Resolve and re-run.\n";
    exit(1);
}

if (@unlink(__FILE__)) {
    echo "\n[ok] _run-v11.php self-destructed.\n";
} else {
    echo "\n[warn] could not remove _run-v11.php — delete via cPanel File Manager.\n";
}
echo "\nDone. The 5-sprint pipeline is now backed by the v11 schema.\n";
