-- Adverton CRM — schema v7 (additive on top of schema-v6.sql).
-- Run once in phpMyAdmin AFTER schema-v6.sql.
-- Adds a runtime-editable `settings` key/value table so the founder can
-- configure webhook secrets etc. from the CRM UI without SFTP-editing
-- crm-config.php. DB credentials still live in crm-config.php.

CREATE TABLE IF NOT EXISTS settings (
  `key`        VARCHAR(60) NOT NULL PRIMARY KEY,
  `value`      TEXT NULL,
  updated_by   INT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
