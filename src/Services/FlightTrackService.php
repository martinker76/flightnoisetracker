<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Fetches full flight tracks from OpenSky Network API and caches them locally.
 * Used when stored position samples are insufficient for visualization.
 */
class FlightTrackService
{
    private PDO $db;
    private OpenSkyAuth $auth;

    public function __construct(PDO $db, OpenSkyAuth $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    /**
     * Get the best available track for a flight:
     * 1. Check local cache (flight_tracks table)
     * 2. If not cached, fetch from OpenSky /tracks/all
     * 3. Cache and return
     */
    public function getTrack(int $flightId, string $icao24, string $firstSeen, string $lastSeen): ?array
    {
        // Check cache first
        $cached = $this->getCached($flightId);
        if ($cached !== null) {
            return $cached;
        }

        // Not cached, fetch from OpenSky
        $track = $this->fetchAndCache($flightId, $icao24, $firstSeen, $lastSeen);
        return $track;
    }

    private function getCached(int $flightId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT track_data, fetched_at FROM flight_tracks WHERE flight_id = ? ORDER BY fetched_at DESC LIMIT 1'
        );
        $stmt->execute([$flightId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $data = json_decode($row['track_data'], true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    /**
     * Fetch track from OpenSky and cache it.
     * Tries multiple time windows around the flight's first_seen to find the track.
     */
    private function fetchAndCache(int $flightId, string $icao24, string $firstSeen, string $lastSeen): ?array
    {
        $timestamps = [
            strtotime($firstSeen),
            strtotime($firstSeen) - 300,   // 5 min before
            strtotime($firstSeen) + 300,   // 5 min after
        ];

        foreach ($timestamps as $ts) {
            if ($ts === false || $ts <= 0) continue;

            $url = "https://opensky-network.org/api/tracks/all?icao24={$icao24}&time={$ts}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $this->auth->headers(),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'FlightNoiseTracker/1.0',
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                continue;
            }

            $body = json_decode($response, true);
            if (!is_array($body) || !isset($body['path']) || empty($body['path'])) {
                continue;
            }

            // Cache the result
            $stmt = $this->db->prepare(
                'INSERT INTO flight_tracks (flight_id, icao24, track_data, fetched_at, source) VALUES (?, ?, ?, NOW(), ?)'
            );
            $stmt->execute([$flightId, $icao24, $response, 'opensky']);

            return $body;
        }

        return null;
    }
}
