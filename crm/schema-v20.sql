-- schema-v20.sql — Adverton Care: website-form leads + a richer SMS kind.
-- 'web' lets website-form leads land in the job tracker (alongside call/manual).
-- 'lead' tags the contractor-alert SMS so reporting can tell it apart from
-- text-backs, relays and review requests.

ALTER TABLE care_jobs MODIFY source ENUM('call','manual','web') NOT NULL DEFAULT 'manual';
ALTER TABLE care_sms  MODIFY kind   ENUM('textback','relay','review','review_reminder','lead','other') NOT NULL DEFAULT 'other';
