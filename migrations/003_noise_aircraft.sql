-- Migration 003: Add estimated noise level column
-- aircraft_type already exists in 001_schema.sql as VARCHAR(32)

ALTER TABLE flights ADD COLUMN IF NOT EXISTS estimated_db DECIMAL(5,1) DEFAULT NULL COMMENT 'Estimated peak noise (dBA) at Mannersdorf center';
