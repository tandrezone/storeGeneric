-- ============================================================
-- Orders imported from marketplaces / delivery apps (POST /api/marketplace/orders).
--  source       short name of the marketplace ("glovo", "bolt-food", …); NULL = own storefront
--  external_id  the marketplace's own order id; (source, external_id) is unique, so a
--               connector that retries never creates an order twice
-- ============================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS source VARCHAR(30) DEFAULT NULL COMMENT 'marketplace the order came from; NULL = this store' AFTER order_number,
    ADD COLUMN IF NOT EXISTS external_id VARCHAR(80) DEFAULT NULL COMMENT 'order id at the marketplace' AFTER source;

ALTER TABLE orders
    ADD UNIQUE KEY IF NOT EXISTS uniq_orders_source_external (source, external_id);
