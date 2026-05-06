-- Adverton CRM — schema v8 (additive on top of schema-v7.sql).
-- Persists the last Stripe Checkout link sent to a client (for resending).

ALTER TABLE clients
  ADD COLUMN stripe_checkout_url        TEXT NULL,
  ADD COLUMN stripe_checkout_session_id VARCHAR(120) NULL,
  ADD COLUMN stripe_checkout_sent_at    DATETIME NULL;
