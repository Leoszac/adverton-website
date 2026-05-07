-- Adverton CRM — schema v10 (additive, run AFTER v9).
--
-- Adds three new lead source values:
--   - referral       (word-of-mouth / partner referrals)
--   - affiliate      (paid affiliates)
--   - csv_import     (bulk import via /crm/lead-import.php)
--
-- Idempotent re-run is safe: MySQL just replays the ENUM definition.

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
    'csv_import'
  ) NOT NULL;
