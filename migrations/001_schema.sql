-- FlightNoiseTracker Database Schema
-- Migration 001: Initial schema creation

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Core flight records (one per unique flight through the box)
CREATE TABLE IF NOT EXISTS flights (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    icao24 CHAR(6) NOT NULL,
    callsign VARCHAR(8),
    origin_country VARCHAR(64),
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    max_altitude_m SMALLINT UNSIGNED,
    min_altitude_m SMALLINT UNSIGNED,
    is_vie_related BOOLEAN DEFAULT FALSE,
    runway_used ENUM('11/29','16/34','UNKNOWN') DEFAULT 'UNKNOWN',
    runway_confidence DECIMAL(3,2),
    operator VARCHAR(128),
    aircraft_type VARCHAR(32),
    registration VARCHAR(16),
    INDEX idx_icao24 (icao24),
    INDEX idx_first_seen (first_seen),
    INDEX idx_vie_related (is_vie_related),
    INDEX idx_runway (runway_used),
    UNIQUE KEY uniq_icao_seen (icao24, first_seen)
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Flight position samples (track data, high volume)
CREATE TABLE IF NOT EXISTS flight_positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT UNSIGNED NOT NULL,
    captured_at DATETIME(3) NOT NULL,
    lat DECIMAL(9,6) NOT NULL,
    lon DECIMAL(9,6) NOT NULL,
    altitude_m SMALLINT UNSIGNED,
    speed_mps DECIMAL(5,1),
    heading_deg DECIMAL(5,1),
    vertical_rate_mps DECIMAL(4,1),
    on_ground BOOLEAN DEFAULT FALSE,
    distance_km DECIMAL(6,2),
    source ENUM('opensky','adsbexchange') NOT NULL DEFAULT 'opensky',
    INDEX idx_flight (flight_id),
    INDEX idx_captured (captured_at),
    FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Noise readings (manual entry)
CREATE TABLE IF NOT EXISTS noise_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    measured_at DATETIME NOT NULL,
    db_level DECIMAL(4,1) NOT NULL,
    lat DECIMAL(9,6),
    lon DECIMAL(9,6),
    notes TEXT,
    correlated_flight_id BIGINT UNSIGNED NULL,
    INDEX idx_measured (measured_at),
    FOREIGN KEY (correlated_flight_id) REFERENCES flights(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Aircraft metadata cache (from OpenFlights)
CREATE TABLE IF NOT EXISTS aircraft (
    icao24 CHAR(6) PRIMARY KEY,
    registration VARCHAR(16),
    aircraft_type VARCHAR(32),
    operator VARCHAR(128),
    model VARCHAR(64),
    last_updated DATETIME NOT NULL
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Poller state tracking
CREATE TABLE IF NOT EXISTS poll_state (
    source VARCHAR(32) PRIMARY KEY,
    last_poll_at DATETIME NOT NULL,
    last_success_at DATETIME,
    last_error TEXT,
    rows_inserted INT DEFAULT 0,
    rows_updated INT DEFAULT 0
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Materialized daily runway stats (refreshed by cron)
CREATE TABLE IF NOT EXISTS daily_stats (
    stat_date DATE PRIMARY KEY,
    total_flights INT DEFAULT 0,
    vie_related INT DEFAULT 0,
    runway_11_29 INT DEFAULT 0,
    runway_16_34 INT DEFAULT 0,
    runway_unknown INT DEFAULT 0,
    overflights INT DEFAULT 0,
    avg_altitude_m DECIMAL(7,1),
    hourly_breakdown JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
