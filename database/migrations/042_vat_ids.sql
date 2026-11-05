-- ============================================================
-- EU VAT numbers for business customers (checked against VIES at checkout).
--  vat_checks              cache of VIES answers (valid 24 h, invalid 1 h) so the same number
--                          isn't looked up on every page view
--  orders.vat_id           the customer's VAT number as checked
--  orders.vat_reverse_charge  1 = no VAT charged: intra-community B2B supply, VAT is
--                          accounted for by the buyer (printed on the invoice)
-- ============================================================

CREATE TABLE IF NOT EXISTS vat_checks (
    vat_id VARCHAR(20) NOT NULL PRIMARY KEY,
    status ENUM('valid', 'invalid') NOT NULL,
    name VARCHAR(255) DEFAULT NULL,
    address VARCHAR(500) DEFAULT NULL,
    checked_at DATETIME NOT NULL
) ENGINE=InnoDB;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS vat_id VARCHAR(20) DEFAULT NULL COMMENT 'customer VAT number (EU business)' AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS vat_reverse_charge TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = no VAT charged, reverse charge' AFTER vat_id;
