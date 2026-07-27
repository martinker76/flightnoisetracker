<?php
// Local Hetzner config — copy to app.php and edit credentials.
// On Hetzner shared hosting, env vars may not be available to Apache/PHP-FPM,
// so we hardcode here. The deploy README has step-by-step instructions.

declare(strict_types=1);

return [
    'bounding_box' => [
        'min_lat' => 47.947,
        'max_lat' => 48.001,
        'min_lon' => 16.570,
        'max_lon' => 16.638,
    ],
    'altitude_max_m' => 12000,
    'polling_interval_s' => 60,
    'airport' => [
        'code' => 'LOWW',
        'lat' => 48.1103,
        'lon' => 16.5697,
        'vie_related_max_km' => 50,
        'vie_related_max_alt_m' => 6000,
        'runway_classify_max_km' => 20,
        'runway_classify_max_alt_m' => 3000,
    ],
    'opensky' => [
        // Get from https://opensky-network.org/apidoc/index.html
        'client_id'     => 'YOUR_OPENSKY_CLIENT_ID',
        'client_secret' => 'YOUR_OPENSKY_CLIENT_SECRET',
    ],
    'adsbexchange_api_key' => null,
    'base_path' => getenv('FNT_BASE_PATH') ?: '/',
    'db' => [
        // Created via Plesk/phpMyAdmin on Hetzner
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'flightnoisetracker',
        'user' => 'flightnoisetracker_user',
        'pass' => 'CHANGE_ME',
    ],
    'noise_model' => [
        'l_ref_offset_db' => 10.0,
        'd_ref_km' => 1.0,
        'v_ref_mps' => 70.0,
        'alpha_climbout' => 5.0,
        'alpha_appr_trans' => 6.0,
        'alpha_fapp' => 8.0,
        'ground_reflection_max_db' => 2.5,
        'l_amax_min_db' => 20.0,
        'l_amax_max_db' => 110.0,
        'phase_classify_max_km' => 18.0,
    ],
];
