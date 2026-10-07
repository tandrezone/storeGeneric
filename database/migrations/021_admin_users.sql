-- ============================================================
-- Admin accounts with roles (owner / manager / staff), replacing the
-- single ADMIN_USERNAME / ADMIN_PASSWORD_HASH account from .env.
-- While this table is empty the .env account can still log in; its first
-- login saves it here as the first 'owner' (see README → Admin users).
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'manager', 'staff') NOT NULL DEFAULT 'staff',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_admin_users_username (username)
) ENGINE=InnoDB;
