-- ============================================================
-- Admin dashboard: revenue and order counts per day are read by
-- payment status and date.
-- ============================================================

CREATE INDEX IF NOT EXISTS idx_orders_payment_created ON orders (payment_status, created_at);
