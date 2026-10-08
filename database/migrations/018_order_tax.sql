-- ============================================================
-- VAT on orders (Admin → Settings → Tax). Each order keeps the rate
-- and amount it was placed with:
--  - prices_include_tax: 1 = VAT is part of the item prices (EU style,
--    total unchanged, VAT extracted); 0 = VAT was added on top.
--  - tax_rate: percentage applied (by shipping country), tax_amount: VAT
--    in the order (items + shipping when shipping is taxed).
--  - order_items.tax_amount: the VAT part of each line (after discount).
-- Older orders read as "no VAT".
-- ============================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS prices_include_tax TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = VAT included in prices, 0 = VAT added on top' AFTER shipping_cost,
    ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT % applied (by shipping country)' AFTER prices_include_tax,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT part of the line (after discount)' AFTER line_total;
