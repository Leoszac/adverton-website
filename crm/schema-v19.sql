-- schema-v19.sql — Adverton Care: multi-user access per client (business).
-- Each team member is a care_users row with their own login token. The owner
-- adds/removes members. Replaces the single care_access token model (care_access
-- stays as a legacy fallback so old owner links keep working).

CREATE TABLE IF NOT EXISTS care_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  client_id     INT NOT NULL,
  name          VARCHAR(120) NULL,
  contact       VARCHAR(120) NULL,                  -- their cell (E.164) or email
  role          ENUM('owner','staff') NOT NULL DEFAULT 'staff',
  token         CHAR(48) NOT NULL,                  -- per-user magic-link token
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  TIMESTAMP NULL,
  UNIQUE KEY uq_token (token),
  INDEX idx_client (client_id),
  CONSTRAINT fk_care_user_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
