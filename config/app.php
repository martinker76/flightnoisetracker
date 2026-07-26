<?php

declare(strict_types=1);

return [
    'bounding_box' => [
        'min_lat' => 47.93,
        'max_lat' => 48.01,
        'min_lon' => 16.56,
        'max_lon' => 16.66,
    ],
    'altitude_max_m' => 12000,
    'polling_interval_s' => 60,
    'airport' => [
        'code' => 'LOWW',
        'lat' => 48.1103,
        'lon' => 16.5697,
        'runway_classify_max_km' => 30,
        'runway_classify_max_alt_m' => 6000,
    ],
    'opensky' => [
        'username' => null,
        'password' => null,
    ],
    'adsbexchange_api_key' => null,

    // When deployed under a subpath (e.g., /flightnoisetracker),
    // set this so the PHP router strips the prefix from REQUEST_URI
    'base_path' => getenv('FNT_BASE_PATH') ?: '/',
    'db' => [
        'host' => getenv('FNT_DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('FNT_DB_PORT') ?: 3306),
        'name' => getenv('FNT_DB_NAME') ?: 'fnt',
        'user' => getenv('FNT_DB_USER') ?: 'fnt_app',
        'pass' => getenv('FNT_DB_PASS') ?: '',
    ],
];
