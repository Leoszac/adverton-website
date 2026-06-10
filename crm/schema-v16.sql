-- schema-v16.sql — Adverton Care (telephony product: missed-call + reviews).
-- Additive + idempotent. Apply after schema-v15.sql.

CREATE TABLE IF NOT EXISTS care_numbers (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  client_id       INT NOT NULL,
  twilio_number   VARCHAR(20) NOT NULL,            -- E.164, the public business number
  twilio_sid      VARCHAR(60) NULL,                -- IncomingPhoneNumber SID
  forward_to      VARCHAR(20) NOT NULL,            -- contractor's real cell, E.164
  ring_seconds    SMALLINT NOT NULL DEFAULT 20,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_twilio_number (twilio_number),
  INDEX idx_client (client_id),
  CONSTRAINT fk_care_num_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS care_calls (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  client_id       INT NOT NULL,
  twilio_number   VARCHAR(20) NOT NULL,            -- the Care number that was called
  caller          VARCHAR(20) NOT NULL,            -- the customer's number (From)
  call_sid        VARCHAR(60) NULL,
  disposition     ENUM('missed','answered','voicemail','failed') NOT NULL,
  duration        INT NOT NULL DEFAULT 0,
  textback_sent   TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client_created (client_id, created_at),
  INDEX idx_caller (caller),
  UNIQUE KEY uq_call_sid (call_sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS care_sms (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  client_id       INT NOT NULL,
  direction       ENUM('out','in') NOT NULL,
  twilio_number   VARCHAR(20) NOT NULL,
  counterparty    VARCHAR(20) NOT NULL,            -- the customer's number
  body            TEXT NULL,
  message_sid     VARCHAR(60) NULL,
  kind            ENUM('textback','relay','review','review_reminder','other') NOT NULL DEFAULT 'other',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_client_created (client_id, created_at),
  INDEX idx_counterparty (counterparty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS care_review_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  client_id       INT NOT NULL,
  customer_phone  VARCHAR(20) NOT NULL,
  customer_name   VARCHAR(120) NULL,
  source          ENUM('call_tap','csv','sms','integration','manual') NOT NULL DEFAULT 'manual',
  status          ENUM('queued','sent','reminded','done','stopped','failed') NOT NULL DEFAULT 'queued',
  send_after      DATETIME NULL,                   -- earliest first-send time
  sent_at         DATETIME NULL,
  reminded_at     DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_client (client_id),
  INDEX idx_status_after (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opt-out suppression (replied STOP). Global per phone — never text again.
CREATE TABLE IF NOT EXISTS care_optouts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  phone        VARCHAR(20) NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
