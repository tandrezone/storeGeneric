-- ============================================================
-- Product reviews. Signed-in customers rate a product 1-5 with an optional
-- title and text; one review per customer and product. New reviews wait for
-- approval in Admin → Reviews unless Settings → reviews_auto_approve is on.
-- verified = the customer has a paid order with this product.
-- author is a snapshot ("Maria S."); deleting an account clears customer_id
-- and author but keeps the (anonymous) review.
-- ============================================================

CREATE TABLE IF NOT EXISTS product_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    author VARCHAR(80) NOT NULL DEFAULT '',
    rating TINYINT UNSIGNED NOT NULL,
    title VARCHAR(120) DEFAULT NULL,
    body TEXT DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_reviews_customer_product (product_id, customer_id),
    INDEX idx_reviews_product_status (product_id, status, created_at),
    INDEX idx_reviews_status (status, created_at)
) ENGINE=InnoDB;
