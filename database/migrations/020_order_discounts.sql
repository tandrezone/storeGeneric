-- ============================================================
-- Discount code used on an order.
--  - coupon_code / discount_amount: copy of the code and the amount taken
--    off the items subtotal (free-shipping codes: the shipping saved).
--  - coupon_counted: 1 while the order counts towards the code's
--    used_count (set at checkout, cleared once if the order is cancelled
--    before payment).
-- ============================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(40) DEFAULT NULL AFTER shipping_cost,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER coupon_code,
    ADD COLUMN IF NOT EXISTS coupon_counted TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_reserved,
    ADD INDEX IF NOT EXISTS idx_orders_coupon (coupon_code);
