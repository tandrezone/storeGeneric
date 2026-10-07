-- ============================================================
-- Site analytics: page views, product detail views, and the
-- checkout funnel (shipping step reached, payment redirect reached).
-- ============================================================

CREATE TABLE IF NOT EXISTS page_views (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL,
    event_type ENUM('visit', 'product_view', 'checkout_shipping', 'checkout_payment') NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pageviews_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_pageviews_event_type (event_type),
    INDEX idx_pageviews_product_id (product_id),
    INDEX idx_pageviews_created_at (created_at)
) ENGINE=InnoDB;
