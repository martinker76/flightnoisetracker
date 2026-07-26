<?php

declare(strict_types=1);

/**
 * FlightNoiseTracker — API Entry Point
 *
 * Serves as the front controller for API routes.
 * Static assets and SPA routes are handled by Apache/.htaccess.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load config
$config = require __DIR__ . '/../config/app.php';

// Set timezone
date_default_timezone_set('UTC');

// CORS and security headers are set by .htaccess (single source of truth).
// Preflight OPTIONS requests are also handled by .htaccess rewrite rules.

// Parse method and URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Initialize the router
$router = new App\Router();

// Health
$router->get('/api/health', [App\Controllers\HealthController::class, 'index']);

// Flights
$router->get('/api/flights', [App\Controllers\FlightController::class, 'index']);
$router->get('/api/flights/vie/daily', [App\Controllers\FlightController::class, 'vieDaily']);
$router->get('/api/flights/{id}', [App\Controllers\FlightController::class, 'show']);
$router->get('/api/flights/{id}/track', [App\Controllers\FlightController::class, 'track']);

// Stats
$router->get('/api/stats/summary', [App\Controllers\StatsController::class, 'summary']);
$router->get('/api/stats/runways', [App\Controllers\StatsController::class, 'runways']);
$router->get('/api/stats/hourly', [App\Controllers\StatsController::class, 'hourly']);
$router->get('/api/stats/trend', [App\Controllers\StatsController::class, 'trend']);

// Noise
$router->get('/api/noise', [App\Controllers\NoiseController::class, 'index']);
$router->post('/api/noise', [App\Controllers\NoiseController::class, 'store']);

// Aircraft
$router->get('/api/aircraft/{icao24}', [App\Controllers\AircraftController::class, 'show']);

// Dispatch
$router->dispatch($method, $uri, $config);
