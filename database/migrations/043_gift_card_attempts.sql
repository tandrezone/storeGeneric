-- ============================================================
-- Failed "Apply gift card" attempts per IP, so gift card codes can't be
-- guessed at checkout (rows older than a day are pruned as it goes).
-- ============================================================

CREATE TABLE IF NOT EXISTS gift_card_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gift_card_attempts_ip_time (ip_address, created_at)
) ENGINE=InnoDB;
