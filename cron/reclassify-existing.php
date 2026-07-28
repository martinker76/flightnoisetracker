<?php
/**
 * One-shot reclassification of existing flights.
 * Fixes flights that were inserted before the heading_deg field alias was added.
 *
 * Run: php cron/reclassify-existing.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/app.php';
$db = new PDO(
    "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$classifier = new \App\Services\RunwayClassifier($config['airport']);

// Get all flights with UNKNOWN runway
$stmt = $db->query("SELECT id, callsign, is_vie_related FROM flights WHERE runway_used = 'UNKNOWN'");
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($flights);
echo "Found $total flights to reclassify...\n";

$updated = 0;
foreach ($flights as $flight) {
    $id = (int)$flight['id'];
    
    // Get positions from DB (these have heading_deg, vertical_rate_mps column names)
    $pstmt = $db->prepare("SELECT lat, lon, altitude_m, heading_deg, vertical_rate_mps, captured_at FROM flight_positions WHERE flight_id = ? ORDER BY captured_at ASC");
    $pstmt->execute([$id]);
    $positions = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($positions)) {
        continue;
    }
    
    $classification = $classifier->classify($positions);
    
    echo "  Flight {$id} ({$flight['callsign']}): VIE=" . ($classification['is_vie_related'] ? 'Y' : 'N') 
        . " RWY={$classification['runway']} CONF={$classification['confidence']}";
    
    if ($classification['runway'] !== 'UNKNOWN' || $classification['is_vie_related'] !== 
        (bool)($flight['is_vie_related'] ?? false)) {
        
        $stmt2 = $db->prepare(
            "UPDATE flights SET is_vie_related = ?, runway_used = ?, runway_confidence = ? WHERE id = ?"
        );
        $stmt2->execute([
            $classification['is_vie_related'] ? 1 : 0,
            $classification['runway'],
            $classification['confidence'],
            $id
        ]);
        echo " → UPDATED";
        $updated++;
    }
    echo "\n";
}

// Refresh daily stats
echo "\nRefreshing daily stats...\n";
require_once __DIR__ . '/../src/Models/Flight.php';
$flightModel = new \App\Models\Flight();
$statsStmt = $db->prepare(
    "INSERT INTO daily_stats (stat_date, total_flights, vie_related, runway_11_29, runway_16_34, runway_unknown, overflights, avg_altitude_m)
    SELECT 
        DATE(first_seen) as stat_date,
        COUNT(*) as total_flights,
        SUM(CASE WHEN is_vie_related = TRUE THEN 1 ELSE 0 END) as vie_related,
        SUM(CASE WHEN runway_used = '11/29' THEN 1 ELSE 0 END) as runway_11_29,
        SUM(CASE WHEN runway_used = '16/34' THEN 1 ELSE 0 END) as runway_16_34,
        SUM(CASE WHEN runway_used = 'UNKNOWN' THEN 1 ELSE 0 END) as runway_unknown,
        SUM(CASE WHEN is_vie_related = FALSE THEN 1 ELSE 0 END) as overflights,
        AVG(max_altitude_m) as avg_altitude_m
    FROM flights
    GROUP BY DATE(first_seen)
    ON DUPLICATE KEY UPDATE
        total_flights = VALUES(total_flights),
        vie_related = VALUES(vie_related),
        runway_11_29 = VALUES(runway_11_29),
        runway_16_34 = VALUES(runway_16_34),
        runway_unknown = VALUES(runway_unknown),
        overflights = VALUES(overflights),
        avg_altitude_m = VALUES(avg_altitude_m)"
);
$statsStmt->execute();

echo "Done. Updated $updated / $total flights.\n";