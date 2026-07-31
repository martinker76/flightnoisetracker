<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Config\Database;

/**
 * Health check endpoint - reports service status, database connectivity,
 * and per-source poll health (dual-poller aware).
 */
class HealthController
{
    /**
     * A poll is considered stale if it hasn't succeeded in this many
     * seconds. We allow 2.5× the 30s cron cadence to cover jitter + a
     * missed cycle.
     */
    private const POLL_STALE_AFTER_SECONDS = 75;

    public function __construct(private array $config)
    {
    }

    /**
     * GET /api/health - Service health check.
     *
     * Reports per-source poll health so a stuck/failing source doesn't hide
     * behind a healthy one. After the dual-poller rollout (PR #1), each
     * source has its own row in `poll_state`; the endpoint iterates all rows
     * and returns 'degraded' if any active source is stale or in error.
     */
    public function index(array $params): void
    {
        $health = [
            'status' => 'ok',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'database' => 'unknown',
            'polls' => [],
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

        // Per-source poll health
        try {
            $db = Database::getConnection();
            $stmt = $db->query(
                'SELECT source, last_poll_at, last_success_at, last_error '
                . 'FROM poll_state ORDER BY source'
            );

            $now = time();
            $anyStale = false;
            $anyError = false;

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $lastSuccessTs = $row['last_success_at']
                    ? strtotime($row['last_success_at'] . ' UTC')
                    : false;
                $ageSeconds = $lastSuccessTs ? ($now - $lastSuccessTs) : null;
                $isStale = $ageSeconds === null || $ageSeconds > self::POLL_STALE_AFTER_SECONDS;
                $hasError = !empty($row['last_error']);

                if ($isStale) $anyStale = true;
                if ($hasError) $anyError = true;

                $health['polls'][$row['source']] = [
                    'last_poll_at' => $row['last_poll_at']
                        ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row['last_poll_at'] . ' UTC'))
                        : null,
                    'last_success_at' => $row['last_success_at']
                        ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row['last_success_at'] . ' UTC'))
                        : null,
                    'last_error' => $row['last_error'],
                    'age_seconds' => $ageSeconds,
                    'stale' => $isStale,
                ];
            }

            // Down-grade status if any active source is stale or in error.
            // DB connectivity already down-graded earlier.
            if ($anyStale || $anyError) {
                $health['status'] = 'degraded';
            }
        } catch (\PDOException) {
            // poll_state may not exist yet — leave polls empty
        }

        $statusCode = $health['status'] === 'ok' ? 200 : 503;

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
