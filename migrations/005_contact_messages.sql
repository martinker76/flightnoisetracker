-- Migration 005: contact_messages table for the About-page contact form.
-- Stores every submitted message as an audit trail. The application then
-- sends email via PHP mail(); if exim/blackhole configuration fails on the
-- shared host, the message is still in the DB and we can recover it.
CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    mail_sent TINYINT(1) DEFAULT 0 COMMENT '1 = mail() reported success, 0 = failed',
    mail_error TEXT DEFAULT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_submitted_at (submitted_at)
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Simple per-IP rate-limiting table (sliding window).
-- We limit to 5 messages per hour per IP to deter spam without bothering
-- legitimate users.
CREATE TABLE IF NOT EXISTS contact_rate_limit (
    ip_address VARCHAR(64) NOT NULL,
    message_count INT UNSIGNED DEFAULT 1,
    window_start DATETIME NOT NULL,
    PRIMARY KEY (ip_address)
) ENGINE=InnoDB CHARSET=utf8mb4;
