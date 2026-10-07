-- ============================================================
-- Align order_items snapshot columns with product_variants,
-- which stores label/unit as free text (e.g. "6" / "pack",
-- "500" / "ml"), not a strict numeric pack count.
-- ============================================================

ALTER TABLE order_items
    MODIFY COLUMN label VARCHAR(64) NULL COMMENT 'snapshot at time of order',
    ADD COLUMN unit VARCHAR(64) NULL COMMENT 'snapshot at time of order' AFTER label;
