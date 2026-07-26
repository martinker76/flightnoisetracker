<?php

declare(strict_types=1);

/**
 * FlightNoiseTracker — OpenSky Network Polling Script
 *
 * This script is executed every 60 seconds via systemd timer.
 * It fetches live aircraft states from OpenSky, filters by the Mannersdorf
 * bounding box, and persists flight + position data.
 *
 * Usage: php cron/poll.php [--refresh-stats]
 *
 * Systemd timer setup:
 *   /etc/systemd/system/fnt-poll.service
 *   /etc/systemd/system/fnt-poll.timer
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// Bootstrap autoloader
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}
require_once $autoloader;

use App\Services\OpenSkyPoller;

// Load config
$config = require __DIR__ . '/../config/app.php';

$refreshStats = in_array('--refresh-stats', $argv ?? [], true);

$startTime = microtime(true);

try {
    $poller = new OpenSkyPoller($config);
    $result = $poller->poll();

    $elapsed = round((microtime(true) - $startTime) * 1000, 1);

    $logLine = sprintf(
        "[%s] Poll complete: %d states found, %d inserted, %d updated, %d positions, %d errors (%.1fms)\n",
        gmdate('Y-m-d H:i:s'),
        $result['states_found'],
        $result['inserted'],
        $result['updated'],
        $result['positions'],
        $result['errors'],
        $elapsed
    );

    fwrite(STDOUT, $logLine);

    // Optionally refresh daily stats for today
    if ($refreshStats) {
        $today = gmdate('Y-m-d');
        $poller->refreshDailyStats($today);
        fwrite(STDOUT, "  Daily stats refreshed for {$today}\n");
    }

    // Log to file if available
    $logFile = __DIR__ . '/../var/log/poll.log';
    if (is_dir(dirname($logFile))) {
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    exit(0);
} catch (\Throwable $e) {
    $elapsed = round((microtime(true) - $startTime) * 1000, 1);
    $errorLine = sprintf(
        "[%s] Poll FAILED: %s (%.1fms)\n",
        gmdate('Y-m-d H:i:s'),
        $e->getMessage(),
        $elapsed
    );

    fwrite(STDERR, $errorLine);

    // Log to file if available
    $logFile = __DIR__ . '/../var/log/poll.log';
    if (is_dir(dirname($logFile))) {
        file_put_contents($logFile, $errorLine, FILE_APPEND | LOCK_EX);
    }

    exit(1);
}
