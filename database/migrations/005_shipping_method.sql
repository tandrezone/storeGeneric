-- ============================================================
-- Adds shipping method selection to checkout: the chosen method
-- and its price at time of order (snapshot, like other order fields).
-- ============================================================

ALTER TABLE orders
    ADD COLUMN shipping_method VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER ship_country,
    ADD COLUMN shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal;
