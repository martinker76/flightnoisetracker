<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Config\Database;

/**
 * Flight model - represents a single flight passing through the bounding box.
 */
class Flight
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Find a flight by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM flights WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * List flights with pagination and filtering.
     *
     * @return array{data: array, total: int}
     */
    public function list(
        int $page = 1,
        int $perPage = 50,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $vieOnly = false,
        ?string $runway = null,
        string $sort = 'first_seen',
        string $order = 'desc'
    ): array {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'first_seen >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'first_seen <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        if ($vieOnly) {
            $where[] = 'is_vie_related = TRUE';
        }

        if ($runway !== null && in_array($runway, ['11/29', '16/34', 'UNKNOWN'], true)) {
            $where[] = 'runway_used = :runway';
            $params['runway'] = $runway;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Sanitize sort column
        $allowedSorts = ['first_seen', 'last_seen', 'icao24', 'callsign', 'max_altitude_m'];
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'first_seen';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        // Count total
        $countSql = "SELECT COUNT(*) FROM flights {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch page
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM flights {$whereClause} ORDER BY {$sort} {$order} LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
        ];
    }

    /**
     * Find or create a flight by icao24 and first_seen.
     */
    public function findOrCreate(string $icao24, string $firstSeen, array $data): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flights WHERE icao24 = :icao24 AND first_seen = :first_seen'
        );
        $stmt->execute(['icao24' => $icao24, 'first_seen' => $firstSeen]);
        $existing = $stmt->fetch();

        if ($existing) {
            return $existing;
        }

        $insertStmt = $this->db->prepare(
            'INSERT INTO flights (icao24, callsign, origin_country, first_seen, last_seen, '
            . 'max_altitude_m, min_altitude_m, is_vie_related, runway_used, runway_confidence, '
            . 'operator, aircraft_type, registration) '
            . 'VALUES (:icao24, :callsign, :origin_country, :first_seen, :last_seen, '
            . ':max_altitude_m, :min_altitude_m, :is_vie_related, :runway_used, :runway_confidence, '
            . ':operator, :aircraft_type, :registration)'
        );

        $insertStmt->execute([
            'icao24' => $icao24,
            'callsign' => $data['callsign'] ?? null,
            'origin_country' => $data['origin_country'] ?? null,
            'first_seen' => $firstSeen,
            'last_seen' => $data['last_seen'] ?? $firstSeen,
            'max_altitude_m' => $data['altitude_m'] ?? null,
            'min_altitude_m' => $data['altitude_m'] ?? null,
            'is_vie_related' => ($data['is_vie_related'] ?? false) ? 1 : 0,
            'runway_used' => $data['runway_used'] ?? 'UNKNOWN',
            'runway_confidence' => $data['runway_confidence'] ?? null,
            'operator' => $data['operator'] ?? null,
            'aircraft_type' => $data['aircraft_type'] ?? null,
            'registration' => $data['registration'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->findById($id);
    }

    /**
     * Update last_seen and altitude range for an existing flight.
     */
    public function updateTracking(int $id, string $lastSeen, ?int $altitudeM): void
    {
        $sql = 'UPDATE flights SET last_seen = :last_seen';
        $params = ['id' => $id, 'last_seen' => $lastSeen];

        if ($altitudeM !== null) {
            $sql .= ', max_altitude_m = GREATEST(COALESCE(max_altitude_m, 0), :alt_max), '
                  . 'min_altitude_m = LEAST(COALESCE(min_altitude_m, 99999), :alt_min)';
            $params['alt_max'] = $altitudeM;
            $params['alt_min'] = $altitudeM;
        }

        $sql .= ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Update VIE classification and runway info.
     */
    public function updateClassification(int $id, bool $isVieRelated, string $runway, float $confidence): void
    {
        $stmt = $this->db->prepare(
            'UPDATE flights SET is_vie_related = :is_vie, runway_used = :runway, '
            . 'runway_confidence = :confidence WHERE id = :id'
        );
        $stmt->execute([
            'is_vie' => $isVieRelated ? 1 : 0,
            'runway' => $runway,
            'confidence' => $confidence,
            'id' => $id,
        ]);
    }

    /**
     * List VIE-related flights for a given date.
     *
     * @return array{data: array, total: int}
     */
    public function listVieDaily(int $page = 1, int $perPage = 50, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $params = [
            'date_start' => $date . ' 00:00:00',
            'date_end' => $date . ' 23:59:59',
        ];

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM flights WHERE is_vie_related = TRUE '
            . 'AND first_seen >= :date_start AND first_seen <= :date_end'
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            'SELECT * FROM flights WHERE is_vie_related = TRUE '
            . 'AND first_seen >= :date_start AND first_seen <= :date_end '
            . 'ORDER BY first_seen ASC LIMIT :limit OFFSET :offset'
        );

        $stmt->bindValue(':date_start', $params['date_start']);
        $stmt->bindValue(':date_end', $params['date_end']);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(),
            'total' => $total,
        ];
    }
}
