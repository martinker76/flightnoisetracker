<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\NoiseReading;

/**
 * Handles noise reading endpoints: list and create.
 */
class NoiseController
{
    private NoiseReading $noiseModel;

    public function __construct(private array $config)
    {
        $this->noiseModel = new NoiseReading();
    }

    /**
     * GET /api/noise - List noise readings (paginated).
     */
    public function index(array $params): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 50)));

        $result = $this->noiseModel->list($page, $perPage);

        $this->sendJson([
            'data' => $result['data'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'pages' => (int)ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/noise - Submit a new noise reading.
     */
    public function store(array $params): void
    {
        $input = $this->getJsonInput();

        if ($input === null) {
            $this->sendError('Invalid JSON body.', 'INVALID_BODY', 400);
            return;
        }

        // Validate required fields
        if (!isset($input['measured_at']) || !isset($input['db_level'])) {
            $this->sendError(
                'Missing required fields: measured_at, db_level.',
                'MISSING_FIELDS',
                422
            );
            return;
        }

        // Validate measured_at format
        $measuredAt = $input['measured_at'];
        if (!$this->isValidDatetime($measuredAt)) {
            $this->sendError(
                'Invalid measured_at format. Use ISO 8601 (YYYY-MM-DD HH:MM:SS or YYYY-MM-DDTHH:MM:SS).',
                'INVALID_PARAMETER',
                422
            );
            return;
        }

        // Normalize datetime format
        $measuredAt = str_replace('T', ' ', $measuredAt);
        if (strlen($measuredAt) === 10) {
            $measuredAt .= ' 00:00:00';
        }

        // Validate dB level
        $dbLevel = (float)$input['db_level'];
        if ($dbLevel < 0 || $dbLevel > 200) {
            $this->sendError('db_level must be between 0 and 200.', 'INVALID_PARAMETER', 422);
            return;
        }

        // Validate optional lat/lon — accept both lat/lon and latitude/longitude
        $lat = isset($input['lat']) ? (float)$input['lat'] : (isset($input['latitude']) ? (float)$input['latitude'] : null);
        $lon = isset($input['lon']) ? (float)$input['lon'] : (isset($input['longitude']) ? (float)$input['longitude'] : null);

        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $this->sendError('lat must be between -90 and 90.', 'INVALID_PARAMETER', 422);
            return;
        }
        if ($lon !== null && ($lon < -180 || $lon > 180)) {
            $this->sendError('lon must be between -180 and 180.', 'INVALID_PARAMETER', 422);
            return;
        }

        $id = $this->noiseModel->create([
            'measured_at' => $measuredAt,
            'db_level' => $dbLevel,
            'lat' => $lat,
            'lon' => $lon,
            'notes' => $input['notes'] ?? null,
            'correlated_flight_id' => isset($input['correlated_flight_id']) ? (int)$input['correlated_flight_id'] : null,
        ]);

        $reading = $this->noiseModel->findById($id);

        $this->sendJson(['data' => $reading], 201);
    }

    /**
     * Parse JSON request body.
     */
    private function getJsonInput(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Validate a datetime string (YYYY-MM-DD HH:MM:SS or YYYY-MM-DDTHH:MM:SS).
     */
    private function isValidDatetime(string $dt): bool
    {
        $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d'];
        foreach ($formats as $format) {
            $d = \DateTime::createFromFormat($format, $dt);
            if ($d !== false) {
                return true;
            }
        }
        return false;
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
