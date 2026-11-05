-- ============================================================
-- Gift cards (Admin → Gift cards). A card has a code and a balance that
-- customers spend at checkout; whatever the card doesn't cover is paid with
-- the chosen payment method (orders.total is that remainder, 0 = fully paid
-- by card). gift_card_transactions is the ledger: issue, redeem, restore
-- (cancelled / expired / refunded orders give the amount back, once) and
-- manual adjustments.
-- ============================================================

CREATE TABLE IF NOT EXISTS gift_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    initial_amount DECIMAL(10,2) NOT NULL,
    balance DECIMAL(10,2) NOT NULL,
    expires_at DATETIME DEFAULT NULL COMMENT 'NULL = never',
    recipient_email VARCHAR(190) DEFAULT NULL,
    message VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(190) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_gift_cards_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gift_card_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gift_card_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED DEFAULT NULL,
    type ENUM('issue', 'redeem', 'restore', 'adjust') NOT NULL,
    amount DECIMAL(10,2) NOT NULL COMMENT 'signed: spending is negative',
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gct_card FOREIGN KEY (gift_card_id)
        REFERENCES gift_cards(id) ON DELETE CASCADE,
    INDEX idx_gct_card (gift_card_id, created_at)
) ENGINE=InnoDB;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS gift_card_code VARCHAR(40) DEFAULT NULL COMMENT 'gift card used (copy)' AFTER discount_amount,
    ADD COLUMN IF NOT EXISTS gift_card_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'paid with the gift card; total is what is left' AFTER gift_card_code,
    ADD COLUMN IF NOT EXISTS gift_card_counted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 while the order holds that amount of the card (given back once)' AFTER gift_card_amount;
