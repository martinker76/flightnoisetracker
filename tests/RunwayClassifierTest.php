<?php
/**
 * Runway classifier test — covers heading-bucket gap heuristic (Option C).
 *
 * Run from workspace root:
 *   php tests/RunwayClassifierTest.php
 *
 * The heuristic kicks in when:
 *   - runway classify returned UNKNOWN (heading in 20-80° or 200-260° gap)
 *   - flight is VIE-related
 *   - altitude < 3000m
 * And defaults to 16/34 (LOWW dominant runway, ~93% of classified traffic)
 * with reduced confidence.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\RunwayClassifier;

$airport = [
    'code' => 'LOWW',
    'lat' => 48.1103,
    'lon' => 16.5697,
    'vie_related_max_km' => 50,
    'vie_related_max_alt_m' => 6000,
    'runway_classify_max_km' => 20,
    'runway_classify_max_alt_m' => 3000,
];

$classifier = new RunwayClassifier($airport);

// Helper: build a position near the airport at given altitude/heading/vertical_rate
function makePos(float $lat, float $lon, int $alt, ?float $hdg, ?float $vrate = null): array {
    return [
        'lat' => $lat,
        'lon' => $lon,
        'altitude_m' => $alt,
        'heading_deg' => $hdg,
        'vertical_rate_mps' => $vrate,
    ];
}

$tests = [];

// Test 1: Clean runway 34 approach (heading 343°, low alt, descending)
$tests[] = [
    'name' => 'runway 34 approach (clean)',
    'positions' => [
        makePos(47.97, 16.62, 800, 343.0, -3.0),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
    'expect_confidence_min' => 0.5,
];

// Test 2: Runway 11 approach (heading 290°, low alt)
$tests[] = [
    'name' => 'runway 11 approach (clean)',
    'positions' => [
        makePos(48.05, 16.50, 500, 290.0, -3.0),
    ],
    'expect_runway' => '11/29',
    'expect_vie' => true,
    'expect_confidence_min' => 0.5,
];

// Test 3: Mid-turn descending — heading 60° at 800m, vie-related (heuristic kicks in)
$tests[] = [
    'name' => 'mid-turn descending (heading 60° @ 800m) — heuristic → 16/34',
    'positions' => [
        makePos(47.96, 16.61, 815, 62.7, -3.9),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
    'expect_confidence_min' => 0.5,
    'expect_confidence_max' => 0.7,  // heuristic reduces confidence
];

// Test 4: Mid-turn climbing — heading 70° at 2500m, vie-related (heuristic kicks in)
$tests[] = [
    'name' => 'mid-turn climbing (heading 70° @ 2500m) — heuristic → 16/34',
    'positions' => [
        makePos(47.96, 16.62, 2530, 70.9, 11.7),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
    'expect_confidence_min' => 0.5,
];

// Test 5: High altitude overflight with heading in gap — heuristic MUST NOT kick in
// (alt > 3000m means not low-altitude, alt > 6000m means not vie-related)
$tests[] = [
    'name' => 'high altitude overflight in gap — heuristic NOT applied (overflight)',
    'positions' => [
        makePos(47.96, 16.61, 8000, 60.0, 0.0),
    ],
    'expect_runway' => 'UNKNOWN',
    'expect_vie' => false,  // alt > 6000m vie_related_max_alt → not noise-relevant
    'expect_confidence_min' => 0.0,
];

// Test 6: Mid altitude (5000m) in gap — heuristic NOT applied (alt > 3000m)
// But alt 5000m IS within vie_related bounds (max 6000m), so vie=true
$tests[] = [
    'name' => 'mid altitude in gap (5000m) — heuristic NOT applied (alt > 3000m cutoff)',
    'positions' => [
        makePos(47.96, 16.61, 5000, 60.0, 0.0),
    ],
    'expect_runway' => 'UNKNOWN',  // alt > 3000m means heuristic doesn't fire
    'expect_vie' => true,           // alt 5000m < 6000m vie_related_max_alt
    'expect_confidence_min' => 0.0,
];

// Test 7: PEV106 — the canonical mid-turn flight (2 positions, descending)
$tests[] = [
    'name' => 'PEV106 — multi-position mid-turn, lowest alt = 754m hdg 345.8° → 16/34 (no heuristic needed)',
    'positions' => [
        makePos(47.95, 16.64, 922, 23.4, -3.9),
        makePos(47.99, 16.63, 754, 345.8, -3.6),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
    'expect_confidence_min' => 0.7,  // clean heading match (345.8° is in runway 34 range)
];

// Test 8: A clean runway 11/29 that would happen to have heading slightly in gap (should still be 16/34 via heuristic)
// heading 79° at 800m low alt
$tests[] = [
    'name' => 'heading 79° at low alt — heuristic → 16/34 (heading just below 80° threshold for 11/29)',
    'positions' => [
        makePos(47.97, 16.62, 800, 79.0, -3.0),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
];

// Test 9: heading 21° at low alt — heading just above 20° threshold for 16/34
$tests[] = [
    'name' => 'heading 21° at low alt — heuristic → 16/34 (heading just above 20° threshold)',
    'positions' => [
        makePos(47.96, 16.62, 800, 21.0, -3.0),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
];

// Test 10: heading 19° at low alt — should be classified as 16/34 cleanly (in range)
$tests[] = [
    'name' => 'heading 19° at low alt — clean 16/34 (in bucket)',
    'positions' => [
        makePos(47.96, 16.62, 800, 19.0, -3.0),
    ],
    'expect_runway' => '16/34',
    'expect_vie' => true,
];

$pass = 0;
$fail = 0;
foreach ($tests as $i => $t) {
    $result = $classifier->classify($t['positions']);
    $errors = [];

    if ($result['runway'] !== $t['expect_runway']) {
        $errors[] = "runway: expected '{$t['expect_runway']}', got '{$result['runway']}'";
    }
    if ($result['is_vie_related'] !== $t['expect_vie']) {
        $errors[] = "is_vie_related: expected " . ($t['expect_vie'] ? 'true' : 'false') . ", got " . ($result['is_vie_related'] ? 'true' : 'false');
    }
    if (isset($t['expect_confidence_min']) && $result['confidence'] < $t['expect_confidence_min']) {
        $errors[] = "confidence: expected >= {$t['expect_confidence_min']}, got {$result['confidence']}";
    }
    if (isset($t['expect_confidence_max']) && $result['confidence'] > $t['expect_confidence_max']) {
        $errors[] = "confidence: expected <= {$t['expect_confidence_max']}, got {$result['confidence']}";
    }

    if (empty($errors)) {
        $pass++;
        printf("  ✓ %s\n", $t['name']);
    } else {
        $fail++;
        printf("  ✗ %s\n", $t['name']);
        foreach ($errors as $e) {
            printf("      %s\n", $e);
        }
        printf("      Got: runway=%s vie=%s conf=%.2f\n",
            $result['runway'], $result['is_vie_related'] ? 'Y' : 'N', $result['confidence']);
    }
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
