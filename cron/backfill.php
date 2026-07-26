<?php

declare(strict_types=1);

/**
 * FlightNoiseTracker — Historical Backfill Script (v2)
 *
 * Backfills flight data from OpenSky Network's historical API.
 *
 * Strategy:
 *   1. Fetch LOWW arrivals/departures for the date range
 *   2. For each VIE flight, fetch its track to see if it crossed our
 *      Mannersdorf bounding box (parallel curl_multi for speed)
 *   3. Insert matching flights + positions into the DB
 *
 * Usage:
 *   php cron/backfill.php
 *   php cron/backfill.php --days 30
 *   php cron/backfill.php --start 2026-07-01 --end 2026-07-14
 *   php cron/backfill.php --dry-run
 *
 * Options:
 *   --days N       Backfill the last N days (default: 7)
 *   --start YYYY-MM-DD  Start date (overrides --days)
 *   --end YYYY-MM-DD    End date (overrides --days)
 *   --dry-run      Query and report without inserting
 *   --concurrency N     Parallel track requests (default: 8)
 *   --refresh-stats     Rebuild daily_stats after backfill
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}
require_once $autoloader;

use App\Services\OpenSkyAuth;
use App\Services\RunwayClassifier;
use App\Config\Database;

// ─── Parse CLI options ────────────────────────────────────────────────
$opts = getopt('', ['days:', 'start:', 'end:', 'dry-run', 'concurrency:', 'refresh-stats']);
$dryRun = isset($opts['dry-run']);
$refreshStats = isset($opts['refresh-stats']);
$maxConcurrent = isset($opts['concurrency']) ? max(1, min(20, (int)$opts['concurrency'])) : 8;

$endTs = isset($opts['end']) ? strtotime($opts['end'] . ' 23:59:59') : time();
if (isset($opts['start'])) {
    $startTs = strtotime($opts['start'] . ' 00:00:00');
} else {
    $days = isset($opts['days']) ? max(1, (int)$opts['days']) : 7;
    $startTs = $endTs - ($days * 86400);
}

echo "FlightNoiseTracker — Historical Backfill v2\n";
echo "=============================================\n";
echo "Date range: " . gmdate('Y-m-d', $startTs) . " to " . gmdate('Y-m-d', $endTs) . "\n";
echo "Concurrency: {$maxConcurrent}\n";
if ($dryRun) echo "Mode: DRY RUN (no inserts)\n";
echo "\n";

// ─── Bootstrap ────────────────────────────────────────────────────────
$config = require __DIR__ . '/../config/app.php';
$db = Database::getConnection();
$auth = new OpenSkyAuth($config['opensky']);
$classifier = new RunwayClassifier($config['airport']);

$box = $config['bounding_box'];
$minLat = (float)$box['min_lat'];
$maxLat = (float)$box['max_lat'];
$minLon = (float)$box['min_lon'];
$maxLon = (float)$box['max_lon'];

if (!$auth->isConfigured()) {
    fwrite(STDERR, "ERROR: OpenSky OAuth2 credentials not configured.\n");
    exit(1);
}

function apiGetParallel(array $urls, OpenSkyAuth $auth, int $maxConcurrent): array {
    $headers = $auth->headers();
    if (empty($headers)) return array_fill(0, count($urls), null);

    $results = [];
    $chunks = array_chunk($urls, $maxConcurrent, true);

    foreach ($chunks as $chunk) {
        $mcurl = curl_multi_init();
        $handles = [];
        $curlHeaders = $headers;

        foreach ($chunk as $i => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_HEADER => true,
            ]);
            curl_multi_add_handle($mcurl, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mcurl, $running);
            curl_multi_select($mcurl, 0.5);
        } while ($running > 0);

        foreach ($handles as $i => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $error = curl_error($ch);

            if ($response === false || !empty($error)) {
                fwrite(STDERR, "  [parallel] curl error: {$error}\n");
                $results[$i] = null;
            } else {
                $body = substr($response, $headerSize);
                // Capture credits info
                $headersStr = substr($response, 0, $headerSize);
                $creditsRemaining = null;
                preg_match('/X-Rate-Limit-Remaining:\s*(\d+)/i', $headersStr, $m);
                if (!empty($m[1])) $creditsRemaining = (int)$m[1];

                $results[$i] = [
                    'code' => $httpCode,
                    'body' => $body,
                    'credits_remaining' => $creditsRemaining,
                ];
            }

            curl_multi_remove_handle($mcurl, $ch);
            curl_close($ch);
        }

        curl_multi_close($mcurl);
    }

    return $results;
}

function apiGet(string $url, OpenSkyAuth $auth): ?array {
    $headers = $auth->headers();
    if (empty($headers)) return null;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) { fwrite(STDERR, "  curl error: {$error}\n"); return null; }
    $body = substr($response, $headerSize);
    $headersStr = substr($response, 0, $headerSize);
    $creditsRemaining = null;
    preg_match('/X-Rate-Limit-Remaining:\s*(\d+)/i', $headersStr, $m);
    if (!empty($m[1])) $creditsRemaining = (int)$m[1];

    return ['code' => $httpCode, 'body' => $body, 'credits_remaining' => $creditsRemaining];
}

// ─── Counters ─────────────────────────────────────────────────────────
$stats = [
    'api_calls' => 0,
    'flights_found' => 0,
    'tracks_fetched' => 0,
    'flights_in_box' => 0,
    'flights_inserted' => 0,
    'positions_inserted' => 0,
    'credits_used' => 0,
    'track_errors' => 0,
    'skipped_dup' => 0,
    'skip_no_track' => 0,
    'errors' => 0,
];

// ─── 1. Fetch flights arriving at/departing from LOWW ────────────────
echo "Step 1: Fetching LOWW flights from OpenSky...\n";

$fetchedFlights = [];

for ($windowStart = max($startTs, $endTs - 86400 * 30); $windowStart < $endTs; $windowStart += 86400 * 2) {
    $windowEnd = min($windowStart + 86400 * 2 - 1, $endTs);
    if ($windowEnd <= $windowStart) break;

    foreach (['arrival', 'departure'] as $type) {
        if ($type === 'departure' && ($windowEnd - $windowStart) < 86400 * 2 + 1) {
            // Departure endpoint requires >2 days
            $depWindowEnd = min($windowStart + 86400 * 3 - 1, $endTs);
            if ($depWindowEnd - $windowStart < 86400 * 2 + 1) continue;
            $url = "https://opensky-network.org/api/flights/departure?airport=LOWW&begin={$windowStart}&end={$depWindowEnd}";
        } else {
            $url = "https://opensky-network.org/api/flights/{$type}?airport=LOWW&begin={$windowStart}&end={$windowEnd}";
        }

        $stats['api_calls']++;
        echo "  {$type}s " . gmdate('Y-m-d', $windowStart) . "→" . gmdate('Y-m-d', $windowEnd) . " ... ";
        $result = apiGet($url, $auth);

        if ($result === null || $result['code'] === 404) {
            echo "no data\n";
            continue;
        }
        if ($result['code'] !== 200) {
            echo "HTTP {$result['code']}: " . substr($result['body'], 0, 100) . "\n";
            continue;
        }

        $flights = json_decode($result['body'], true);
        if (!is_array($flights)) { echo "invalid\n"; continue; }

        $cr = $result['credits_remaining'] !== null ? " (rem: {$result['credits_remaining']})" : '';
        echo count($flights) . " flights{$cr}\n";
        $stats['credits_used'] += 30;

        foreach ($flights as $f) {
            $key = ($f['icao24'] ?? '?') . '_' . ($f['firstSeen'] ?? 0);
            if (isset($f['firstSeen']) && $f['firstSeen'] >= $startTs && $f['firstSeen'] <= $endTs) {
                // Avoid duplicates (same flight may be in both arrivals and departures)
                if (!isset($fetchedFlights[$key])) {
                    $fetchedFlights[$key] = $f;
                }
            }
        }
    }
}

echo "\nTotal unique LOWW flights: " . count($fetchedFlights) . "\n\n";

// ─── 2. Batch-fetch tracks in parallel ───────────────────────────────
echo "Step 2: Fetching tracks (parallel, {$maxConcurrent} concurrent)...\n";

$allFlightKeys = array_keys($fetchedFlights);
$trackUrls = [];
$trackMap = []; // flightKey => [url, index]

foreach ($allFlightKeys as $idx => $key) {
    $flight = $fetchedFlights[$key];
    $trackTime = (int)(($flight['firstSeen'] + $flight['lastSeen']) / 2);
    $url = "https://opensky-network.org/api/tracks/all?icao24={$flight['icao24']}&time={$trackTime}";
    $trackUrls[$idx] = $url;
    $trackMap[$idx] = $key;
}

$trackResults = apiGetParallel($trackUrls, $auth, $maxConcurrent);

$tracksByKey = [];
foreach ($trackResults as $idx => $result) {
    $key = $trackMap[$idx];
    $stats['api_calls']++;

    if ($result === null) {
        $stats['track_errors']++;
        continue;
    }
    if ($result['code'] === 404) {
        $stats['skip_no_track']++;
        continue;
    }
    if ($result['code'] !== 200) {
        $stats['track_errors']++;
        continue;
    }

    $track = json_decode($result['body'], true);
    if (!is_array($track) || !isset($track['path'])) {
        $stats['skip_no_track']++;
        continue;
    }

    $tracksByKey[$key] = $track;
    $stats['tracks_fetched']++;

    // Estimate credits: track queries with single timestamp probably cost 4
    $stats['credits_used'] += 4;
}

echo "  Tracks fetched: {$stats['tracks_fetched']}, errors: {$stats['track_errors']}, no-track: {$stats['skip_no_track']}\n\n";

// ─── 3. Filter tracks by bounding box and insert ─────────────────────
echo "Step 3: Filtering by bounding box and inserting...\n";

$insertFlightStmt = $db->prepare(
    'INSERT IGNORE INTO flights (icao24, callsign, origin_country, first_seen, last_seen, '
    . 'max_altitude_m, min_altitude_m, is_vie_related, runway_used, runway_confidence) '
    . 'VALUES (:icao24, :callsign, :origin_country, :first_seen, :last_seen, '
    . ':max_altitude_m, :min_altitude_m, :is_vie_related, :runway_used, :runway_confidence)'
);

$insertPosStmt = $db->prepare(
    'INSERT INTO flight_positions (flight_id, captured_at, lat, lon, altitude_m, '
    . 'speed_mps, heading_deg, vertical_rate_mps, on_ground, distance_km, source) '
    . 'VALUES (:flight_id, :captured_at, :lat, :lon, :altitude_m, '
    . ':speed_mps, :heading_deg, :vertical_rate_mps, :on_ground, :distance_km, :source)'
);

// Pre-check all existing flight signatures (icao24 + close first_seen)
$existingStmt = $db->prepare(
    'SELECT icao24, first_seen FROM flights'
);
$existingStmt->execute();
$existingFlights = [];
foreach ($existingStmt->fetchAll() as $row) {
    $ts = strtotime($row['first_seen']);
    $existingFlights[$row['icao24']][$ts] = true;
}

function isDuplicate(string $icao24, int $firstSeenTs, array &$existingFlights, int $window = 300): bool {
    if (!isset($existingFlights[$icao24])) return false;
    foreach (array_keys($existingFlights[$icao24]) as $ts) {
        if (abs($ts - $firstSeenTs) < $window) return true;
    }
    return false;
}

$processedCount = 0;
foreach ($tracksByKey as $key => $track) {
    $flight = $fetchedFlights[$key];
    $icao24 = $flight['icao24'];
    $path = $track['path'];
    $stats['flights_found']++;

    // Filter waypoints within bounding box
    $waypointsInBox = [];
    foreach ($path as $wp) {
        if (count($wp) < 3) continue;
        $lat = (float)$wp[1];
        $lon = (float)$wp[2];
        if ($lat >= $minLat && $lat <= $maxLat && $lon >= $minLon && $lon <= $maxLon) {
            $waypointsInBox[] = $wp;
        }
    }

    if (empty($waypointsInBox)) continue;

    $stats['flights_in_box']++;

    $firstWp = $waypointsInBox[0];
    $lastWp = $waypointsInBox[count($waypointsInBox) - 1];
    $firstSeenBox = gmdate('Y-m-d H:i:s', (int)$firstWp[0]);
    $lastSeenBox = gmdate('Y-m-d H:i:s', (int)$lastWp[0]);

    $altitudes = array_map(fn($wp) => isset($wp[3]) ? (int)round((float)$wp[3]) : null, $waypointsInBox);
    $altitudes = array_filter($altitudes, fn($a) => $a !== null);
    $maxAlt = !empty($altitudes) ? max($altitudes) : null;
    $minAlt = !empty($altitudes) ? min($altitudes) : null;

    $callsign = isset($track['callsign']) ? trim($track['callsign']) : (isset($flight['callsign']) ? trim($flight['callsign']) : null);

    // Skip duplicate
    if (isDuplicate($icao24, (int)$firstWp[0], $existingFlights)) {
        $stats['skipped_dup']++;
        continue;
    }

    // Classify runway
    $posSamples = [];
    foreach ($waypointsInBox as $wp) {
        $posSamples[] = [
            'lat' => (float)$wp[1],
            'lon' => (float)$wp[2],
            'altitude_m' => isset($wp[3]) ? (int)round((float)$wp[3]) : null,
            'heading_deg' => isset($wp[4]) ? (float)$wp[4] : null,
            'vertical_rate_mps' => null,
        ];
    }
    $classification = $classifier->classify($posSamples);

    if ($dryRun) {
        echo "    [DRY] {$icao24} {$callsign} — {$firstSeenBox} → {$lastSeenBox}, "
            . count($waypointsInBox) . " pos, {$classification['runway']}\n";
        $stats['positions_inserted'] += count($waypointsInBox);
        continue;
    }

    // Insert flight
    try {
        $insertFlightStmt->execute([
            'icao24' => $icao24,
            'callsign' => $callsign ?: null,
            'origin_country' => $flight['originCountry'] ?? null,
            'first_seen' => $firstSeenBox,
            'last_seen' => $lastSeenBox,
            'max_altitude_m' => $maxAlt,
            'min_altitude_m' => $minAlt,
            'is_vie_related' => $classification['is_vie_related'] ? 1 : 0,
            'runway_used' => $classification['runway'],
            'runway_confidence' => $classification['confidence'],
        ]);

        // Since we use INSERT IGNORE, check if row was actually inserted
        if ($insertFlightStmt->rowCount() === 0) {
            $stats['skipped_dup']++;
            continue;
        }

        $flightId = (int)$db->lastInsertId();
        $stats['flights_inserted']++;

        // Insert positions
        $count = 0;
        foreach ($waypointsInBox as $wp) {
            $wpTime = gmdate('Y-m-d H:i:s', (int)$wp[0]);
            $wpAlt = isset($wp[3]) ? (int)round((float)$wp[3]) : null;
            $wpHeading = isset($wp[4]) ? (float)$wp[4] : null;
            $wpOnGround = isset($wp[5]) ? (bool)$wp[5] : false;
            $distance = $classifier->distanceFromMannersdorf((float)$wp[1], (float)$wp[2]);

            $insertPosStmt->execute([
                'flight_id' => $flightId,
                'captured_at' => $wpTime,
                'lat' => (float)$wp[1],
                'lon' => (float)$wp[2],
                'altitude_m' => $wpAlt,
                'speed_mps' => null,
                'heading_deg' => $wpHeading,
                'vertical_rate_mps' => null,
                'on_ground' => $wpOnGround ? 1 : 0,
                'distance_km' => round($distance, 2),
                'source' => 'opensky-historical',
            ]);
            $count++;
        }

        $stats['positions_inserted'] += $count;

    } catch (\Throwable $e) {
        $stats['errors']++;
        fwrite(STDERR, "    ✗ {$icao24}: {$e->getMessage()}\n");
    }
}

// ─── 4. Summary ──────────────────────────────────────────────────────
echo "\n";
echo "========================================\n";
echo " Backfill Complete\n";
echo "========================================\n";
echo "  API calls:             {$stats['api_calls']}\n";
echo "  LOWW flights found:    {$stats['flights_found']}\n";
echo "  Tracks fetched:        {$stats['tracks_fetched']}\n";
echo "  Flights crossing box:  {$stats['flights_in_box']}\n";
echo "  Flights inserted:      {$stats['flights_inserted']}\n";
echo "  Positions inserted:    {$stats['positions_inserted']}\n";
echo "  Skipped (duplicate):   {$stats['skipped_dup']}\n";
echo "  Track errors/no-data:  {$stats['track_errors']}/{$stats['skip_no_track']}\n";
echo "  Errors:                {$stats['errors']}\n";
echo "  Credits used (approx): {$stats['credits_used']}\n";

// ─── 5. Refresh daily stats ──────────────────────────────────────────
if ($refreshStats && !$dryRun && $stats['flights_inserted'] > 0) {
    echo "\nRefreshing daily_stats...\n";
    $startDate = gmdate('Y-m-d', $startTs);
    $endDate = gmdate('Y-m-d', $endTs);

    $sql = 'INSERT INTO daily_stats (stat_date, total_flights, vie_related, runway_11_29, '
        . 'runway_16_34, runway_unknown, overflights, avg_altitude_m, hourly_breakdown) '
        . 'SELECT DATE(first_seen) AS stat_date, COUNT(*) AS total_flights, '
        . 'SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END), '
        . 'SUM(CASE WHEN runway_used = \'11/29\' THEN 1 ELSE 0 END), '
        . 'SUM(CASE WHEN runway_used = \'16/34\' THEN 1 ELSE 0 END), '
        . 'SUM(CASE WHEN runway_used = \'UNKNOWN\' THEN 1 ELSE 0 END), '
        . 'SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END), '
        . 'AVG(max_altitude_m), NULL '
        . 'FROM flights WHERE DATE(first_seen) >= :start_date AND DATE(first_seen) <= :end_date '
        . 'GROUP BY DATE(first_seen) '
        . 'ON DUPLICATE KEY UPDATE '
        . 'total_flights = VALUES(total_flights), vie_related = VALUES(vie_related), '
        . 'runway_11_29 = VALUES(runway_11_29), runway_16_34 = VALUES(runway_16_34), '
        . 'runway_unknown = VALUES(runway_unknown), overflights = VALUES(overflights), '
        . 'avg_altitude_m = VALUES(avg_altitude_m)';

    $stmt = $db->prepare($sql);
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    echo "  Daily stats refreshed.\n";
}

echo "\nDone.\n";
