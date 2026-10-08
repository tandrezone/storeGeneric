-- ============================================================
-- Category description and image, edited in Admin → Categories and
-- shown on the storefront. image_path is relative to /public.
-- ============================================================

ALTER TABLE categories
    ADD COLUMN IF NOT EXISTS description TEXT DEFAULT NULL AFTER slug,
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) DEFAULT NULL COMMENT 'relative to /public' AFTER description;
