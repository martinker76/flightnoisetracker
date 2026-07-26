<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Config\Database;

/**
 * Aircraft model - metadata cache for aircraft identified by ICAO24 address.
 */
class Aircraft
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Find aircraft metadata by ICAO24 address.
     */
    public function findByIcao24(string $icao24): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM aircraft WHERE icao24 = :icao24');
        $stmt->execute(['icao24' => strtolower($icao24)]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Insert or update aircraft metadata.
     */
    public function upsert(string $icao24, array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO aircraft (icao24, registration, aircraft_type, operator, model, last_updated) '
            . 'VALUES (:icao24, :registration, :aircraft_type, :operator, :model, :last_updated) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'registration = COALESCE(VALUES(registration), registration), '
            . 'aircraft_type = COALESCE(VALUES(aircraft_type), aircraft_type), '
            . 'operator = COALESCE(VALUES(operator), operator), '
            . 'model = COALESCE(VALUES(model), model), '
            . 'last_updated = VALUES(last_updated)'
        );

        $stmt->execute([
            'icao24' => strtolower($icao24),
            'registration' => $data['registration'] ?? null,
            'aircraft_type' => $data['aircraft_type'] ?? null,
            'operator' => $data['operator'] ?? null,
            'model' => $data['model'] ?? null,
            'last_updated' => $data['last_updated'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}
