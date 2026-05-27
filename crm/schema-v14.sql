-- Adverton CRM — schema v14 (additive, run AFTER v13).
-- Adds 'leos_contacts' to the lead source ENUM.
-- Run once.

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
    'cold_call',
    'leos_contacts'
  ) NOT NULL;
