-- ============================================================
-- Multiple payment methods: which method an order was placed with,
-- and which provider each payments row came from.
-- Existing rows were all OxaPay.
-- ============================================================

ALTER TABLE orders
    ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'oxapay' AFTER shipping_method;

ALTER TABLE payments
    ADD COLUMN provider VARCHAR(30) NOT NULL DEFAULT 'oxapay' AFTER order_id;
