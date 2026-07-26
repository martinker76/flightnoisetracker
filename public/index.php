<?php

declare(strict_types=1);

/**
 * FlightNoiseTracker API Entry Point
 *
 * All requests are routed through this file via .htaccess rewrite.
 */

// Bootstrap autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use App\Router;
use App\Controllers\FlightController;
use App\Controllers\StatsController;
use App\Controllers\NoiseController;
use App\Controllers\AircraftController;
use App\Controllers\HealthController;

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load config
$config = require __DIR__ . '/../config/app.php';

// Initialize router
$router = new Router();

// Register routes
$router->get('/api/health', [HealthController::class, 'index']);

$router->get('/api/flights', [FlightController::class, 'index']);
$router->get('/api/flights/vie/daily', [FlightController::class, 'vieDaily']);
$router->get('/api/flights/{id}', [FlightController::class, 'show']);
$router->get('/api/flights/{id}/track', [FlightController::class, 'track']);

$router->get('/api/stats/summary', [StatsController::class, 'summary']);
$router->get('/api/stats/runways', [StatsController::class, 'runways']);
$router->get('/api/stats/hourly', [StatsController::class, 'hourly']);
$router->get('/api/stats/trend', [StatsController::class, 'trend']);

$router->post('/api/noise', [NoiseController::class, 'store']);
$router->get('/api/noise', [NoiseController::class, 'index']);

$router->get('/api/aircraft/{icao24}', [AircraftController::class, 'show']);

// Dispatch the request
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $config);
