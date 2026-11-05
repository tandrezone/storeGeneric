-- ============================================================
-- Editable content pages (Admin → Pages). A row for one of the built-in slugs
-- (about, info, support, terms, privacy) replaces that page's built-in text;
-- any other slug is a new page at /p/<slug>. locale '' applies to every
-- language; a row for a specific language wins over it. Bodies are sanitized
-- HTML (headings, lists, links). in_footer adds the page to the footer links.
-- ============================================================

CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL,
    locale VARCHAR(10) NOT NULL DEFAULT '',
    title VARCHAR(180) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    in_footer TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pages_slug_locale (slug, locale),
    INDEX idx_pages_footer (is_published, in_footer, sort_order)
) ENGINE=InnoDB;
