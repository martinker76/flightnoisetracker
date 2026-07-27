-- Migration 004: Add SEL column for v1.1 noise model
-- Mirrors the shape of estimated_db (migration 003).
ALTER TABLE flights ADD COLUMN IF NOT EXISTS sel_db DECIMAL(5,1) DEFAULT NULL COMMENT 'Sound Exposure Level (dB) at Mannersdorf center';
