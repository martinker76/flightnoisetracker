-- Migration 007: extend `flight_positions.source` ENUM with `opensky-historical`
-- for rows produced by `cron/backfill.php` (the historical OpenSky backfill
-- script that re-creates positions for past dates from `/flights/*` +
-- `/tracks/*` data).
--
-- Why: `cron/backfill.php:788` writes `source='opensky-historical'`. The
-- previous ENUM (`opensky`, `adsbexchange`, `home-adsb`) didn't include
-- this value, so under strict SQL mode (MySQL 8 / MariaDB >=10.2.4) every
-- backfilled position INSERT threw SQLSTATE 1265, was caught by the
-- per-flight try/catch, and left the just-inserted flight row orphaned
-- with zero positions — effectively making the entire backfill pipeline
-- a no-op while running cleanly without errors at the script level.
--
-- DDL safety:
--   - Adding a value at the END of an ENUM is metadata-only in MariaDB /
--     MySQL 8.0+; no table rewrite, no read lock beyond the brief instant
--     of ALTER.
--   - The runtime DB user `kersch_flightn_w` is DML-only; apply via the
--     admin user `kersch_flightn` (Plesk panel → phpMyAdmin, or
--     `mysql -u kersch_flightn -p`).
--
-- Rollback:
--   ALTER TABLE flight_positions
--     MODIFY source ENUM('opensky','adsbexchange','home-adsb') NOT NULL DEFAULT 'opensky';
--   (Will fail if any row currently has source='opensky-historical';
--    delete those rows first.)

ALTER TABLE flight_positions
    MODIFY source ENUM('opensky','adsbexchange','home-adsb','opensky-historical')
        NOT NULL DEFAULT 'opensky';
