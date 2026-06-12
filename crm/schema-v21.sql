-- schema-v21.sql — Adverton Care: per-sender opt-out (TCPA/CTIA correctness).
--
-- Opt-out must be per (client, phone): a customer who texts STOP to one
-- contractor must NOT be suppressed for every other Adverton client, and a
-- START to one must not re-enable texts from a different contractor.
-- client_id = 0 is a reserved GLOBAL hard-suppress (abuse) that applies to all.
--
-- ⚠️ APPLY-ORDER: run this BEFORE deploying the matching code
-- (care/lib/care.php care_isOptedOut + care/lib/flows.php care_optOut/optIn).
-- The new code reads/writes care_optouts.client_id; without this column those
-- queries throw (they're caught, but opt-out silently stops recording).
--
-- Existing rows (if any) get client_id = 0 → preserved as global suppressions.

ALTER TABLE care_optouts ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER id;
ALTER TABLE care_optouts DROP INDEX uq_phone, ADD UNIQUE KEY uq_client_phone (client_id, phone);
