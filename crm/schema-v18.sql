-- schema-v18.sql — Adverton Care: the mega-simple job tracker (the "no CRM?
-- use our list" fallback). Leads from missed calls auto-populate; moving a job
-- to "done" auto-fires the review request. Additive + idempotent.

CREATE TABLE IF NOT EXISTS care_jobs (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  client_id      INT NOT NULL,
  name           VARCHAR(120) NULL,
  phone          VARCHAR(20) NOT NULL,                 -- E.164
  address        VARCHAR(255) NULL,
  status         ENUM('lead','scheduled','done','lost') NOT NULL DEFAULT 'lead',
  source         ENUM('call','manual') NOT NULL DEFAULT 'manual',
  review_queued  TINYINT(1) NOT NULL DEFAULT 0,        -- so 'done' only fires once
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_client_status (client_id, status),
  INDEX idx_client_phone (client_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
