-- Adverton CRM — schema v2 (additive on top of schema.sql).
-- Run once in phpMyAdmin AFTER schema.sql.
-- Adds: deal/forecast fields on leads, activity timeline, tasks.

-- ========== Deal & forecast fields on leads ==========

ALTER TABLE leads
  ADD COLUMN monthly_fee       DECIMAL(8,2)  NULL AFTER notes,
  ADD COLUMN ad_budget         DECIMAL(10,2) NULL AFTER monthly_fee,
  ADD COLUMN mgmt_fee_pct      DECIMAL(5,2)  NULL AFTER ad_budget,
  ADD COLUMN expected_close_at DATE          NULL AFTER mgmt_fee_pct,
  ADD COLUMN temperature       ENUM('hot','warm','cold') NULL AFTER expected_close_at,
  ADD INDEX idx_temperature    (temperature),
  ADD INDEX idx_expected_close (expected_close_at);

-- ========== Activity timeline ==========

CREATE TABLE IF NOT EXISTS lead_activities (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  lead_id         INT NOT NULL,
  user_id         INT NULL,
  type            ENUM('note','call','email','sms','meeting','status_change','system') NOT NULL,
  disposition     VARCHAR(40) NULL,
  body            TEXT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lead_created (lead_id, created_at DESC),
  INDEX idx_type         (type),
  CONSTRAINT fk_activity_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Tasks (follow-ups, callbacks) ==========

CREATE TABLE IF NOT EXISTS tasks (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  lead_id         INT NULL,
  assigned_to     INT NULL,
  created_by      INT NULL,
  title           VARCHAR(255) NOT NULL,
  notes           TEXT NULL,
  due_at          DATETIME NOT NULL,
  done_at         DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_due           (due_at, done_at),
  INDEX idx_assignee_due  (assigned_to, due_at),
  INDEX idx_lead          (lead_id),
  CONSTRAINT fk_task_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
