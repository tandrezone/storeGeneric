-- ============================================================
-- Languages (translations/<locale>.php, e.g. 'en', 'pt').
--  - orders.locale: language the order was placed in; its emails use it.
--  - customers.locale: the account's preferred language (set when
--    registering and when the visitor switches language while signed in).
--  - admin_users.locale: language of the admin panel for that user
--    (Admin → My account), English by default.
-- NULL / unknown codes fall back to the store default (store_language).
-- ============================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS locale VARCHAR(10) DEFAULT NULL AFTER customer_id;

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS locale VARCHAR(10) DEFAULT NULL AFTER phone;

ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS locale VARCHAR(10) NOT NULL DEFAULT 'en' AFTER role;
