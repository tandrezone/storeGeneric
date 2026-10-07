-- ============================================================
-- Admin activity log: logins, logouts, failed logins and every
-- state-changing admin action (Admin → Activity log). username is a
-- snapshot, so entries stay readable after a user is renamed or removed.
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_audit_log (
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
