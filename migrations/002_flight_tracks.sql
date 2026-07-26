-- Cache for flight track data fetched from OpenSky Network
CREATE TABLE IF NOT EXISTS flight_tracks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT UNSIGNED NOT NULL,
    icao24 CHAR(6) NOT NULL,
    track_data JSON NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(32) NOT NULL DEFAULT 'opensky',
    FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

ALTER TABLE flight_tracks ADD INDEX idx_flight_id (flight_id);
ALTER TABLE flight_tracks ADD INDEX idx_icao24 (icao24);
