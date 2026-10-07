-- ============================================================
-- Online Store - MariaDB Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS online_store CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE online_store;

-- ----------------------------------------------------------
-- Categories
-- ----------------------------------------------------------
CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Products
-- ----------------------------------------------------------
CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    short_description VARCHAR(280) NOT NULL,
    long_description TEXT NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    images JSON DEFAULT NULL COMMENT 'array of image paths (relative to /public) for the product detail slideshow; first entry is the main image',
    import_status ENUM('created', 'invalid', 'update', 'approved') NOT NULL DEFAULT 'created'
        COMMENT 'created = default; invalid/update = admin review flags; approved = visible on the storefront',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_products_category (category_id),
    INDEX idx_products_active (is_active),
    INDEX idx_products_import_status (import_status)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Settings edited in /admin/settings.php (override .env values)
-- ----------------------------------------------------------
CREATE TABLE settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Shipping methods (managed in /admin/shipping.php)
-- ----------------------------------------------------------
CREATE TABLE shipping_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'stored on orders.shipping_method',
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) DEFAULT NULL COMMENT 'e.g. delivery estimate, shown at checkout',
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    free_over DECIMAL(10,2) DEFAULT NULL COMMENT 'subtotal at or above which shipping is free; NULL = never',
    countries VARCHAR(500) DEFAULT NULL COMMENT 'comma-separated country names/codes; NULL = all countries',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shipping_active_sort (is_active, sort_order)
) ENGINE=InnoDB;

INSERT INTO shipping_methods (code, name, description, cost, sort_order) VALUES
    ('standard', 'Standard Shipping', '5-7 business days', 0.00, 1),
    ('express', 'Express Shipping', '1-2 business days', 9.99, 2);

-- ----------------------------------------------------------
-- Product variants (pack size / qty + price)
-- ----------------------------------------------------------
CREATE TABLE product_variants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    sku VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(64),
    unit VARCHAR(64),
    price DECIMAL(10,2) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_variants_product (product_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Carts (one active cart per session, optionally per user later)
-- ----------------------------------------------------------
CREATE TABLE carts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cart_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id INT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cartitems_cart FOREIGN KEY (cart_id)
        REFERENCES carts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cartitems_variant FOREIGN KEY (variant_id)
        REFERENCES product_variants(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_cart_variant (cart_id, variant_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Orders
-- ----------------------------------------------------------
CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    ship_name VARCHAR(150) NOT NULL,
    ship_address1 VARCHAR(190) NOT NULL,
    ship_address2 VARCHAR(190) DEFAULT NULL,
    ship_city VARCHAR(100) NOT NULL,
    ship_state VARCHAR(100) DEFAULT NULL,
    ship_postal_code VARCHAR(20) NOT NULL,
    ship_country VARCHAR(100) NOT NULL,
    shipping_method VARCHAR(20) NOT NULL DEFAULT 'standard',
    shipping_label VARCHAR(150) DEFAULT NULL COMMENT 'shipping method name at time of order',
    payment_method VARCHAR(30) NOT NULL DEFAULT 'oxapay',
    status ENUM('pending','paid','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','paying','paid','failed','expired') NOT NULL DEFAULT 'unpaid',
    subtotal DECIMAL(10,2) NOT NULL,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_email (email),
    INDEX idx_orders_status (status)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(180) NOT NULL COMMENT 'snapshot at time of order',
    label VARCHAR(64) DEFAULT NULL COMMENT 'snapshot at time of order',
    unit VARCHAR(64) DEFAULT NULL COMMENT 'snapshot at time of order',
    unit_price DECIMAL(10,2) NOT NULL COMMENT 'snapshot at time of order',
    quantity INT UNSIGNED NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_orderitems_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_orderitems_variant FOREIGN KEY (variant_id)
        REFERENCES product_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Payments (OxaPay invoices/callbacks)
-- ----------------------------------------------------------
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'oxapay',
    track_id VARCHAR(100) DEFAULT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(20) DEFAULT NULL,
    raw_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_payments_track (track_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Login attempts (brute-force throttling for /admin/login.php)
-- ----------------------------------------------------------
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Page views (site analytics: traffic, product views, and the
-- checkout funnel — shipping step reached, payment redirect reached)
-- ----------------------------------------------------------
CREATE TABLE page_views (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL,
    event_type ENUM('visit', 'product_view', 'checkout_shipping', 'checkout_payment') NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pageviews_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_pageviews_event_type (event_type),
    INDEX idx_pageviews_product_id (product_id),
    INDEX idx_pageviews_created_at (created_at)
) ENGINE=InnoDB;

