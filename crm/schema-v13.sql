-- Adverton CRM — schema v13 (additive, run AFTER v12).
--
-- Cold-calling pipeline. Separate from `leads` to keep cold prospects
-- (Outscraper / online list scrapes) out of the inbound-lead pipeline.
-- Conversion path: a VA marks a prospect "interested" → INSERT into leads
-- with source='cold_call', UPDATE cold_prospects.converted_lead_id.
--
-- DNC scrub (DNCScrub.com / similar): every imported phone is checked
-- against National DNC + state lists + wireless flag. Blocked numbers
-- stay in the table with dnc_status='blocked_*' (compliance audit trail,
-- dedup on re-import, no re-scrub cost). The VA's view filters them out
-- with WHERE dnc_status='clean'.
--
-- Run once. Idempotent.
-- ─────────────────────────────────────────────────────────────────────

-- Add 'cold_call' to the lead source ENUM (mirrors schema-v10 pattern).
ALTER TABLE leads
  MODIFY COLUMN source ENUM(
    'audit_auto',
    'audit_manual',
    'contact_form',
    'inbound_call',
    'manual',
    'ebook_growth_engine',
    'referral',
    'affiliate',
    'csv_import',
    'cold_email_instantly',
    'cold_call'
  ) NOT NULL;

-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cold_prospects (
  id                INT AUTO_INCREMENT PRIMARY KEY,

  -- contact (phone is the primary identifier; required + normalized to E.164)
  phone             VARCHAR(20)  NOT NULL,
  business_name     VARCHAR(160) NULL,
  email             VARCHAR(160) NULL,
  contact_name      VARCHAR(160) NULL,
  trade             VARCHAR(80)  NULL,
  city              VARCHAR(80)  NULL,
  state             VARCHAR(40)  NULL,
  website           VARCHAR(255) NULL,
  gbp_url           VARCHAR(500) NULL,
  notes             TEXT         NULL,

  -- provenance
  source            ENUM('outscraper','manual_csv','online','other')
                    NOT NULL DEFAULT 'manual_csv',
  imported_batch_id VARCHAR(32)  NULL,
  imported_by       INT          NULL,

  -- DNC scrub state
  dnc_status        ENUM(
    'pending',
    'clean',
    'blocked_federal',
    'blocked_state',
    'blocked_wireless',
    'blocked_litigator',
    'blocked_internal',
    'scrub_error'
  ) NOT NULL DEFAULT 'pending',
  dnc_scrubbed_at   DATETIME     NULL,
  dnc_meta_json     JSON         NULL,

  -- call state
  call_status       ENUM(
    'not_called',
    'no_answer',
    'voicemail',
    'busy',
    'wrong_number',
    'not_interested',
    'interested',
    'converted',
    'dnc_requested',
    'dead'
  ) NOT NULL DEFAULT 'not_called',
  call_attempts     SMALLINT     NOT NULL DEFAULT 0,
  last_called_at    DATETIME     NULL,
  last_called_by    INT          NULL,

  -- conversion link
  converted_lead_id INT          NULL,
  converted_at      DATETIME     NULL,

  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_cold_prospects_phone (phone),
  INDEX idx_cold_prospects_dnc_status  (dnc_status),
  INDEX idx_cold_prospects_call_status (call_status),
  INDEX idx_cold_prospects_batch       (imported_batch_id),
  INDEX idx_cold_prospects_dialable    (dnc_status, call_status, call_attempts),
  INDEX idx_cold_prospects_scrubbed_at (dnc_scrubbed_at),
  INDEX idx_cold_prospects_state_trade (state, trade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
