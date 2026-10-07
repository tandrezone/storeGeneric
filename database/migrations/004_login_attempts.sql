-- ============================================================
-- Tracks failed admin login attempts per IP so AdminAuth can lock
-- out repeated guessing (brute-force protection).
-- ============================================================

CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, created_at)
) ENGINE=InnoDB;
