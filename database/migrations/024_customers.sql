-- ============================================================
-- Customer accounts (storefront sign-in) and their saved addresses.
-- Guest checkout keeps working; accounts are optional.
--  - email is stored lower-case and is unique; a deleted account is
--    anonymised (email deleted-<id>@invalid, no password, inactive) so
--    its orders keep their customer_id.
--  - customer_addresses mirror the checkout form fields; one per customer
--    can be the default (prefilled at checkout).
-- ============================================================

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(40) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customers_email (email),
    INDEX idx_customers_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    label VARCHAR(60) DEFAULT NULL COMMENT 'e.g. Home, Work',
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    address1 VARCHAR(190) NOT NULL,
    address2 VARCHAR(190) DEFAULT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) DEFAULT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_addresses_customer (customer_id, is_default)
) ENGINE=InnoDB;
