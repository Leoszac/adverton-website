-- Adverton CRM — schema v3 (additive on top of schema-v2.sql).
-- Run once in phpMyAdmin AFTER schema-v2.sql.
-- Adds: tags, email_templates, last_contacted_at on leads.

-- ========== Tags ==========

CREATE TABLE IF NOT EXISTS tags (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(60)  NOT NULL UNIQUE,
  color        VARCHAR(20)  NOT NULL DEFAULT '#6d28d9',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lead_tags (
  lead_id      INT NOT NULL,
  tag_id       INT NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lead_id, tag_id),
  INDEX idx_tag (tag_id),
  CONSTRAINT fk_lt_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_lt_tag  FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Email templates ==========

CREATE TABLE IF NOT EXISTS email_templates (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body         TEXT         NOT NULL,
  created_by   INT          NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Last-contacted timestamp on leads ==========

ALTER TABLE leads
  ADD COLUMN last_contacted_at DATETIME NULL AFTER updated_at,
  ADD INDEX idx_last_contacted (last_contacted_at);

-- ========== Per-user "last seen lead" pointer (for the "X new" badge) ==========

ALTER TABLE users
  ADD COLUMN last_seen_lead_id INT NULL DEFAULT NULL;
