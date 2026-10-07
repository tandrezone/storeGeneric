-- ============================================================
-- Orders placed while signed in belong to that customer account
-- (orders.customer_id; NULL = guest checkout). Guest orders are linked
-- to an account only after its owner verifies the same email address.
-- ============================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED DEFAULT NULL AFTER order_number,
    ADD INDEX IF NOT EXISTS idx_orders_customer (customer_id, created_at);

ALTER TABLE orders
    ADD CONSTRAINT fk_orders_customer FOREIGN KEY IF NOT EXISTS (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL;
