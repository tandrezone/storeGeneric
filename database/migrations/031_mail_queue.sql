-- ============================================================
-- Outgoing email queue. Mail is delivered right away; when that fails the
-- message is kept here and retried with a growing delay by
-- `bin/console mail:process` (run it every few minutes from cron).
-- Bulk mail (newsletters, reminders) is only queued and sent by the same command.
--  sent_at   set once delivered (kept for a while, then pruned)
--  failed_at set when max_attempts is used up (a person has to look at it)
-- ============================================================

CREATE TABLE IF NOT EXISTS mail_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    reply_to VARCHAR(190) DEFAULT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    next_attempt_at DATETIME NOT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    failed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mail_queue_due (sent_at, failed_at, next_attempt_at)
) ENGINE=InnoDB;
