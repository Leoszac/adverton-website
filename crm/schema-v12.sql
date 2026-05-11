-- Adverton CRM — schema v12 (additive, run AFTER v11).
--
-- Form submissions captured from deployed client websites. The contact form
-- in every crm/web-templates/*.php POSTs to /crm/client-form-submit.php which
-- inserts here and emails the client + operator.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS client_form_submissions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  client_id   INT NOT NULL,
  name        VARCHAR(160) NULL,
  email       VARCHAR(160) NULL,
  phone       VARCHAR(40)  NULL,
  message     TEXT         NULL,
  source_url  VARCHAR(500) NULL,
  ip          VARCHAR(45)  NULL,
  ua          VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cfs_client (client_id, created_at),
  CONSTRAINT fk_cfs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
