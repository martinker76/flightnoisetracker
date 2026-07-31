-- Migration 008: per-IP rate limit for POST /api/noise.
--
-- Why: the noise endpoint was previously unauthenticated and unrate-limited,
-- allowing anyone to bulk-insert arbitrary `noise_readings` rows (which
-- drive the public noise statistics on the dashboard). Same defensive
-- pattern as `contact_rate_limit` for the contact form, keyed per-IP
-- with a single row per IP storing the window start time and message count.
--
-- Schema mirrors contact_rate_limit intentionally — the application code
-- uses the same upsert/check pattern, just with a different table name.

CREATE TABLE IF NOT EXISTS noise_rate_limit (
    ip_address     VARCHAR(45)  NOT NULL,        -- IPv4 / IPv6 max length
    message_count  INT UNSIGNED NOT NULL DEFAULT 0,
    window_start   DATETIME     NOT NULL,
    PRIMARY KEY (ip_address),
    KEY idx_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
