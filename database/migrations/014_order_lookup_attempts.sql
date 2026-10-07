-- ============================================================
-- Failed storefront order lookups (/order/track) per IP, for rate limiting.
-- ============================================================

CREATE TABLE IF NOT EXISTS order_lookup_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_lookup_ip_time (ip_address, created_at)
) ENGINE=InnoDB;
