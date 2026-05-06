-- Adverton CRM — schema v4 (additive on top of schema-v3.sql).
-- Run once in phpMyAdmin AFTER schema-v3.sql.
-- Adds: file attachments, email tracking, lost-reason, BANT qualification.

-- ========== Lost-reason + BANT on leads ==========

ALTER TABLE leads
  ADD COLUMN lost_reason       ENUM('price','not_a_fit','competitor','no_response','timing','other') NULL AFTER status,
  ADD COLUMN lost_reason_note  VARCHAR(255) NULL AFTER lost_reason,
  ADD COLUMN won_reason_note   VARCHAR(255) NULL AFTER lost_reason_note,

  ADD COLUMN bant_budget       ENUM('yes','no','unsure') NULL,
  ADD COLUMN bant_authority    ENUM('yes','no','unsure') NULL,
  ADD COLUMN bant_need         ENUM('yes','no','unsure') NULL,
  ADD COLUMN bant_timeline     ENUM('asap','30d','90d','later','none') NULL,
  ADD COLUMN bant_notes        TEXT NULL,
  ADD INDEX idx_lost_reason   (lost_reason);

-- ========== File attachments ==========

CREATE TABLE IF NOT EXISTS lead_files (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  lead_id         INT NOT NULL,
  uploaded_by     INT NULL,
  original_name   VARCHAR(255) NOT NULL,
  stored_name     VARCHAR(120) NOT NULL,    -- random + safe extension
  mime            VARCHAR(120) NOT NULL,
  size_bytes      BIGINT       NOT NULL,
  uploaded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lead (lead_id, uploaded_at DESC),
  CONSTRAINT fk_file_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Email tracking ==========

CREATE TABLE IF NOT EXISTS email_sends (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  lead_id         INT NOT NULL,
  template_id     INT NULL,
  user_id         INT NULL,
  open_token      VARCHAR(40) NOT NULL UNIQUE,
  click_token     VARCHAR(40) NOT NULL UNIQUE,
  subject         VARCHAR(255) NULL,
  sent_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  first_opened_at  DATETIME NULL,
  last_opened_at   DATETIME NULL,
  open_count       INT NOT NULL DEFAULT 0,
  first_clicked_at DATETIME NULL,
  last_clicked_at  DATETIME NULL,
  click_count      INT NOT NULL DEFAULT 0,
  INDEX idx_lead (lead_id, sent_at DESC),
  CONSTRAINT fk_send_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
