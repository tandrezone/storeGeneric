-- ============================================================
-- Return requests (RMA). A customer asks to return items of a shipped order
-- within the return window (Settings: return_window_days, default 14, 0 = no
-- returns); staff approve or reject, mark the goods received, and refund the
-- money from the order page as usual.
--  status requested → approved | rejected → received → refunded
-- ============================================================

CREATE TABLE IF NOT EXISTS return_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    status ENUM('requested', 'approved', 'rejected', 'received', 'refunded') NOT NULL DEFAULT 'requested',
    reason VARCHAR(40) NOT NULL,
    details TEXT DEFAULT NULL,
    admin_response TEXT DEFAULT NULL COMMENT 'shown to the customer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_requests_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_return_requests_order (order_id),
    INDEX idx_return_requests_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS return_request_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id)
        REFERENCES return_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_return_items_item FOREIGN KEY (order_item_id)
        REFERENCES order_items(id) ON DELETE CASCADE,
    INDEX idx_return_items_return (return_id)
) ENGINE=InnoDB;
