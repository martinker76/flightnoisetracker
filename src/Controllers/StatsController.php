<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Config\Database;

/**
 * Handles statistics endpoints: summary, runway breakdown, hourly, and trend.
 */
class StatsController
{
    private PDO $db;

    public function __construct(private array $config)
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/stats/summary - Aggregated flight counts (today, week, month, all time).
     */
    public function summary(array $params): void
    {
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $monthAgo = date('Y-m-d', strtotime('-30 days'));

        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total_flights,
                SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END) AS vie_related,
                SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END) AS runway_11_29,
                SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END) AS runway_16_34,
                SUM(CASE WHEN runway_used = \'UNKNOWN\' AND is_vie_related = TRUE THEN 1 ELSE 0 END) AS runway_unknown,
                SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END) AS overflights,
                AVG(max_altitude_m) AS avg_altitude_m
            FROM flights
            WHERE first_seen >= :start'
        );

        // Today
        $stmt->execute(['start' => $today . ' 00:00:00']);
        $todayStats = $stmt->fetch();

        // Week
        $stmt->execute(['start' => $weekAgo . ' 00:00:00']);
        $weekStats = $stmt->fetch();

        // Month
        $stmt->execute(['start' => $monthAgo . ' 00:00:00']);
        $monthStats = $stmt->fetch();

        // All time
        $allStmt = $this->db->query(
            'SELECT
                COUNT(*) AS total_flights,
                SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END) AS vie_related,
                SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END) AS runway_11_29,
                SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END) AS runway_16_34,
                SUM(CASE WHEN runway_used = \'UNKNOWN\' AND is_vie_related = TRUE THEN 1 ELSE 0 END) AS runway_unknown,
                SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END) AS overflights,
                AVG(max_altitude_m) AS avg_altitude_m
            FROM flights'
        );
        $allStats = $allStmt->fetch();

        $this->sendJson([
            'data' => [
                'today' => $this->formatStats($todayStats),
                'week' => $this->formatStats($weekStats),
                'month' => $this->formatStats($monthStats),
                'all_time' => $this->formatStats($allStats),
            ],
        ]);
    }

    /**
     * GET /api/stats/runways - Per-runway breakdown over a date range.
     */
    public function runways(array $params): void
    {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');

        if (!$this->isValidDate($dateFrom) || !$this->isValidDate($dateTo)) {
            $this->sendError('Invalid date format. Use YYYY-MM-DD.', 'INVALID_PARAMETER', 400);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT
                DATE(first_seen) AS stat_date,
                COUNT(*) AS total_flights,
                SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END) AS vie_related,
                SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END) AS runway_11_29,
                SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END) AS runway_16_34,
                SUM(CASE WHEN runway_used = \'UNKNOWN\' THEN 1 ELSE 0 END) AS runway_unknown,
                SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END) AS overflights
            FROM flights
            WHERE first_seen >= :date_from AND first_seen <= :date_to
            GROUP BY DATE(first_seen)
            ORDER BY stat_date ASC'
        );

        $stmt->execute([
            'date_from' => $dateFrom . ' 00:00:00',
            'date_to' => $dateTo . ' 23:59:59',
        ]);

        $this->sendJson([
            'data' => $stmt->fetchAll(),
            'meta' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * GET /api/stats/hourly?date=YYYY-MM-DD - Hourly breakdown for a given day.
     */
    public function hourly(array $params): void
    {
        $date = $_GET['date'] ?? date('Y-m-d');

        if (!$this->isValidDate($date)) {
            $this->sendError('Invalid date format. Use YYYY-MM-DD.', 'INVALID_PARAMETER', 400);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT
                HOUR(first_seen) AS hour,
                COUNT(*) AS total_flights,
                SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END) AS vie_related,
                SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END) AS runway_11_29,
                SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END) AS runway_16_34,
                SUM(CASE WHEN runway_used = \'UNKNOWN\' THEN 1 ELSE 0 END) AS runway_unknown,
                SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END) AS overflights
            FROM flights
            WHERE first_seen >= :date_start AND first_seen <= :date_end
            GROUP BY HOUR(first_seen)
            ORDER BY hour ASC'
        );

        $stmt->execute([
            'date_start' => $date . ' 00:00:00',
            'date_end' => $date . ' 23:59:59',
        ]);

        $rows = $stmt->fetchAll();

        // Build full 24-hour breakdown
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = [
                'hour' => $h,
                'total_flights' => 0,
                'vie_related' => 0,
                'runway_11_29' => 0,
                'runway_16_34' => 0,
                'runway_unknown' => 0,
                'overflights' => 0,
            ];
        }

        foreach ($rows as $row) {
            $h = (int)$row['hour'];
            $hourly[$h] = $row;
        }

        $this->sendJson([
            'data' => $hourly,
            'meta' => [
                'date' => $date,
            ],
        ]);
    }

    /**
     * GET /api/stats/trend?days=30 - Trend data (flights/day over N days).
     */
    public function trend(array $params): void
    {
        $days = min(365, max(1, (int)($_GET['days'] ?? 30)));
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare(
            'SELECT
                DATE(first_seen) AS stat_date,
                COUNT(*) AS total_flights,
                SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END) AS vie_related,
                SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END) AS runway_11_29,
                SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END) AS runway_16_34,
                SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END) AS overflights
            FROM flights
            WHERE first_seen >= :start
            GROUP BY DATE(first_seen)
            ORDER BY stat_date ASC'
        );

        $stmt->execute(['start' => $startDate . ' 00:00:00']);
        $rows = $stmt->fetchAll();

        // Index by date for gap filling
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['stat_date']] = $row;
        }

        // Fill all days
        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = $indexed[$date] ?? [
                'stat_date' => $date,
                'total_flights' => 0,
                'vie_related' => 0,
                'runway_11_29' => 0,
                'runway_16_34' => 0,
                'overflights' => 0,
            ];
        }

        // Reverse to chronological order
        $trend = array_reverse($trend);

        $this->sendJson([
            'data' => $trend,
            'meta' => [
                'days' => $days,
                'date_from' => $startDate,
                'date_to' => date('Y-m-d'),
            ],
        ]);
    }

    /**
     * Format stats row with proper types.
     */
    private function formatStats(array|false $row): array
    {
        if (!$row) {
            return [
                'total_flights' => 0,
                'vie_related' => 0,
                'runway_11_29' => 0,
                'runway_16_34' => 0,
                'runway_unknown' => 0,
                'overflights' => 0,
                'avg_altitude_m' => null,
            ];
        }

        return [
            'total_flights' => (int)$row['total_flights'],
            'vie_related' => (int)$row['vie_related'],
            'runway_11_29' => (int)$row['runway_11_29'],
            'runway_16_34' => (int)$row['runway_16_34'],
            'runway_unknown' => (int)$row['runway_unknown'],
            'overflights' => (int)$row['overflights'],
            'avg_altitude_m' => $row['avg_altitude_m'] !== null ? round((float)$row['avg_altitude_m'], 1) : null,
        ];
    }

    /**
     * Validate a YYYY-MM-DD date string.
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /**
     * Send a JSON response.
     */
    private function sendJson(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a JSON error response.
     */
    private function sendError(string $message, string $code, int $statusCode): void
    {
        $this->sendJson(['error' => $message, 'code' => $code], $statusCode);
    }
}
