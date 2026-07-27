<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Config\Database;
use App\Models\Flight;
use App\Models\FlightPosition;
use App\Models\Aircraft;

/**
 * OpenSky Network poller service.
 *
 * Fetches live aircraft states from OpenSky Network API,
 * filters by the Mannersdorf bounding box, deduplicates by icao24+first_seen,
 * classifies VIE-related flights, and persists data.
 */
class OpenSkyPoller
{
    private PDO $db;
    private Flight $flightModel;
    private FlightPosition $positionModel;
    private Aircraft $aircraftModel;
    private RunwayClassifier $classifier;
    private OpenSkyAuth $auth;
    private NoiseCalculator $noiseCalc;

    private float $minLat;
    private float $maxLat;
    private float $minLon;
    private float $maxLon;

    /** @var array{inserted: int, updated: int, positions: int, errors: int} */
    private array $counters = ['inserted' => 0, 'updated' => 0, 'positions' => 0, 'errors' => 0];

    /** @var array<string, string|null> Cache for resolved aircraft types within a poll cycle */
    private array $aircraftTypeCache = [];

    private ?bool $selDbAvailable = null;

    public function __construct(private array $config)
    {
        $this->db = Database::getConnection();
        $this->flightModel = new Flight();
        $this->positionModel = new FlightPosition();
        $this->aircraftModel = new Aircraft();
        $this->auth = new OpenSkyAuth($config['opensky']);

        $this->classifier = new RunwayClassifier($config['airport']);
        $this->noiseCalc = new NoiseCalculator($config);

        $box = $config['bounding_box'];
        $this->minLat = (float)$box['min_lat'];
        $this->maxLat = (float)$box['max_lat'];
        $this->minLon = (float)$box['min_lon'];
        $this->maxLon = (float)$box['max_lon'];
    }

    /**
     * Execute a single polling cycle.
     *
     * @return array{inserted: int, updated: int, positions: int, errors: int, states_found: int}
     */
    public function poll(): array
    {
        $this->counters = ['inserted' => 0, 'updated' => 0, 'positions' => 0, 'errors' => 0];

        // Update poll timestamp
        $this->updatePollState('polling');

        try {
            $states = $this->fetchStates();
        } catch (\RuntimeException $e) {
            $this->updatePollState('error', $e->getMessage());
            throw $e;
        }

        if (empty($states)) {
            $this->updatePollState('success');
            return array_merge($this->counters, ['states_found' => 0]);
        }

        // Process each state
        foreach ($states as $state) {
            $this->processState($state);
        }

        $this->updatePollState('success');

        return array_merge($this->counters, ['states_found' => count($states)]);
    }

    /**
     * Fetch aircraft states from OpenSky Network API.
     *
     * @return array Array of state vectors
     * @throws \RuntimeException on API failure
     */
    private function fetchStates(): array
    {
        // Use bounding box filter in API call for efficiency
        $url = sprintf(
            'https://opensky-network.org/api/states/all?lamin=%.4f&lomin=%.4f&lamax=%.4f&lomax=%.4f',
            $this->minLat,
            $this->minLon,
            $this->maxLat,
            $this->maxLon
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: FlightNoiseTracker/1.0',
            ],
        ]);

        // Add OAuth2 Bearer token if configured
        $authHeaders = $this->auth->headers();
        if (!empty($authHeaders)) {
            $existingHeaders = [
                'Accept: application/json',
                'User-Agent: FlightNoiseTracker/1.0',
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($existingHeaders, $authHeaders));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \RuntimeException(
                "OpenSky API request failed: HTTP {$httpCode} - {$error}"
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !array_key_exists('states', $data)) {
            throw new \RuntimeException('Invalid OpenSky API response format');
        }

        // `null` states means no aircraft in the bounding box
        if ($data['states'] === null) {
            return [];
        }

        return $this->parseStates($data['states']);
    }

    /**
     * Parse raw OpenSky state vectors into structured arrays.
     *
     * OpenSky state vector indices:
     *  0: icao24, 1: callsign, 2: origin_country, 3: time_position,
     *  4: last_contact, 5: longitude, 6: latitude, 7: baro_altitude,
     *  8: on_ground, 9: velocity, 10: true_track, 11: vertical_rate,
     *  12: sensors, 13: geo_altitude, 14: squawk, 15: spi, 16: position_source
     *
     * @return array Array of parsed state arrays
     */
    private function parseStates(array $rawStates): array
    {
        $states = [];

        foreach ($rawStates as $raw) {
            if (!is_array($raw) || count($raw) < 12) {
                continue;
            }

            $icao24 = $raw[0] ?? null;
            $lat = $raw[6] ?? null;
            $lon = $raw[5] ?? null;

            // Skip if no valid position
            if ($icao24 === null || $lat === null || $lon === null) {
                continue;
            }

            $lat = (float)$lat;
            $lon = (float)$lon;

            // Double-check bounding box (API filter may be approximate)
            if ($lat < $this->minLat || $lat > $this->maxLat
                || $lon < $this->minLon || $lon > $this->maxLon) {
                continue;
            }

            $altitude = $raw[7] !== null ? (int)round((float)$raw[7]) : null; // baro_altitude in meters
            if ($altitude !== null && $altitude < 0) {
                $altitude = 0;
            }

            $states[] = [
                'icao24' => strtolower(trim($icao24)),
                'callsign' => isset($raw[1]) ? trim($raw[1]) : null,
                'origin_country' => $raw[2] ?? null,
                'time_position' => $raw[3] ?? null,
                'last_contact' => $raw[4] ?? null,
                'lat' => $lat,
                'lon' => $lon,
                'altitude_m' => $altitude,
                'on_ground' => (bool)($raw[8] ?? false),
                'velocity' => isset($raw[9]) ? (float)$raw[9] : null, // m/s
                'heading' => isset($raw[10]) ? (float)$raw[10] : null, // degrees
                'heading_deg' => isset($raw[10]) ? (float)$raw[10] : null, // degrees (alias for classifier)
                'vertical_rate' => isset($raw[11]) ? (float)$raw[11] : null, // m/s
                'vertical_rate_mps' => isset($raw[11]) ? (float)$raw[11] : null, // m/s (alias for classifier)
                'geo_altitude' => isset($raw[13]) ? (int)round((float)$raw[13]) : null,
            ];
        }

        return $states;
    }

    /**
     * Process a single aircraft state: deduplicate, insert/update flight, insert position.
     */
    private function processState(array $state): void
    {
        try {
            $icao24 = $state['icao24'];
            $now = gmdate('Y-m-d H:i:s');
            $capturedAt = gmdate('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);

            // Check if this aircraft already has an active flight (seen within last 60 min)
            $existingFlight = $this->findActiveFlight($icao24);

            if ($existingFlight === null) {
                // New flight: classify and insert
                $classification = $this->classifier->classify([$state]);
                $distance = $this->classifier->distanceFromMannersdorf($state['lat'], $state['lon']);

                $flight = $this->flightModel->findOrCreate($icao24, $now, [
                    'callsign' => $state['callsign'] ?: null,
                    'origin_country' => $state['origin_country'],
                    'last_seen' => $now,
                    'altitude_m' => $state['altitude_m'],
                    'is_vie_related' => $classification['is_vie_related'],
                    'runway_used' => $classification['runway'],
                    'runway_confidence' => $classification['confidence'],
                ]);

                $this->counters['inserted']++;

                // Resolve aircraft type from ADSB.lol
                $aircraftType = $this->resolveAircraftType($icao24);
                if ($aircraftType) {
                    $stmt = $this->db->prepare('UPDATE flights SET aircraft_type = ? WHERE id = ?');
                    $stmt->execute([$aircraftType, $flight['id']]);
                }

                // Insert position
                $this->positionModel->create([
                    'flight_id' => $flight['id'],
                    'captured_at' => $capturedAt,
                    'lat' => $state['lat'],
                    'lon' => $state['lon'],
                    'altitude_m' => $state['altitude_m'],
                    'speed_mps' => $state['velocity'],
                    'heading_deg' => $state['heading'],
                    'vertical_rate_mps' => $state['vertical_rate'],
                    'on_ground' => $state['on_ground'],
                    'distance_km' => round($distance, 2),
                    'source' => 'opensky',
                ]);

                $this->counters['positions']++;

                // Update estimated_db from closest position
                $this->updateEstimatedDb((int)$flight['id']);
            } else {
                // Existing flight: update last_seen, altitude, add position
                $this->flightModel->updateTracking(
                    (int)$existingFlight['id'],
                    $now,
                    $state['altitude_m']
                );

                $this->counters['updated']++;

                $distance = $this->classifier->distanceFromMannersdorf($state['lat'], $state['lon']);

                $this->positionModel->create([
                    'flight_id' => (int)$existingFlight['id'],
                    'captured_at' => $capturedAt,
                    'lat' => $state['lat'],
                    'lon' => $state['lon'],
                    'altitude_m' => $state['altitude_m'],
                    'speed_mps' => $state['velocity'],
                    'heading_deg' => $state['heading'],
                    'vertical_rate_mps' => $state['vertical_rate'],
                    'on_ground' => $state['on_ground'],
                    'distance_km' => round($distance, 2),
                    'source' => 'opensky',
                ]);

                $this->counters['positions']++;

                // Re-classify if not yet classified (UNKNOWN and enough positions now)
                if ($existingFlight['runway_used'] === 'UNKNOWN' && $existingFlight['is_vie_related']) {
                    $this->reclassifyFlight((int)$existingFlight['id']);
                }

                // Retry aircraft type resolution if null
                if ($existingFlight['aircraft_type'] === null) {
                    $aircraftType = $this->resolveAircraftType($icao24);
                    if ($aircraftType) {
                        $stmt = $this->db->prepare('UPDATE flights SET aircraft_type = ? WHERE id = ?');
                        $stmt->execute([$aircraftType, (int)$existingFlight['id']]);
                    }
                }

                // Update estimated_db from closest position
                $this->updateEstimatedDb((int)$existingFlight['id']);
            }
        } catch (\Throwable $e) {
            $this->counters['errors']++;
            fwrite(STDERR, "Error processing state {$state['icao24']}: {$e->getMessage()}\n");
        }
    }

    /**
     * Find an active flight for the given ICAO24 (seen within last 60 minutes).
     *
     * Tightened from 24h to 60min to avoid merging distinct overflights
     * when the same aircraft circles back. Poll interval is 60s, so a
     * single overpass should be visible for ~5-15 minutes.
     */
    private function findActiveFlight(string $icao24): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM flights WHERE icao24 = :icao24 '
            . 'AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 MINUTE) '
            . 'ORDER BY last_seen DESC LIMIT 1'
        );
        $stmt->execute(['icao24' => $icao24]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Re-classify a flight when more position data becomes available.
     */
    private function reclassifyFlight(int $flightId): void
    {
        $positions = $this->positionModel->findByFlightId($flightId);
        if (count($positions) < 3) {
            return; // Need at least 3 points for meaningful classification
        }

        $classification = $this->classifier->classify($positions);

        if ($classification['runway'] !== 'UNKNOWN') {
            $this->flightModel->updateClassification(
                $flightId,
                $classification['is_vie_related'],
                $classification['runway'],
                $classification['confidence']
            );
        }
    }

    /**
     * Resolve aircraft type from ADSB.lol public API.
     */
    private function resolveAircraftType(string $icao24): ?string
    {
        if (array_key_exists($icao24, $this->aircraftTypeCache)) {
            return $this->aircraftTypeCache[$icao24];
        }

        $url = 'https://api.adsb.lol/v2/icao/' . strtolower($icao24);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'FlightNoiseTracker/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->aircraftTypeCache[$icao24] = null;
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['ac'][0]['t'])) {
            $this->aircraftTypeCache[$icao24] = null;
            return null;
        }

        $type = strtoupper(trim($data['ac'][0]['t']));
        $this->aircraftTypeCache[$icao24] = $type;
        return $type;
    }

    /**
     * Update estimated_db on a flight based on its closest position.
     *
     * Uses the v1.1 noise model. Falls back to simple geometric model
     * if the new model returns null (e.g. OVERFLIGHT).
     */
    private function updateEstimatedDb(int $flightId): void
    {
        // Fetch closest position with all fields needed by the noise model
        $stmt = $this->db->prepare(
            'SELECT fp.distance_km, fp.altitude_m, fp.speed_mps,
                    fp.vertical_rate_mps, fp.lat, fp.lon
             FROM flight_positions fp
             WHERE fp.flight_id = ? AND fp.distance_km IS NOT NULL AND fp.altitude_m IS NOT NULL
             ORDER BY fp.distance_km ASC LIMIT 1'
        );
        $stmt->execute([$flightId]);
        $pos = $stmt->fetch();

        if (!$pos) {
            return;
        }

        // Get flight metadata for type + VIE flag
        $fStmt = $this->db->prepare(
            'SELECT aircraft_type, is_vie_related FROM flights WHERE id = ?'
        );
        $fStmt->execute([$flightId]);
        $flight = $fStmt->fetch();

        $aircraftType = $flight['aircraft_type'] ?? null;
        $isVie = (bool)($flight['is_vie_related'] ?? false);

        // Check if any prior position had descent (for GO_AROUND heuristic)
        $hadAppr = false;
        if ($isVie) {
            $haStmt = $this->db->prepare(
                'SELECT 1 FROM flight_positions
                 WHERE flight_id = ? AND vertical_rate_mps < -2.0
                 LIMIT 1'
            );
            $haStmt->execute([$flightId]);
            $hadAppr = (bool)$haStmt->fetch();
        }

        $position = [
            'distance_km'        => (float)$pos['distance_km'],
            'altitude_m'         => (float)$pos['altitude_m'],
            'speed_mps'          => $pos['speed_mps'] !== null ? (float)$pos['speed_mps'] : null,
            'vertical_rate_mps'  => $pos['vertical_rate_mps'] !== null ? (float)$pos['vertical_rate_mps'] : null,
            'lat'                => $pos['lat'] !== null ? (float)$pos['lat'] : null,
            'lon'                => $pos['lon'] !== null ? (float)$pos['lon'] : null,
        ];

        $result = $this->noiseCalc->calculate($position, $aircraftType, $isVie, $hadAppr);

        if ($result['l_amax'] !== null) {
            $upd = $this->db->prepare('UPDATE flights SET estimated_db = ? WHERE id = ?');
            $upd->execute([$result['l_amax'], $flightId]);

            if ($result['sel'] !== null && $this->hasSelDbColumn()) {
                $selUpd = $this->db->prepare('UPDATE flights SET sel_db = ? WHERE id = ?');
                $selUpd->execute([$result['sel'], $flightId]);
            }
        }
    }

    private function hasSelDbColumn(): bool
    {
        if ($this->selDbAvailable !== null) {
            return $this->selDbAvailable;
        }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'flights'
               AND COLUMN_NAME = 'sel_db'"
        );
        $stmt->execute();
        $this->selDbAvailable = ((int)$stmt->fetchColumn()) > 0;
        if (!$this->selDbAvailable) {
            error_log('[FNT] sel_db column missing — run migration 004 to enable SEL persistence');
        }
        return $this->selDbAvailable;
    }

    /**
     * Update the poll_state table with current status.
     */
    private function updatePollState(string $status, ?string $error = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO poll_state (source, last_poll_at, last_success_at, last_error, rows_inserted, rows_updated) '
            . 'VALUES (:source, :last_poll, :last_success, :error, :inserted, :updated) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'last_poll_at = VALUES(last_poll_at), '
            . 'last_success_at = COALESCE(VALUES(last_success_at), last_success_at), '
            . 'last_error = VALUES(last_error), '
            . 'rows_inserted = VALUES(rows_inserted), '
            . 'rows_updated = VALUES(rows_updated)'
        );

        $stmt->execute([
            'source' => 'opensky',
            'last_poll' => $now,
            'last_success' => $status === 'success' ? $now : null,
            'error' => $error,
            'inserted' => $this->counters['inserted'],
            'updated' => $this->counters['updated'],
        ]);
    }

    /**
     * Refresh daily_stats materialized table for a given date.
     */
    public function refreshDailyStats(string $date): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO daily_stats (stat_date, total_flights, vie_related, runway_11_29, '
            . 'runway_16_34, runway_unknown, overflights, avg_altitude_m, hourly_breakdown) '
            . 'SELECT
                DATE(first_seen) AS stat_date,
                COUNT(*) AS total_flights,
                SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END),
                SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END),
                SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END),
                SUM(CASE WHEN runway_used = \'UNKNOWN\' THEN 1 ELSE 0 END),
                SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END),
                AVG(max_altitude_m),
                JSON_OBJECT('
            . '\'00\', SUM(CASE WHEN HOUR(first_seen) = 0 THEN 1 ELSE 0 END), '
            . '\'01\', SUM(CASE WHEN HOUR(first_seen) = 1 THEN 1 ELSE 0 END), '
            . '\'02\', SUM(CASE WHEN HOUR(first_seen) = 2 THEN 1 ELSE 0 END), '
            . '\'03\', SUM(CASE WHEN HOUR(first_seen) = 3 THEN 1 ELSE 0 END), '
            . '\'04\', SUM(CASE WHEN HOUR(first_seen) = 4 THEN 1 ELSE 0 END), '
            . '\'05\', SUM(CASE WHEN HOUR(first_seen) = 5 THEN 1 ELSE 0 END), '
            . '\'06\', SUM(CASE WHEN HOUR(first_seen) = 6 THEN 1 ELSE 0 END), '
            . '\'07\', SUM(CASE WHEN HOUR(first_seen) = 7 THEN 1 ELSE 0 END), '
            . '\'08\', SUM(CASE WHEN HOUR(first_seen) = 8 THEN 1 ELSE 0 END), '
            . '\'09\', SUM(CASE WHEN HOUR(first_seen) = 9 THEN 1 ELSE 0 END), '
            . '\'10\', SUM(CASE WHEN HOUR(first_seen) = 10 THEN 1 ELSE 0 END), '
            . '\'11\', SUM(CASE WHEN HOUR(first_seen) = 11 THEN 1 ELSE 0 END), '
            . '\'12\', SUM(CASE WHEN HOUR(first_seen) = 12 THEN 1 ELSE 0 END), '
            . '\'13\', SUM(CASE WHEN HOUR(first_seen) = 13 THEN 1 ELSE 0 END), '
            . '\'14\', SUM(CASE WHEN HOUR(first_seen) = 14 THEN 1 ELSE 0 END), '
            . '\'15\', SUM(CASE WHEN HOUR(first_seen) = 15 THEN 1 ELSE 0 END), '
            . '\'16\', SUM(CASE WHEN HOUR(first_seen) = 16 THEN 1 ELSE 0 END), '
            . '\'17\', SUM(CASE WHEN HOUR(first_seen) = 17 THEN 1 ELSE 0 END), '
            . '\'18\', SUM(CASE WHEN HOUR(first_seen) = 18 THEN 1 ELSE 0 END), '
            . '\'19\', SUM(CASE WHEN HOUR(first_seen) = 19 THEN 1 ELSE 0 END), '
            . '\'20\', SUM(CASE WHEN HOUR(first_seen) = 20 THEN 1 ELSE 0 END), '
            . '\'21\', SUM(CASE WHEN HOUR(first_seen) = 21 THEN 1 ELSE 0 END), '
            . '\'22\', SUM(CASE WHEN HOUR(first_seen) = 22 THEN 1 ELSE 0 END), '
            . '\'23\', SUM(CASE WHEN HOUR(first_seen) = 23 THEN 1 ELSE 0 END)'
            . ') '
            . 'FROM flights '
            . 'WHERE DATE(first_seen) = :date '
            . 'GROUP BY DATE(first_seen) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'total_flights = VALUES(total_flights), '
            . 'vie_related = VALUES(vie_related), '
            . 'runway_11_29 = VALUES(runway_11_29), '
            . 'runway_16_34 = VALUES(runway_16_34), '
            . 'runway_unknown = VALUES(runway_unknown), '
            . 'overflights = VALUES(overflights), '
            . 'avg_altitude_m = VALUES(avg_altitude_m), '
            . 'hourly_breakdown = VALUES(hourly_breakdown)'
        );

        $stmt->execute(['date' => $date]);
    }
}
