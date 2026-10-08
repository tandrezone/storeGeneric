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
    description TEXT DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL COMMENT 'relative to /public',
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
-- Product text per language (name, short and long description).
-- products.* is the store's own language; a NULL / empty field here
-- falls back to it.
-- ----------------------------------------------------------
CREATE TABLE product_translations (
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
-- Customer accounts (optional; guest checkout works without one).
-- email is stored lower-case; deleting an account anonymises the row
-- (email deleted-<id>@invalid, no password, inactive) and keeps orders.
-- ----------------------------------------------------------
CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(40) DEFAULT NULL,
    locale VARCHAR(10) DEFAULT NULL COMMENT 'preferred language (translations/<locale>.php)',
    active TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customers_email (email),
    INDEX idx_customers_created (created_at)
) ENGINE=InnoDB;

-- Saved addresses (same fields as the checkout form); one can be the default.
CREATE TABLE customer_addresses (
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

-- Password-reset (1 hour) and email-verification links: only the SHA-256
-- of the token is stored, used_at makes each single-use.
CREATE TABLE customer_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    purpose ENUM('reset', 'verify') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    email VARCHAR(190) NOT NULL COMMENT 'address the link was sent to',
    expires_at DATETIME NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_tokens_hash (token_hash),
    CONSTRAINT fk_customer_tokens_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer_tokens_customer (customer_id, purpose)
) ENGINE=InnoDB;

-- Customer sign-ins, reset requests and registrations per IP / email (rate limiting).
CREATE TABLE customer_auth_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('login', 'reset', 'register') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_attempts_ip (kind, ip_address, created_at),
    INDEX idx_customer_attempts_email (kind, email, created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Orders (customer_id NULL = guest checkout)
-- ----------------------------------------------------------
CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    customer_id INT UNSIGNED DEFAULT NULL,
    locale VARCHAR(10) DEFAULT NULL COMMENT 'language the order was placed in (its emails use it)',
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
    status ENUM('pending','paid','processing','shipped','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','paying','paid','failed','expired','refunded') NOT NULL DEFAULT 'unpaid',
    subtotal DECIMAL(10,2) NOT NULL,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    coupon_code VARCHAR(40) DEFAULT NULL COMMENT 'discount code used (copy)',
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'taken off the subtotal (free-shipping codes: shipping saved)',
    prices_include_tax TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = VAT included in prices, 0 = VAT added on top',
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT % applied (by shipping country)',
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    stock_reserved TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 while the order holds stock for its items (taken at checkout, given back once)',
    coupon_counted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 while the order counts towards coupons.used_count',
    tracking_number VARCHAR(100) DEFAULT NULL,
    carrier VARCHAR(100) DEFAULT NULL,
    shipped_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_email (email),
    INDEX idx_orders_status (status),
    INDEX idx_orders_created (created_at),
    INDEX idx_orders_payment_created (payment_status, created_at),
    INDEX idx_orders_coupon (coupon_code),
    INDEX idx_orders_customer (customer_id, created_at),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL
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
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT part of the line (after discount)',
    CONSTRAINT fk_orderitems_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_orderitems_variant FOREIGN KEY (variant_id)
        REFERENCES product_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Discount codes (Admin → Discounts). Codes are stored upper-case and
-- matched case-insensitively; used_count is raised at checkout and
-- lowered if the order is cancelled before payment.
-- ----------------------------------------------------------
CREATE TABLE coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    type ENUM('percent', 'fixed', 'free_shipping') NOT NULL DEFAULT 'percent',
    value DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'percent (0-100) or amount off; unused for free_shipping',
    min_subtotal DECIMAL(10,2) DEFAULT NULL COMMENT 'items subtotal needed to use the code; NULL = any',
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    usage_limit INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    per_email_limit INT UNSIGNED DEFAULT NULL COMMENT 'uses per customer email; NULL = unlimited',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_coupons_code (code)
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
    INDEX idx_payments_track (track_id),
    INDEX idx_payments_order_event (order_id, provider, status)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Login attempts (brute-force throttling for /admin/login.php)
-- ----------------------------------------------------------
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(190) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, created_at),
    INDEX idx_login_attempts_user_time (username, created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Admin users (Admin → Users). Roles: owner (everything), manager
-- (everything but users/settings/themes), staff (products + orders).
-- While empty, the .env ADMIN_USERNAME / ADMIN_PASSWORD_HASH account can
-- log in and is saved here as the first owner.
-- ----------------------------------------------------------
CREATE TABLE admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'manager', 'staff') NOT NULL DEFAULT 'staff',
    locale VARCHAR(10) NOT NULL DEFAULT 'en' COMMENT 'admin panel language',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_admin_users_username (username)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Admin activity log (Admin → Activity log). username is a snapshot;
-- details is JSON and never contains passwords or form tokens.
-- ----------------------------------------------------------
CREATE TABLE admin_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    username VARCHAR(190) DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(40) DEFAULT NULL,
    entity_id VARCHAR(64) DEFAULT NULL,
    summary VARCHAR(255) NOT NULL DEFAULT '',
    details TEXT DEFAULT NULL COMMENT 'JSON; never contains passwords or form tokens',
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_user_created (user_id, created_at),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Failed storefront order lookups (/order/track), for rate limiting
-- ----------------------------------------------------------
CREATE TABLE order_lookup_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_lookup_ip_time (ip_address, created_at)
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

