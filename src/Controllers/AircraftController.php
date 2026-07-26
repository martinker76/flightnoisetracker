<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Aircraft;

/**
 * Handles aircraft metadata endpoints.
 */
class AircraftController
{
    private Aircraft $aircraftModel;

    public function __construct(private array $config)
    {
        $this->aircraftModel = new Aircraft();
    }

    /**
     * GET /api/aircraft/{icao24} - Aircraft metadata by ICAO24 address.
     */
    public function show(array $params): void
    {
        $icao24 = strtolower(trim($params['icao24'] ?? ''));

        // Validate ICAO24 format (6 hex characters)
        if (!preg_match('/^[0-9a-f]{6}$/', $icao24)) {
            $this->sendError(
                'Invalid ICAO24 address. Must be 6 hexadecimal characters.',
                'INVALID_PARAMETER',
                400
            );
            return;
        }

        $aircraft = $this->aircraftModel->findByIcao24($icao24);

        if ($aircraft === null) {
            $this->sendError('Aircraft not found.', 'NOT_FOUND', 404);
            return;
        }

        $this->sendJson(['data' => $aircraft]);
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
