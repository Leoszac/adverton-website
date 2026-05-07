-- Adverton CRM — schema v11 (additive, run AFTER v10).
--
-- Foundation for the client-onboarding pipeline (pre-contract form,
-- kickoff wizard, AI generation, photo intake, deploy adapter).
--
-- Run once. Idempotent: ALTERs use IF EXISTS-aware patterns where MySQL
-- supports them; CREATE TABLE uses IF NOT EXISTS.
-- ─────────────────────────────────────────────────────────────────────

-- ALTER leads: magic-link token for pre-contract flow.
-- The token lives on the lead until the client row is created (at form submit).
ALTER TABLE leads
  ADD COLUMN magic_token            CHAR(64) NULL,
  ADD COLUMN magic_token_expires_at DATETIME NULL,
  ADD COLUMN pre_contract_sent_at   DATETIME NULL,
  ADD UNIQUE KEY uk_leads_magic_token (magic_token);

-- ALTER clients: billing fields captured by the pre-contract form,
-- plus magic_token for the kickoff-wizard handoff.
ALTER TABLE clients
  ADD COLUMN legal_entity_name        VARCHAR(255) NULL AFTER business_name,
  ADD COLUMN billing_email            VARCHAR(160) NULL AFTER primary_email,
  ADD COLUMN billing_address          TEXT         NULL,
  ADD COLUMN billing_city             VARCHAR(80)  NULL,
  ADD COLUMN billing_state            VARCHAR(40)  NULL,
  ADD COLUMN billing_zip              VARCHAR(20)  NULL,
  ADD COLUMN tax_id                   VARCHAR(40)  NULL,
  ADD COLUMN authorized_signer        VARCHAR(160) NULL,
  ADD COLUMN signer_role              VARCHAR(80)  NULL,
  ADD COLUMN pre_contract_completed_at DATETIME    NULL,
  ADD COLUMN magic_token              CHAR(64)     NULL,
  ADD COLUMN magic_token_expires_at   DATETIME     NULL,
  ADD UNIQUE KEY uk_clients_magic_token (magic_token),
  ADD INDEX idx_pre_contract_status   (pre_contract_completed_at);

-- ─────────────────────────────────────────────────────────────────────
-- Kickoff intake (Sprint 1) — answers to the 8-step wizard.
-- One row per client. Filled by client async (magic-link) or by the
-- account manager during the kickoff call. Step-level save+resume via
-- current_step. AI-generated copy cached in ai_drafts_json.
CREATE TABLE IF NOT EXISTS client_intake (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  client_id                INT NOT NULL,
  -- Step 1: Business basics
  display_name             VARCHAR(160) NULL,
  tagline                  VARCHAR(255) NULL,
  story_short              TEXT NULL,
  story_long               TEXT NULL,
  -- Step 2: Contact + service area
  service_area_json        JSON NULL,
  -- Step 3: Services + hours
  services_json            JSON NULL,
  hours_regular_json       JSON NULL,
  emergency_24_7           BOOLEAN DEFAULT FALSE,
  -- Step 4: Trust signals
  license_number           VARCHAR(80)  NULL,
  insurance_carrier        VARCHAR(160) NULL,
  years_in_business        TINYINT      NULL,
  certifications_json      JSON NULL,
  reviews_links_json       JSON NULL,
  -- Step 5: Visual
  photos_drive_url         VARCHAR(500) NULL,
  brand_colors_json        JSON NULL,
  brand_logo_path          VARCHAR(255) NULL,
  -- Step 6: Tone & goals
  competitors_admired_json JSON NULL,
  primary_goal             ENUM('more_calls','more_bookings','more_reviews','brand_awareness') NULL,
  -- Step 7: Template choice
  template_choice          ENUM('trust_first','speed_first','story_first') NULL,
  -- Step 9: Hosting & domain (Sprint 4)
  hosting_status           ENUM('client_has','adverton_provisions','unknown') NULL,
  domain_status            ENUM('client_has','adverton_buys','unknown') NULL,
  -- AI generation output (cached)
  ai_drafts_json           JSON NULL,
  -- Status machine
  status                   ENUM(
    'not_started','in_progress','ready_for_ai',
    'ai_generated','pending_approval','approved',
    'provisioning_pending','deployed'
  ) NOT NULL DEFAULT 'not_started',
  current_step             TINYINT NOT NULL DEFAULT 1,
  kickoff_completed_at     DATETIME NULL,
  ai_generated_at          DATETIME NULL,
  approved_at              DATETIME NULL,
  approved_by              INT NULL,
  deployed_at              DATETIME NULL,
  deployed_url             VARCHAR(500) NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_intake_client (client_id),
  INDEX idx_intake_status (status),
  CONSTRAINT fk_intake_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- Encrypted credentials vault (Sprint 4).
-- One (client_id, kind) per row. value_enc is openssl_encrypt'd with
-- the master key from crm_config('CREDENTIALS_KEY'). Reads logged to
-- client_events.
CREATE TABLE IF NOT EXISTS client_credentials (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  client_id   INT NOT NULL,
  kind        ENUM(
    'cpanel','sftp','wordpress','domain_registrar',
    'google_business_profile','google_local_services','google_ads',
    'facebook_business','yelp_for_business','custom'
  ) NOT NULL,
  label       VARCHAR(120) NULL,
  url         VARCHAR(255) NULL,
  username    VARCHAR(160) NULL,
  value_enc   BLOB NULL,
  notes       TEXT NULL,
  rotated_at  DATETIME NULL,
  expires_at  DATETIME NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_credentials_client_kind (client_id, kind),
  CONSTRAINT fk_credentials_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- Photos sent by client via email or upload (Sprint 3).
-- AI Vision classification fills category/description/tags asynchronously.
CREATE TABLE IF NOT EXISTS client_assets (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  client_id      INT NOT NULL,
  source         ENUM('email_inbound','manual_upload') NOT NULL,
  category       ENUM(
    'job','team','vehicle','equipment','before_after',
    'logo','interior','exterior','other'
  ) NOT NULL DEFAULT 'other',
  original_name  VARCHAR(255) NULL,
  stored_name    VARCHAR(120) NOT NULL,
  mime           VARCHAR(80)  NOT NULL,
  size_bytes     INT NOT NULL,
  exif_json      JSON NULL,
  ai_description TEXT NULL,
  ai_tags_json   JSON NULL,
  ai_confidence  FLOAT NULL,
  approved       BOOLEAN DEFAULT FALSE,
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_assets_client_category (client_id, category),
  INDEX idx_assets_pending         ((CASE WHEN ai_description IS NULL THEN 1 ELSE 0 END)),
  CONSTRAINT fk_assets_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
