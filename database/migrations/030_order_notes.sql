-- ============================================================
-- Internal order notes (Admin → Orders → an order). Staff-only; never shown
-- to the customer. author is a snapshot of the admin's username.
-- ============================================================

CREATE TABLE IF NOT EXISTS order_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    author VARCHAR(190) DEFAULT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_notes_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_order_notes_order (order_id, created_at)
) ENGINE=InnoDB;
