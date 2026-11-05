-- ============================================================
-- Sale pricing. product_variants.price stays the regular price. While
-- sale_price is set and lower, and now is inside the optional
-- sale_starts_at / sale_ends_at window, customers pay sale_price
-- (the storefront shows the regular price struck through). Orders
-- snapshot the price actually charged in order_items.unit_price.
-- ============================================================

ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS sale_price DECIMAL(10,2) DEFAULT NULL COMMENT 'discounted price; NULL = no sale' AFTER price,
    ADD COLUMN IF NOT EXISTS sale_starts_at DATETIME DEFAULT NULL COMMENT 'NULL = already started' AFTER sale_price,
    ADD COLUMN IF NOT EXISTS sale_ends_at DATETIME DEFAULT NULL COMMENT 'NULL = no end' AFTER sale_starts_at;
