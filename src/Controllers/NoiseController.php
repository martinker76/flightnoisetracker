<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Models\NoiseReading;

/**
 * Handles noise reading endpoints: list and create.
 *
 * The POST /api/noise endpoint is a public write endpoint — anyone can
 * submit a reading. Since these readings drive the public noise
 * statistics on the dashboard, the endpoint needs both per-IP rate
 * limiting (spam defense) and input validation (no arbitrary long
 * `notes`, no fake `correlated_flight_id`).
 */
class NoiseController
{
    private NoiseReading $noiseModel;
    private PDO $db;

    /** Per-IP rate limit: max submissions per window. */
    private const RATE_LIMIT_MAX = 10;

    /** Rate-limit window length in seconds. */
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    /** Cap on the `notes` free-text field (DB column is TEXT, ~64KB; we cut tighter). */
    private const MAX_NOTES = 1000;

    public function __construct(private array $config)
    {
        $this->noiseModel = new NoiseReading();
        $this->db = new PDO(
            "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset=utf8mb4",
            $config['db']['user'],
            $config['db']['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
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
        // Per-IP rate limit (spam defense on a public write endpoint).
        $ip = $this->clientIp();
        if (!$this->checkRateLimit($ip)) {
            $this->sendError(
                'Too many submissions. Please try again later.',
                'RATE_LIMITED',
                429
            );
            return;
        }

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

        // Cap and validate the free-text `notes` field.
        $notes = null;
        if (isset($input['notes']) && $input['notes'] !== null) {
            $notesRaw = $input['notes'];
            if (!is_string($notesRaw)) {
                $this->sendError('notes must be a string.', 'INVALID_PARAMETER', 422);
                return;
            }
            if (mb_strlen($notesRaw) > self::MAX_NOTES) {
                $this->sendError(
                    'notes exceeds ' . self::MAX_NOTES . ' chars.',
                    'INVALID_PARAMETER',
                    422
                );
                return;
            }
            $notes = $notesRaw;
        }

        // Validate `correlated_flight_id` if provided — must reference an existing flight.
        $correlatedFlightId = null;
        if (isset($input['correlated_flight_id']) && $input['correlated_flight_id'] !== null) {
            $cfid = (int)$input['correlated_flight_id'];
            if ($cfid > 0) {
                $check = $this->db->prepare('SELECT id FROM flights WHERE id = :id');
                $check->execute([':id' => $cfid]);
                if ($check->fetch() === false) {
                    $this->sendError(
                        'correlated_flight_id does not reference an existing flight.',
                        'INVALID_PARAMETER',
                        422
                    );
                    return;
                }
                $correlatedFlightId = $cfid;
            }
        }

        $id = $this->noiseModel->create([
            'measured_at' => $measuredAt,
            'db_level' => $dbLevel,
            'lat' => $lat,
            'lon' => $lon,
            'notes' => $notes,
            'correlated_flight_id' => $correlatedFlightId,
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

    /**
     * Best-effort client IP. We trust REMOTE_ADDR (the only reliable source
     * at this layer; X-Forwarded-For can be spoofed unless terminated by a
     * proxy we control).
     */
    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Sliding-window per-IP rate limit. One row per IP in `noise_rate_limit`,
     * atomic via SELECT ... FOR UPDATE so concurrent requests from the same
     * IP cannot both pass the check.
     */
    private function checkRateLimit(string $ip): bool
    {
        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare(
                'SELECT message_count, UNIX_TIMESTAMP(window_start) AS ws_ts '
                . 'FROM noise_rate_limit WHERE ip_address = :ip FOR UPDATE'
            );
            $sel->execute([':ip' => $ip]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            $now = time();

            if ($row === false) {
                // First request from this IP
                $ins = $this->db->prepare(
                    'INSERT INTO noise_rate_limit (ip_address, message_count, window_start) '
                    . "VALUES (:ip, 1, UTC_TIMESTAMP())"
                );
                $ins->execute([':ip' => $ip]);
                $this->db->commit();
                return true;
            }

            $count = (int)$row['message_count'];
            $windowStart = (int)$row['ws_ts'];
            $windowAge = $now - $windowStart;

            if ($windowAge >= self::RATE_LIMIT_WINDOW_SECONDS) {
                // Window expired — reset
                $upd = $this->db->prepare(
                    'UPDATE noise_rate_limit '
                    . 'SET message_count = 1, window_start = UTC_TIMESTAMP() '
                    . 'WHERE ip_address = :ip'
                );
                $upd->execute([':ip' => $ip]);
                $this->db->commit();
                return true;
            }

            if ($count >= self::RATE_LIMIT_MAX) {
                // At cap — deny without incrementing
                $this->db->commit();
                return false;
            }

            // Within window, under cap — increment
            $upd = $this->db->prepare(
                'UPDATE noise_rate_limit SET message_count = message_count + 1 '
                . 'WHERE ip_address = :ip'
            );
            $upd->execute([':ip' => $ip]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
