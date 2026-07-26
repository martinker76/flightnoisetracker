<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Flight;
use App\Models\FlightPosition;

/**
 * Handles flight listing, detail, and track endpoints.
 */
class FlightController
{
    private Flight $flightModel;
    private FlightPosition $positionModel;

    public function __construct(private array $config)
    {
        $this->flightModel = new Flight();
        $this->positionModel = new FlightPosition();
    }

    /**
     * GET /api/flights - List flights with pagination and filtering.
     */
    public function index(array $params): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 50)));
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $vieOnly = isset($_GET['vie_only']) && $_GET['vie_only'] === 'true';
        $runway = $_GET['runway'] ?? null;
        $sort = $_GET['sort'] ?? 'first_seen';
        $order = $_GET['order'] ?? 'desc';

        // Validate date formats
        if ($dateFrom !== null && !$this->isValidDate($dateFrom)) {
            $this->sendError('Invalid date_from format. Use YYYY-MM-DD.', 'INVALID_PARAMETER', 400);
            return;
        }
        if ($dateTo !== null && !$this->isValidDate($dateTo)) {
            $this->sendError('Invalid date_to format. Use YYYY-MM-DD.', 'INVALID_PARAMETER', 400);
            return;
        }

        $result = $this->flightModel->list(
            $page,
            $perPage,
            $dateFrom,
            $dateTo,
            $vieOnly,
            $runway,
            $sort,
            $order
        );

        $this->sendPaginated($result['data'], $result['total'], $page, $perPage);
    }

    /**
     * GET /api/flights/{id} - Single flight with position data.
     */
    public function show(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            $this->sendError('Invalid flight ID.', 'INVALID_PARAMETER', 400);
            return;
        }

        $flight = $this->flightModel->findById($id);
        if ($flight === null) {
            $this->sendError('Flight not found.', 'NOT_FOUND', 404);
            return;
        }

        $positions = $this->positionModel->findByFlightId($id);

        $this->sendJson([
            'data' => array_merge($flight, [
                'positions' => $positions,
                'position_count' => count($positions),
            ]),
        ]);
    }

    /**
     * GET /api/flights/{id}/track - GeoJSON flight track.
     */
    public function track(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            $this->sendError('Invalid flight ID.', 'INVALID_PARAMETER', 400);
            return;
        }

        $flight = $this->flightModel->findById($id);
        if ($flight === null) {
            $this->sendError('Flight not found.', 'NOT_FOUND', 404);
            return;
        }

        $geoJson = $this->positionModel->toGeoJson($id, $flight);

        $this->sendJson($geoJson);
    }

    /**
     * GET /api/flights/vie/daily - Daily VIE-related flight list.
     */
    public function vieDaily(array $params): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 50)));
        $date = $_GET['date'] ?? null;

        if ($date !== null && !$this->isValidDate($date)) {
            $this->sendError('Invalid date format. Use YYYY-MM-DD.', 'INVALID_PARAMETER', 400);
            return;
        }

        $result = $this->flightModel->listVieDaily($page, $perPage, $date);

        $this->sendPaginated($result['data'], $result['total'], $page, $perPage);
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
     * Send a paginated JSON response.
     */
    private function sendPaginated(array $data, int $total, int $page, int $perPage): void
    {
        $this->sendJson([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int)ceil($total / $perPage),
            ],
        ]);
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
