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

// Inject SMTP relay creds as env vars (from config) so PHPMailer (which uses
// getenv) sees them. The PHP-FPM pool can't set them at the panel layer,
// and we can't reach the panel config. This is the runtime fallback.
//
// Source order (first-wins):
//   1. Existing env vars (panel-set, sysadmin-set, .htaccess SetEnv)
//   2. config/app.php 'smtp' block
//   3. PHP mail() fallback (last resort; on this shared host fails with 550)
if (isset($config['smtp']) && is_array($config['smtp'])) {
    $smtpEnvMap = [
        'FNT_SMTP_HOST'      => $config['smtp']['host']         ?? null,
        'FNT_SMTP_PORT'      => $config['smtp']['port']         ?? null,
        'FNT_SMTP_USERNAME'  => $config['smtp']['username']     ?? null,
        'FNT_SMTP_PASSWORD'  => $config['smtp']['password']     ?? null,
        'FNT_SMTP_FROM_ADDR' => $config['smtp']['from_address'] ?? null,
        'FNT_SMTP_FROM_NAME' => $config['smtp']['from_name']     ?? null,
    ];
    foreach ($smtpEnvMap as $name => $value) {
        if ($value !== null && $value !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}


date_default_timezone_set('UTC');

// CORS and security headers are set by .htaccess (single source of truth).
// Preflight OPTIONS requests are also handled by .htaccess rewrite rules.

// Parse method and URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Support subpath deployments (e.g., /flightnoisetracker/api/health → /api/health)
// Caddy's handle_path strips the prefix for SCRIPT_NAME/SERVER_FILENAME
// but preserves the full path in REQUEST_URI. Strip it here when configured.
$basePath = $config['base_path'] ?? '/';
if ($basePath !== '/') {
    $uriPath = parse_url($uri, PHP_URL_PATH);
    $normalizedBase = rtrim($basePath, '/');
    if (str_starts_with((string)$uriPath, $normalizedBase)) {
        $stripped = substr($uriPath, strlen($normalizedBase));
        $qs = str_contains($uri, '?') ? parse_url($uri, PHP_URL_QUERY) : null;
        $uri = ($stripped ?: '/') . ($qs !== null ? '?' . $qs : '');
    }
}

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
$router->get('/api/stats/aircraft', [App\Controllers\StatsController::class, 'aircraft']);
$router->get('/api/stats/noise', [App\Controllers\StatsController::class, 'noise']);

// Noise
$router->get('/api/noise', [App\Controllers\NoiseController::class, 'index']);
$router->post('/api/noise', [App\Controllers\NoiseController::class, 'store']);

// Aircraft
$router->get('/api/aircraft/{icao24}', [App\Controllers\AircraftController::class, 'show']);

// Contact form (About page)
$router->post('/api/contact', [App\Controllers\ContactController::class, 'store']);

// Dispatch
$router->dispatch($method, $uri, $config);
