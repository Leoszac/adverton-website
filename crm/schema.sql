-- Adverton CRM schema. Run once in phpMyAdmin against the DB created in cPanel
-- (e.g. advertonnet_crm). Safe to re-run: uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS users (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(60)  NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  display_name    VARCHAR(120) NOT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id              INT AUTO_INCREMENT PRIMARY KEY,

  -- where it came from
  source          ENUM('audit_auto','audit_manual','contact_form') NOT NULL,
  source_page     VARCHAR(255) NULL,        -- HTTP_REFERER (e.g. /plumbing-tampa.html)

  -- contact data
  first_name      VARCHAR(80)  NULL,
  last_name       VARCHAR(80)  NULL,
  email           VARCHAR(160) NULL,
  phone           VARCHAR(40)  NULL,
  business_name   VARCHAR(160) NULL,
  trade           VARCHAR(80)  NULL,
  city_state      VARCHAR(120) NULL,
  website         VARCHAR(255) NULL,

  -- audit-specific
  gbp_url         TEXT         NULL,
  audit_score     TINYINT      NULL,        -- 0..100, NULL for non-audit
  audit_id        VARCHAR(32)  NULL,        -- ties back to /home2/advertonnet/logs/audit.log

  -- contact-form-specific
  revenue         VARCHAR(40)  NULL,
  message         TEXT         NULL,

  -- pipeline
  status          ENUM('new','contacted','qualified','proposal','won','lost') NOT NULL DEFAULT 'new',
  owner_user_id   INT          NULL,
  notes           TEXT         NULL,

  -- meta
  ip              VARCHAR(45)  NULL,
  user_agent      VARCHAR(255) NULL,
  utm_source      VARCHAR(80)  NULL,
  utm_medium      VARCHAR(80)  NULL,
  utm_campaign    VARCHAR(80)  NULL,

  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_status     (status),
  INDEX idx_source     (source),
  INDEX idx_created_at (created_at),
  INDEX idx_email      (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
