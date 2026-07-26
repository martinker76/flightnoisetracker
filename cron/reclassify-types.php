<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config/app.php';

$db = \App\Config\Database::getConnection();

echo "Fetching flights without aircraft_type...\n";
$flights = $db->query('SELECT id, icao24 FROM flights WHERE aircraft_type IS NULL ORDER BY id')->fetchAll();

echo "Found " . count($flights) . " flights to process.\n";

$count = 0;
$errors = 0;
foreach ($flights as $f) {
    $icao24 = strtolower($f['icao24']);
    $url = "https://api.adsb.lol/v2/icao/{$icao24}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'FlightNoiseTracker/1.0',
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200 && $resp) {
        $data = json_decode($resp, true);
        if (!empty($data['ac'][0]['t'])) {
            $type = strtoupper(trim($data['ac'][0]['t']));
            $stmt = $db->prepare('UPDATE flights SET aircraft_type = ? WHERE id = ?');
            $stmt->execute([$type, $f['id']]);
            $count++;
            echo "  Flight {$f['id']} ({$icao24}): {$type}\n";
        }
    } else {
        $errors++;
    }
    usleep(100000); // 100ms rate limit
}

echo "Updated {$count} flights with aircraft types ({$errors} errors).\n";
