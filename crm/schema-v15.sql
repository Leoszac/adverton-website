-- schema v15 — add 'ebook_gbp' lead source (GBP guide lead magnet).
-- Mirror of CRM_LEAD_SOURCES in crm/lib/leads.php. Apply once on the live DB.

ALTER TABLE leads
  MODIFY COLUMN source ENUM(
    'audit_auto',
    'audit_manual',
    'contact_form',
    'inbound_call',
    'manual',
    'ebook_growth_engine',
    'ebook_gbp',
    'referral',
    'affiliate',
    'csv_import',
    'cold_email_instantly',
    'cold_call',
    'leos_contacts'
  ) NOT NULL;
