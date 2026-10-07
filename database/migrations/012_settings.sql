-- ============================================================
-- Admin → Settings: store name, logo, theme. Values here override
-- the matching .env keys (STORE_NAME, STORE_EMAIL, THEME).
-- ============================================================

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

