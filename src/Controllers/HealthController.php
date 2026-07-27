<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Config\Database;

/**
 * Health check endpoint - reports service status, database connectivity, and last poll time.
 */
class HealthController
{
    public function __construct(private array $config)
    {
    }

    /**
     * GET /api/health - Service health check.
     */
    public function index(array $params): void
    {
        $health = [
            'status' => 'ok',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'database' => 'unknown',
            'last_poll' => null,
        ];

        // Check database connectivity
        try {
            $db = Database::getConnection();
            $db->query('SELECT 1');
            $health['database'] = 'connected';
        } catch (\PDOException $e) {
            $health['status'] = 'degraded';
            $health['database'] = 'error: connection failed';
            error_log('HealthController: DB connection failed: ' . $e->getMessage());
        }

        // Check last poll time
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                'SELECT last_poll_at, last_success_at, last_error FROM poll_state WHERE source = :source'
            );
            $stmt->execute(['source' => 'opensky']);
            $pollState = $stmt->fetch();

            if ($pollState) {
                $health['last_poll'] = [
                    'last_poll_at' => $pollState['last_poll_at']
                        ? gmdate('Y-m-d\TH:i:s\Z', strtotime($pollState['last_poll_at'] . ' UTC'))
                        : null,
                    'last_success_at' => $pollState['last_success_at']
                        ? gmdate('Y-m-d\TH:i:s\Z', strtotime($pollState['last_success_at'] . ' UTC'))
                        : null,
                    'last_error' => $pollState['last_error'],
                ];
            }
        } catch (\PDOException) {
            // Ignore - poll_state may not exist yet
        }

        $statusCode = $health['status'] === 'ok' ? 200 : 503;

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
