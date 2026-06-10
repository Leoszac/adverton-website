-- schema-v17.sql — Adverton Care: passwordless dashboard access tokens.
-- Additive + idempotent. Apply after schema-v16.sql.

CREATE TABLE IF NOT EXISTS care_access (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  client_id     INT NOT NULL,
  token         CHAR(48) NOT NULL,                 -- bin2hex(random_bytes(24))
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  TIMESTAMP NULL,
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_client (client_id),
  CONSTRAINT fk_care_access_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
