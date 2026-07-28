<?php

declare(strict_types=1);

/**
 * One-shot migration to fix the invariant on existing flight rows.
 *
 * Background: prior to the v4.1 fix, the RunwayClassifier could produce a
 * concrete runway stamp (e.g. '11/29') for a flight that turned out not to be
 * VIE-related (e.g. a high-altitude overflight that briefly came within 10 km
 * of LOWW). The DB ended up with rows where runway_used != 'UNKNOWN' but
 * is_vie_related = 0, which is the impossible state.
 *
 * The fix to the classifier (forcing runway = UNKNOWN when !is_vie_related)
 * prevents new bad rows. This script repairs the existing ones.
 *
 * Usage: php cron/fix-stale-runway.php (dry-run by default)
 *        php cron/fix-stale-runway.php --apply
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$autoloader = __DIR__ . '/../vendor/autoload.php';
require_once $autoloader;

use App\Config\Database;

$opts = getopt('', ['apply']);
$apply = isset($opts['apply']);

$db = Database::getConnection();

// Find flights with the invariant violation.
$rows = $db->query(
    'SELECT id, callsign, icao24, is_vie_related, runway_used, runway_confidence, first_seen, last_seen '
    . 'FROM flights WHERE runway_used != \'UNKNOWN\' AND is_vie_related = 0 '
    . 'ORDER BY id DESC'
)->fetchAll(\PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " flights with stale runway stamps (is_vie_related = 0 but runway_used != UNKNOWN):\n";
foreach ($rows as $r) {
    printf(
        "  id=%d %s/%s VIE=%d runway=%s conf=%s first=%s\n",
        $r['id'],
        $r['callsign'] ?? '-',
        $r['icao24'],
        $r['is_vie_related'],
        $r['runway_used'],
        $r['runway_confidence'],
        $r['first_seen']
    );
}

if ($rows === []) {
    echo "\nNothing to fix. Exiting.\n";
    exit(0);
}

if (!$apply) {
    echo "\nDRY RUN. Re-run with --apply to fix.\n";
    exit(0);
}

echo "\nApplying fix...\n";
$stmt = $db->prepare(
    'UPDATE flights SET runway_used = \'UNKNOWN\', runway_confidence = 0 '
    . 'WHERE runway_used != \'UNKNOWN\' AND is_vie_related = 0'
);
$stmt->execute();
$affected = $stmt->rowCount();
echo "Updated {$affected} rows (runway_used -> 'UNKNOWN', runway_confidence -> 0).\n";

// Verify
$remaining = $db->query(
    'SELECT COUNT(*) FROM flights WHERE runway_used != \'UNKNOWN\' AND is_vie_related = 0'
)->fetchColumn();
echo "Remaining rows with the invariant violation: {$remaining}\n";