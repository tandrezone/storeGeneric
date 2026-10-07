-- ============================================================
-- Customer account tokens and rate limiting.
--  - customer_tokens: password-reset (1 hour) and email-verification
--    links. Only the SHA-256 of the token is stored; used_at makes each
--    one single-use. A verify token is for the email it was sent to.
--  - customer_auth_attempts: sign-ins, password-reset requests and
--    registrations per IP and per email (separate from the admin
--    login_attempts).
-- ============================================================

CREATE TABLE IF NOT EXISTS customer_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    purpose ENUM('reset', 'verify') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL COMMENT 'address the link was sent to',
    expires_at DATETIME NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_tokens_hash (token_hash),
    CONSTRAINT fk_customer_tokens_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_tokens_customer (customer_id, purpose)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_auth_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('login', 'reset', 'register') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_attempts_ip (kind, ip_address, created_at),
    INDEX idx_customer_attempts_email (kind, email, created_at)
) ENGINE=InnoDB;
