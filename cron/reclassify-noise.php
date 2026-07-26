<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config/app.php';

$db = \App\Config\Database::getConnection();

// Helper function matching poller logic
function calcDb(float $distKm, float $altM): float {
    $altKm = $altM / 1000.0;
    $dSlant = sqrt($distKm * $distKm + $altKm * $altKm);
    if ($dSlant <= 0) return 95.0;
    $estDb = 80.0 - 20.0 * log10($dSlant / 0.3);
    return round(min(95.0, max(0.0, $estDb)), 1);
}

echo "Recomputing estimated_db from position data...\n";
$flights = $db->query('SELECT id FROM flights WHERE estimated_db IS NULL ORDER BY id')->fetchAll();

echo "Found " . count($flights) . " flights to process.\n";

$updated = 0;
foreach ($flights as $f) {
    // Get closest position
    $stmt = $db->prepare(
        'SELECT distance_km, altitude_m FROM flight_positions
         WHERE flight_id = ? AND distance_km IS NOT NULL
         ORDER BY distance_km ASC LIMIT 1'
    );
    $stmt->execute([$f['id']]);
    $pos = $stmt->fetch();

    if ($pos && $pos['altitude_m'] !== null) {
        $estDb = calcDb((float)$pos['distance_km'], (float)$pos['altitude_m']);
        $upd = $db->prepare('UPDATE flights SET estimated_db = ? WHERE id = ?');
        $upd->execute([$estDb, $f['id']]);
        $updated++;
        if ($updated % 100 === 0) {
            echo "  {$updated} flights processed...\n";
        }
    }
}

echo "Updated {$updated} flights with noise estimates.\n";
