-- ============================================================
-- Weight-based shipping.
--  product_variants.weight_grams   weight of one unit (0 = unknown / weightless)
--  shipping_methods.cost_per_kg    added to the flat cost, per kg of the order
--  shipping_methods.min/max_weight_grams  the method is only offered for orders
--                                  within this weight range (NULL = no limit)
--  orders.weight_grams             total weight at the time of the order
-- ============================================================

ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS weight_grams INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'weight of one unit, grams' AFTER stock;

ALTER TABLE shipping_methods
    ADD COLUMN IF NOT EXISTS cost_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'added to cost, per kg of the order' AFTER cost,
    ADD COLUMN IF NOT EXISTS min_weight_grams INT UNSIGNED DEFAULT NULL COMMENT 'offered from this order weight; NULL = no minimum' AFTER countries,
    ADD COLUMN IF NOT EXISTS max_weight_grams INT UNSIGNED DEFAULT NULL COMMENT 'offered up to this order weight; NULL = no maximum' AFTER min_weight_grams;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS weight_grams INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'total order weight, grams' AFTER shipping_cost;
