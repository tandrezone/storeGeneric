-- ============================================================
-- Orders: stock reservation flag, refunds, shipment tracking.
--  - stock_reserved: 1 while the order holds stock (taken at checkout,
--    given back once on cancel / failed / expired / refund with restock).
--  - 'refunded' order and payment status.
--  - tracking_number, carrier, shipped_at.
-- Before this, stock was only taken when an order became paid, so paid
-- orders are marked as holding stock and unpaid ones as not.
-- ============================================================

ALTER TABLE orders
    MODIFY status ENUM('pending','paid','processing','shipped','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    MODIFY payment_status ENUM('unpaid','paying','paid','failed','expired','refunded') NOT NULL DEFAULT 'unpaid',
    ADD COLUMN stock_reserved TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 while the order holds stock for its items (taken at checkout, given back once)' AFTER total,
    ADD COLUMN tracking_number VARCHAR(100) DEFAULT NULL AFTER stock_reserved,
    ADD COLUMN carrier VARCHAR(100) DEFAULT NULL AFTER tracking_number,
    ADD COLUMN shipped_at TIMESTAMP NULL DEFAULT NULL AFTER carrier,
    ADD INDEX idx_orders_created (created_at);

UPDATE orders SET stock_reserved = 1 WHERE payment_status = 'paid';

-- Payment events are looked up per order + provider + status to ignore webhook retries.
ALTER TABLE payments
    ADD INDEX idx_payments_order_event (order_id, provider, status);
