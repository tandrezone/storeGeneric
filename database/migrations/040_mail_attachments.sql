-- ============================================================
-- Email attachments (invoice / credit-note PDFs). Queued mail keeps them as a
-- JSON list of {filename, type, data (base64)}.
-- ============================================================

ALTER TABLE mail_queue
    ADD COLUMN IF NOT EXISTS attachments MEDIUMTEXT DEFAULT NULL COMMENT 'JSON [{filename, type, data(base64)}]' AFTER body_text;
