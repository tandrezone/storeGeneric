-- ============================================================
-- Remove the supplier import pipeline and the admin todo board.
-- For databases created before these features were dropped;
-- fresh installs from schema.sql already match this.
-- ============================================================

UPDATE products SET import_status = 'created' WHERE import_status = 'imported';

ALTER TABLE products
    DROP FOREIGN KEY fk_products_supplier,
    DROP INDEX uniq_products_supplier_external,
    DROP COLUMN supplier_external_id,
    DROP COLUMN supplier_id,
    MODIFY COLUMN import_status ENUM('created', 'invalid', 'update', 'approved') NOT NULL DEFAULT 'created'
        COMMENT 'created = default; invalid/update = admin review flags; approved = visible on the storefront';

DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS todos;
