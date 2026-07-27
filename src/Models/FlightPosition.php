<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Config\Database;

/**
 * FlightPosition model - represents a single position sample for a flight.
 */
class FlightPosition
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Insert a new position sample.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO flight_positions (flight_id, captured_at, lat, lon, altitude_m, '
            . 'speed_mps, heading_deg, vertical_rate_mps, on_ground, distance_km, source) '
            . 'VALUES (:flight_id, :captured_at, :lat, :lon, :altitude_m, '
            . ':speed_mps, :heading_deg, :vertical_rate_mps, :on_ground, :distance_km, :source)'
        );

        $stmt->execute([
            'flight_id' => $data['flight_id'],
            'captured_at' => $data['captured_at'],
            'lat' => $data['lat'],
            'lon' => $data['lon'],
            'altitude_m' => $data['altitude_m'] ?? null,
            'speed_mps' => $data['speed_mps'] ?? null,
            'heading_deg' => $data['heading_deg'] ?? null,
            'vertical_rate_mps' => $data['vertical_rate_mps'] ?? null,
            'on_ground' => ($data['on_ground'] ?? false) ? 1 : 0,
            'distance_km' => $data['distance_km'] ?? null,
            'source' => $data['source'] ?? 'opensky',
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get all positions for a flight, ordered by time.
     */
    public function findByFlightId(int $flightId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flight_positions WHERE flight_id = :flight_id ORDER BY captured_at ASC'
        );
        $stmt->execute(['flight_id' => $flightId]);
        $rows = $stmt->fetchAll();
        return array_map(static function (array $row): array {
            if (isset($row['captured_at'])) {
                $ts = strtotime($row['captured_at'] . ' UTC');
                $row['captured_at'] = $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
            }
            return $row;
        }, $rows);
    }

    /**
     * Get positions as GeoJSON FeatureCollection.
     */
    public function toGeoJson(int $flightId, array $flight): array
    {
        $feature = $this->buildSingleFeature($flightId, $flight);
        return [
            'type' => 'FeatureCollection',
            'features' => [$feature],
        ];
    }

    /**
     * Build a single GeoJSON Feature with LineString geometry.
     */
    private function buildSingleFeature(int $flightId, array $flight): array
    {
        $positions = $this->findByFlightId($flightId);

        $coordinates = [];
        $altitudes = [];
        $timestamps = [];

        foreach ($positions as $pos) {
            // GeoJSON uses [longitude, latitude, altitude]
            $coordinates[] = [
                (float)$pos['lon'],
                (float)$pos['lat'],
                $pos['altitude_m'] !== null ? (int)$pos['altitude_m'] : null,
            ];
            $altitudes[] = $pos['altitude_m'];
            $timestamps[] = $pos['captured_at'];
        }

        return [
            'type' => 'Feature',
            'properties' => [
                'flight_id' => $flightId,
                'icao24' => $flight['icao24'],
                'callsign' => $flight['callsign'],
                'aircraft_type' => $flight['aircraft_type'] ?? null,
                'estimated_db' => $flight['estimated_db'] ?? null,
                'runway_used' => $flight['runway_used'],
                'is_vie_related' => (bool)$flight['is_vie_related'],
                'first_seen' => $flight['first_seen'],
                'last_seen' => $flight['last_seen'],
                'position_count' => count($positions),
            ],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coordinates,
            ],
            'timestamps' => $timestamps,
        ];
    }
}
