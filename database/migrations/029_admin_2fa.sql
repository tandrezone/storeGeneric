-- ============================================================
-- Optional two-factor authentication (TOTP, RFC 6238) for admin users
-- (Admin → My account).
--  - totp_secret: base32 shared secret, set only once 2FA is confirmed.
--  - totp_enabled: 1 when logins need a code after the password.
--  - totp_last_step: last accepted 30-second time step, so a code
--    can't be used twice (replay protection).
--  - admin_recovery_codes: one-time recovery codes; only the SHA-256
--    of each code is stored, used_at marks a spent one.
-- ============================================================

ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL COMMENT 'base32 TOTP secret (never logged)' AFTER locale,
    ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
    ADD COLUMN IF NOT EXISTS totp_last_step BIGINT UNSIGNED DEFAULT NULL COMMENT 'last accepted time step (replay protection)' AFTER totp_enabled;

CREATE TABLE IF NOT EXISTS admin_recovery_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the normalised code',
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_recovery_user FOREIGN KEY (user_id)
        REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_admin_recovery_lookup (user_id, code_hash)
) ENGINE=InnoDB;
