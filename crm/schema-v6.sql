-- Adverton CRM — schema v6 (additive on top of schema-v5.sql).
-- Run once in phpMyAdmin AFTER schema-v5.sql.
-- Adds per-user email sender configuration (overrides global CRM_FROM_ADDRESS).

ALTER TABLE users
  ADD COLUMN email_from      VARCHAR(160) NULL,
  ADD COLUMN email_from_name VARCHAR(120) NULL,
  ADD COLUMN email_reply_to  VARCHAR(160) NULL;
