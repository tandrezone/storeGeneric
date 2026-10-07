-- ============================================================
-- Adds a gallery of images per product, used by the product detail
-- slideshow. Stores an array of paths (relative to /public); the
-- first entry is the main image (mirrors image_path).
-- ============================================================

ALTER TABLE products
    ADD COLUMN images JSON DEFAULT NULL AFTER image_path;
