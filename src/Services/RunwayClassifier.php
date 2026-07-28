<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Runway classifier for Vienna International Airport (LOWW).
 *
 * Determines which runway (11/29 or 16/34) a flight is using based on
 * heading, altitude, and proximity to the airport.
 *
 * LOWW Reference:
 *   Coordinates: 48.1103°N, 16.5697°E
 *   Runway 11/29: ~110°/290° heading (ESE-WNW)
 *   Runway 16/34: ~160°/340° heading (NNW-SSE)
 */
class RunwayClassifier
{
    private float $airportLat;
    private float $airportLon;
    private float $classifyMaxKm;
    private int $classifyMaxAltM;
    private float $vieRelatedMaxKm;
    private int $vieRelatedMaxAltM;

    public function __construct(array $airportConfig)
    {
        $this->airportLat = (float)$airportConfig['lat'];
        $this->airportLon = (float)$airportConfig['lon'];
        // Tight bounds — only positions within these get a runway stamp
        $this->classifyMaxKm = (float)($airportConfig['runway_classify_max_km'] ?? 10);
        $this->classifyMaxAltM = (int)($airportConfig['runway_classify_max_alt_m'] ?? 3000);
        // Broad bounds — used only for the is_vie_related flag
        $this->vieRelatedMaxKm = (float)($airportConfig['vie_related_max_km'] ?? 50);
        $this->vieRelatedMaxAltM = (int)($airportConfig['vie_related_max_alt_m'] ?? 6000);
    }

    /**
     * Classify a flight's runway usage based on position samples.
     *
     * @param array $positions Array of position samples with keys:
     *                         lat, lon, altitude_m, heading_deg, vertical_rate_mps
     * @return array{runway: string, confidence: float, is_vie_related: bool,
     *               approach_type: string|null}
     */
    public function classify(array $positions): array
    {
        if (empty($positions)) {
            return [
                'runway' => 'UNKNOWN',
                'confidence' => 0.0,
                'is_vie_related' => false,
                'approach_type' => null,
            ];
        }

        // Step 1: Find a position within the TIGHT (runway-classifiable) bounds.
        // Only these get a runway stamped — anything further out is either an
        // overflight or still too far from the airport to confirm a corridor.
        $bestPosition = null;
        $bestDistance = PHP_FLOAT_MAX;
        $bestAltitude = PHP_INT_MAX;

        foreach ($positions as $pos) {
            $lat = (float)$pos['lat'];
            $lon = (float)$pos['lon'];
            $alt = isset($pos['altitude_m']) && $pos['altitude_m'] !== null ? (int)$pos['altitude_m'] : null;

            $distance = $this->haversineDistance($lat, $lon, $this->airportLat, $this->airportLon);

            // Tight bound for runway classification (typically 10 km / 3000 m)
            if ($distance > $this->classifyMaxKm) {
                continue;
            }

            // Skip positions with null altitude
            if ($alt === null) {
                continue;
            }

            if ($alt < $bestAltitude) {
                $bestAltitude = $alt;
                $bestDistance = $distance;
                $bestPosition = $pos;
            } elseif ($alt === $bestAltitude && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestPosition = $pos;
            }
        }

        // Step 2: Determine broad VIE-related flag separately. A flight may be
        // is_vie_related=true (in our noise area) without being close enough to
        // stamp a specific runway.
        $isVieRelated = $this->checkVieRelated($positions);

        // No position qualifies for runway classification — leave runway UNKNOWN
        // but keep the VIE-related flag from the broad check.
        if ($bestPosition === null) {
            return [
                'runway' => 'UNKNOWN',
                'confidence' => 0.0,
                'is_vie_related' => $isVieRelated,
                'approach_type' => null,
            ];
        }

        $heading = isset($bestPosition['heading_deg']) && $bestPosition['heading_deg'] !== null ? (float)$bestPosition['heading_deg'] : null;
        $altitude = isset($bestPosition['altitude_m']) && $bestPosition['altitude_m'] !== null ? (int)$bestPosition['altitude_m'] : null;
        $verticalRate = isset($bestPosition['vertical_rate_mps']) && $bestPosition['vertical_rate_mps'] !== null ? (float)$bestPosition['vertical_rate_mps'] : null;

        // Step 3: Classify runway from heading
        if ($heading === null) {
            return [
                'runway' => 'UNKNOWN',
                'confidence' => 0.0,
                'is_vie_related' => $isVieRelated,
                'approach_type' => null,
            ];
        }

        $runway = $this->classifyRunwayFromHeading($heading);
        $confidence = $this->calculateConfidence($heading, $altitude, $bestDistance);

        // Step 3.5: Heuristic fallback for "mid-turn" flights.
        // When heading falls in the gap between runway buckets (20-80° or 200-260°),
        // the flight is most likely a 16/34 arrival/departure captured mid-turn
        // (LOWW uses 16/34 ~93% of the time when classifiable). If the flight is
        // VIE-related and at low altitude (i.e., clearly on approach/departure,
        // not a high-altitude overflight), default to the dominant runway with
        // reduced confidence to reflect the heuristic nature of the call.
        // This fixes ~9 flights/day that are correctly in our noise area but
        // happen to be sampled during their turn to/from runway alignment.
        if ($runway === 'UNKNOWN' && $isVieRelated && $altitude !== null && $altitude < 3000) {
            $runway = '16/34';
            $confidence = max(0.5, $confidence * 0.7);
        }

        // Step 4: Determine approach/departure type
        $approachType = $this->determineApproachType($verticalRate, $heading, $bestPosition, $positions);

        // Step 5: Enforce invariant. If a flight is NOT VIE-related (no noise-relevant
        // approach), it should never have a runway stamp. The classification can
        // produce a concrete runway from a high-altitude position that briefly comes
        // within 10 km of LOWW, but that's an overflight (e.g., at 7000 m) and the
        // runway stamp is misleading. Clear it.
        // Invariant: runway_used != 'UNKNOWN' IMPLIES is_vie_related = true.
        if (!$isVieRelated && $runway !== 'UNKNOWN') {
            $runway = 'UNKNOWN';
            $confidence = 0.0;
        }

        return [
            'runway' => $runway,
            'confidence' => $confidence,
            'is_vie_related' => $isVieRelated,
            'approach_type' => $approachType,
        ];
    }

    /**
     * Classify runway from aircraft heading.
     *
     * RWY 11/29: headings ~110° or ~290° (range 80°-140° and 260°-320°)
     * RWY 16/34: headings ~160° or ~340° (range 140°-200° and 320°-20°)
     */
    private function classifyRunwayFromHeading(float $heading): string
    {
        // Normalize heading to 0-360
        $heading = fmod($heading + 360, 360);
        if ($heading < 0) {
            $heading += 360;
        }

        // RWY 11/29: 80°-140° (approaching from W / departing to E)
        //             260°-320° (approaching from E / departing to W)
        if (($heading >= 80 && $heading <= 140) || ($heading >= 260 && $heading <= 320)) {
            return '11/29';
        }

        // RWY 16/34: 140°-200° (approaching from N / departing to S)
        //             320°-360° or 0°-20° (approaching from S / departing to N)
        if (($heading >= 140 && $heading <= 200) || $heading >= 320 || $heading <= 20) {
            return '16/34';
        }

        return 'UNKNOWN';
    }

    /**
     * Calculate confidence score based on heading clarity, altitude, and distance.
     */
    private function calculateConfidence(float $heading, ?int $altitude, float $distance): float
    {
        $heading = fmod($heading + 360, 360);
        if ($heading < 0) {
            $heading += 360;
        }

        $confidence = 0.5; // Base confidence

        // Heading clarity: how close to ideal runway heading
        $idealHeadings1129 = [110, 290];
        $idealHeadings1634 = [160, 340];

        $minDeviation1129 = min(
            abs($this->angleDiff($heading, 110)),
            abs($this->angleDiff($heading, 290))
        );
        $minDeviation1634 = min(
            abs($this->angleDiff($heading, 160)),
            abs($this->angleDiff($heading, 340))
        );

        $minDeviation = min($minDeviation1129, $minDeviation1634);

        // Perfect alignment = +0.3, 30° off = +0.0
        $headingBonus = max(0, 0.3 * (1 - $minDeviation / 30));
        $confidence += $headingBonus;

        // Altitude bonus: lower altitude = higher confidence (approach/departure)
        if ($altitude !== null) {
            if ($altitude < 500) {
                $confidence += 0.2;
            } elseif ($altitude < 1000) {
                $confidence += 0.15;
            } elseif ($altitude < 2000) {
                $confidence += 0.1;
            } elseif ($altitude < 3000) {
                $confidence += 0.05;
            }
        }

        // Distance bonus: closer to airport = higher confidence
        if ($distance < 5) {
            $confidence += 0.1;
        } elseif ($distance < 10) {
            $confidence += 0.05;
        }

        return min(1.0, max(0.0, round($confidence, 2)));
    }

    /**
     * Determine if the aircraft is approaching or departing.
     */
    private function determineApproachType(
        ?float $verticalRate,
        float $heading,
        array $currentPos,
        array $allPositions
    ): ?string {
        if ($verticalRate === null) {
            return null;
        }

        // Descending = likely arriving, ascending = likely departing
        if ($verticalRate < -1.0) {
            return 'arrival';
        }
        if ($verticalRate > 1.0) {
            return 'departure';
        }

        return null;
    }

    /**
     * Check if any position in the set qualifies as VIE-related.
     * VIE-related: closest approach to LOWW within vie_related_max_km AND altitude below vie_related_max_alt_m.
     * This is a BROAD check (separate from runway classification) used for noise-exposure statistics.
     */
    private function checkVieRelated(array $positions): bool
    {
        foreach ($positions as $pos) {
            $lat = (float)$pos['lat'];
            $lon = (float)$pos['lon'];
            $alt = isset($pos['altitude_m']) && $pos['altitude_m'] !== null ? (int)$pos['altitude_m'] : null;

            $distance = $this->haversineDistance($lat, $lon, $this->airportLat, $this->airportLon);

            if ($distance <= $this->vieRelatedMaxKm && $alt !== null && $alt <= $this->vieRelatedMaxAltM) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate the angular difference between two headings.
     */
    private function angleDiff(float $a, float $b): float
    {
        $diff = fmod($a - $b + 360, 360);
        if ($diff > 180) {
            $diff = 360 - $diff;
        }
        return $diff;
    }

    /**
     * Haversine distance between two points in kilometers.
     */
    public function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Calculate distance from Mannersdorf center for a position.
     */
    public function distanceFromMannersdorf(float $lat, float $lon): float
    {
        // Mannersdorf am Leithagebirge center (47.974, 16.604)
        return $this->haversineDistance($lat, $lon, 47.974, 16.604);
    }
}
