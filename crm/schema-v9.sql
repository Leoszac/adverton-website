-- v9: add ebook_growth_engine to leads.source ENUM
-- Required because the public ebook landing form persists leads with this source.
-- Without this ALTER, MySQL silently rejects the INSERT and the lead never makes
-- it to the CRM (the public form continues to work because crm_insertLead is
-- wrapped in @ to never break lead capture).

ALTER TABLE leads
  MODIFY COLUMN source ENUM(
    'audit_auto',
    'audit_manual',
    'contact_form',
    'inbound_call',
    'manual',
    'ebook_growth_engine'
  ) NOT NULL;
