-- ============================================================
-- Invoices and credit notes (PDF). One invoice per paid order and one credit
-- note per refunded order, numbered per kind and year without gaps
-- (INV-2026-00001, CN-2026-00001). Numbers are assigned the first time a
-- document is generated and never change; the PDF is rebuilt from the order,
-- whose prices and VAT are snapshots.
-- ============================================================

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    kind ENUM('invoice', 'credit_note') NOT NULL DEFAULT 'invoice',
    year SMALLINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    number VARCHAR(32) NOT NULL,
    issued_at DATETIME NOT NULL,
    CONSTRAINT fk_invoices_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_invoices_order_kind (order_id, kind),
    UNIQUE KEY uniq_invoices_sequence (kind, year, seq),
    UNIQUE KEY uniq_invoices_number (number)
) ENGINE=InnoDB;
