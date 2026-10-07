-- ============================================================
-- Admin login throttling per username as well as per IP, so an
-- attacker rotating IP addresses can't keep guessing one account.
-- ============================================================

ALTER TABLE login_attempts
    ADD COLUMN IF NOT EXISTS username VARCHAR(190) DEFAULT NULL AFTER ip_address,
    ADD INDEX IF NOT EXISTS idx_login_attempts_user_time (username, created_at);
