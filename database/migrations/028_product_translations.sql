-- ============================================================
-- Product translations: name, short description and description per
-- language (translations/<locale>.php codes, e.g. 'pt').
--  - The text in `products` is the store's own language; a row here
--    replaces it for visitors browsing in that language.
--  - A NULL / empty field falls back to the original text, so a product
--    can be translated partly.
-- ============================================================

CREATE TABLE IF NOT EXISTS product_translations (
    product_id INT UNSIGNED NOT NULL,
    locale VARCHAR(10) NOT NULL,
    name VARCHAR(180) DEFAULT NULL,
    short_description VARCHAR(280) DEFAULT NULL,
    long_description TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, locale),
    CONSTRAINT fk_product_translations_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;
