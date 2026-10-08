-- ============================================================
-- Discount codes (Admin → Discounts). Codes are stored upper-case and
-- matched case-insensitively. type: percent (value = 0-100), fixed
-- (value = amount off the subtotal) or free_shipping (value unused).
-- used_count is raised in the checkout transaction (row locked, so
-- usage_limit holds) and lowered again if the order is cancelled
-- before it is paid.
-- ============================================================

CREATE TABLE IF NOT EXISTS coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    type ENUM('percent', 'fixed', 'free_shipping') NOT NULL DEFAULT 'percent',
    value DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'percent (0-100) or amount off; unused for free_shipping',
    min_subtotal DECIMAL(10,2) DEFAULT NULL COMMENT 'items subtotal needed to use the code; NULL = any',
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    usage_limit INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    per_email_limit INT UNSIGNED DEFAULT NULL COMMENT 'uses per customer email; NULL = unlimited',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_coupons_code (code)
) ENGINE=InnoDB;
