-- Adverton CRM — schema v5 (consolidated, additive on top of schema-v4.sql).
-- Run once in phpMyAdmin AFTER schema-v4.sql.
--
-- Adds: clients (post-won), client_events, commission_events, sequences,
-- sequence_steps, sequence_enrollments, routing_rules, push_subscriptions.
-- ALTERs: users +role/totp, leads.source +inbound_call.

-- ========== Clients (post-won subscription record) ==========

CREATE TABLE IF NOT EXISTS clients (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  lead_id                INT NULL,
  business_name          VARCHAR(160) NULL,
  trade                  VARCHAR(80)  NULL,
  primary_email          VARCHAR(160) NULL,
  primary_phone          VARCHAR(40)  NULL,
  contract_start_at      DATE NOT NULL,
  contract_end_at        DATE NOT NULL,
  monthly_fee            DECIMAL(8,2)  NOT NULL DEFAULT 799.00,
  addons                 JSON NULL,
  ad_budget              DECIMAL(10,2) NULL,
  mgmt_fee_pct           DECIMAL(5,2)  DEFAULT 0,
  status                 ENUM('onboarding','active','past_due','paused','cancelled','renewed') NOT NULL DEFAULT 'onboarding',
  payment_status         ENUM('current','past_due','failed','cancelled') NOT NULL DEFAULT 'current',
  installment_count      TINYINT NOT NULL DEFAULT 0,
  renewal_count          TINYINT NOT NULL DEFAULT 0,
  buyout_eligible        BOOLEAN DEFAULT TRUE,
  cancellation_reason    VARCHAR(60)  NULL,
  cancellation_note      VARCHAR(255) NULL,
  account_manager_id     INT NULL,
  stripe_customer_id     VARCHAR(60) NULL,
  stripe_subscription_id VARCHAR(60) NULL,
  pandadoc_doc_id        VARCHAR(60) NULL,
  health_score           TINYINT NULL,
  notes                  TEXT NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status            (status),
  INDEX idx_contract_end      (contract_end_at),
  INDEX idx_payment_status    (payment_status),
  INDEX idx_health            (health_score),
  INDEX idx_account_manager   (account_manager_id),
  CONSTRAINT fk_client_lead   FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  client_id  INT NOT NULL,
  user_id    INT NULL,
  type       ENUM('payment_succeeded','payment_failed','subscription_changed',
                  'status_change','renewal_notice','renewed',
                  'addon_added','addon_removed',
                  'buyout_requested','migration_delivered','note') NOT NULL,
  body       TEXT NULL,
  meta       JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client_created (client_id, created_at DESC),
  CONSTRAINT fk_event_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Commission tracking ==========

CREATE TABLE IF NOT EXISTS commission_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  lead_id     INT NULL,
  client_id   INT NULL,
  type        ENUM('demo','close_signed','close_day90','clawback') NOT NULL,
  amount      DECIMAL(8,2) NOT NULL,
  paid_at     DATETIME NULL,
  notes       VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_created (user_id, created_at DESC),
  INDEX idx_type         (type),
  INDEX idx_lead         (lead_id),
  INDEX idx_client       (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Drip sequences ==========

CREATE TABLE IF NOT EXISTS sequences (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,
  trigger_event   VARCHAR(60)  NOT NULL,
  trigger_value   VARCHAR(60)  NULL,
  active          BOOLEAN NOT NULL DEFAULT TRUE,
  created_by      INT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_active_trigger (active, trigger_event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequence_steps (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  sequence_id  INT NOT NULL,
  step_order   TINYINT NOT NULL,
  delay_days   SMALLINT NOT NULL DEFAULT 0,
  action       ENUM('send_template','create_task','add_tag','remove_tag') NOT NULL,
  payload      JSON NOT NULL,
  INDEX idx_seq_order (sequence_id, step_order),
  CONSTRAINT fk_step_seq FOREIGN KEY (sequence_id) REFERENCES sequences(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequence_enrollments (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  sequence_id       INT NOT NULL,
  lead_id           INT NOT NULL,
  current_step      TINYINT NOT NULL DEFAULT 0,
  next_run_at       DATETIME NOT NULL,
  completed_at      DATETIME NULL,
  unenrolled_reason VARCHAR(60) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_seq_lead (sequence_id, lead_id),
  INDEX idx_next_run    (next_run_at, completed_at),
  INDEX idx_lead        (lead_id),
  CONSTRAINT fk_enr_seq  FOREIGN KEY (sequence_id) REFERENCES sequences(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_lead FOREIGN KEY (lead_id)     REFERENCES leads(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Lead routing rules ==========

CREATE TABLE IF NOT EXISTS routing_rules (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  priority      SMALLINT NOT NULL DEFAULT 100,
  match_trade   VARCHAR(80)  NULL,
  match_source  VARCHAR(40)  NULL,
  match_state   VARCHAR(40)  NULL,
  match_temp    VARCHAR(10)  NULL,
  assign_to     INT NOT NULL,
  active        BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_priority (priority, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Push subscriptions (Web Push) ==========

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  endpoint   VARCHAR(500) NOT NULL,
  p256dh     VARCHAR(255) NOT NULL,
  auth_key   VARCHAR(80)  NOT NULL,
  ua         VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_endpoint (endpoint(191)),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== ALTERs ==========

ALTER TABLE users
  ADD COLUMN role         ENUM('founder','sales','operator') NOT NULL DEFAULT 'sales',
  ADD COLUMN totp_secret  VARCHAR(32) NULL,
  ADD COLUMN totp_enabled BOOLEAN NOT NULL DEFAULT FALSE;

-- Promote first user to founder (assumes 'leandro' was seeded first)
UPDATE users SET role = 'founder' WHERE username = 'leandro';

ALTER TABLE leads
  MODIFY COLUMN source ENUM('audit_auto','audit_manual','contact_form','inbound_call','manual') NOT NULL;
