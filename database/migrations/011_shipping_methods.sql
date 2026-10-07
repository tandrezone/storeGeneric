-- ============================================================
-- Shipping methods move from a hardcoded list (Order::SHIPPING_METHODS)
-- to a table managed in the admin panel. Seeds the two methods the
-- code used to define, so existing orders keep resolving.
-- ============================================================

CREATE TABLE IF NOT EXISTS shipping_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'stored on orders.shipping_method',
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL COMMENT 'e.g. delivery estimate, shown at checkout',
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    free_over DECIMAL(10,2) DEFAULT NULL COMMENT 'subtotal at or above which shipping is free; NULL = never',
    countries VARCHAR(500) DEFAULT NULL COMMENT 'comma-separated country names/codes; NULL = all countries',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shipping_active_sort (is_active, sort_order)
) ENGINE=InnoDB;

INSERT IGNORE INTO shipping_methods (code, name, description, cost, sort_order) VALUES
    ('standard', 'Standard Shipping', '5-7 business days', 0.00, 1),
    ('express', 'Express Shipping', '1-2 business days', 9.99, 2);

ALTER TABLE orders
    ADD COLUMN shipping_label VARCHAR(150) DEFAULT NULL AFTER shipping_method;

UPDATE orders o
JOIN shipping_methods s ON s.code = o.shipping_method
SET o.shipping_label = CONCAT(s.name, ' (', s.description, ')')
WHERE o.shipping_label IS NULL;
