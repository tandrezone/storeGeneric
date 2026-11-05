-- ============================================================
-- Customer engagement.
--  wishlist_items        a signed-in customer's saved products
--  stock_alerts          "email me when it's back" for out-of-stock products;
--                        one row per email and product, notified once
--  newsletter_subscribers / newsletter_campaigns
--                        double opt-in list and the campaigns sent to it
--  carts.customer_id / reminded_at
--                        who a cart belongs to (signed-in customers) and whether
--                        the abandoned-cart reminder has gone out
-- ============================================================

CREATE TABLE IF NOT EXISTS wishlist_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_wishlist (customer_id, product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    locale VARCHAR(10) DEFAULT NULL,
    token CHAR(32) NOT NULL COMMENT 'cancel link',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_stock_alerts_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_stock_alert (product_id, email),
    UNIQUE KEY uniq_stock_alert_token (token),
    INDEX idx_stock_alerts_pending (notified_at, product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    locale VARCHAR(10) DEFAULT NULL,
    token CHAR(32) NOT NULL COMMENT 'confirm / unsubscribe link',
    confirm_sent_at DATETIME DEFAULT NULL COMMENT 'last confirmation email (resend throttle)',
    confirmed_at DATETIME DEFAULT NULL COMMENT 'double opt-in; NULL = not confirmed yet',
    unsubscribed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_newsletter_email (email),
    UNIQUE KEY uniq_newsletter_token (token),
    INDEX idx_newsletter_active (confirmed_at, unsubscribed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS newsletter_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    recipients INT UNSIGNED NOT NULL DEFAULT 0,
    sent_by VARCHAR(190) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE carts
    ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED DEFAULT NULL AFTER session_id,
    ADD COLUMN IF NOT EXISTS reminded_at DATETIME DEFAULT NULL AFTER customer_id;
