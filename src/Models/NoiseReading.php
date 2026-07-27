<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Config\Database;

/**
 * NoiseReading model - represents a manual noise measurement entry.
 */
class NoiseReading
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * List noise readings with pagination.
     *
     * @return array{data: array, total: int}
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $countStmt = $this->db->query('SELECT COUNT(*) FROM noise_readings');
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            'SELECT * FROM noise_readings ORDER BY measured_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $data = array_map([$this, 'formatNoise'], $rows);

        return [
            'data' => $data,
            'total' => $total,
        ];
    }

    /**
     * Create a new noise reading.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO noise_readings (measured_at, db_level, lat, lon, notes, correlated_flight_id) '
            . 'VALUES (:measured_at, :db_level, :lat, :lon, :notes, :correlated_flight_id)'
        );

        $stmt->execute([
            'measured_at' => $data['measured_at'],
            'db_level' => $data['db_level'],
            'lat' => $data['lat'] ?? null,
            'lon' => $data['lon'] ?? null,
            'notes' => $data['notes'] ?? null,
            'correlated_flight_id' => $data['correlated_flight_id'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find a noise reading by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM noise_readings WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ? $this->formatNoise($result) : null;
    }

    /**
     * Format noise reading row: map lat/lon to latitude/longitude for frontend,
     * and emit measured_at as ISO 8601 UTC so the frontend's date-fns format()
     * correctly converts to the user's local timezone.
     */
    private function formatNoise(array $row): array
    {
        $row['latitude'] = $row['lat'];
        $row['longitude'] = $row['lon'];
        unset($row['lat'], $row['lon']);
        if (!empty($row['measured_at'])) {
            $ts = strtotime($row['measured_at'] . ' UTC');
            $row['measured_at'] = $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
        return $row;
    }
}
