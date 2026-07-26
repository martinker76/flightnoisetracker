# FlightNoiseTracker — Specification

**Version:** 1.3  
**Status:** Live  
**Repository:** `https://github.com/martinker76/flightnoisetracker`  
**Live URL:** `https://openclaw.kersch.at/flightnoisetracker/`

## 1. Purpose

A web application that tracks aircraft movements over **Mannersdorf am Leithagebirge (2452)**, Lower Austria — a small town approximately 20 km SSE of Vienna International Airport (LOWW). The app determines whether the volume of flights routed across the town is increasing over time, classifies which runway (RWY 11/29 or RWY 16/34) each Vienna-bound/departing flight uses, and provides daily and hourly statistics on runway usage and noise exposure.

**Core question:** Is there an increasing number of flights crossing over Mannersdorf, resulting in growing noise emissions?

## 2. Geographic Scope

### Bounding Box (Mannersdorf am Leithagebirge)

The bounding box is sized so a jet at typical approach altitude (~1,200–1,800 m) produces ~55 dBA at the outer edge — clearly audible above daytime background noise. This gives approximately a 3 km radius from the town center, not administrative borders.

```
min_lat: 47.947
max_lat: 48.001
min_lon: 16.570
max_lon: 16.638
```

Flights **entering this box at any altitude** (0–12,000 m) are captured and persisted.

### Reference Point

- **Mannersdorfer Schloss** (Hauptstraße 48, Mannersdorf am Leithagebirge)
- **Coordinates:** 47.974°N, 16.604°E
- Used for closest-distance calculations in the UI

### Airport Reference

- **Vienna International Airport (VIE / LOWW)**
- Coordinates: 48.1103°N, 16.5697°E
- Runways:
  - **11/29** — 3,500 m, oriented ESE–WNW (~110°/290°)
  - **16/34** — 3,600 m, oriented NNW–SSE (~160°/340°)

Runway determination is inferred from the aircraft's heading, altitude, and position relative to the airport at the closest approach. An approaching aircraft aligning with ~110° or ~290° heading at low altitude is using 11/29; ~160° or ~340° uses 16/34.

## 3. Goals & Non-Goals

### Goals (v1)
- Poll OpenSky every 60 s for live flights within the Mannersdorf bounding box
- Persist every flight + position sample with timestamps
- Classify flights as VIE-related (departure or arrival) or overflight (crossing only)
- Infer runway (11/29 vs 16/34) for VIE-related flights
- Provide daily and hourly **runway statistics** (counts per runway, trend over time)
- Aggregate per-runway flight counts (chart: flights/day broken by runway)
- Compute closest-approach distance from Mannersdorf center for every flight
- Publicly accessible (no login required)
- Unlimited data retention (keep everything, the app runs indefinitely)

### Non-Goals (v1)
- Live noise sensor integration (manual entries only)
- Mobile native apps
- Predictive modelling
- Multi-airport support (VIE only)
- User authentication
- ADS-B Exchange integration (OpenSky free tier is sufficient)

## 4. Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  React SPA   │────▶│  PHP REST API │────▶│  MariaDB    │
│  (Vite)      │     │  (8.3-FPM)    │     │  (10.11)    │
└─────────────┘     └──────┬───────┘     └─────────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │ External APIs   │
                  │ - OpenSky       │
                  │   (OAuth2, live)│
                  │ - OpenFlights   │
                  │   (aircraft DB) │
                  └─────────────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │  fnt-poll.timer  │  ← runs every 60s
                  │  cron/poll.php   │
                  └─────────────────┘
                  ┌─────────────────┐
                  │  cron/backfill.php│  ← manual, one-shot
                  └─────────────────┘
```

### Tech Stack
- **Backend:** PHP 8.3-FPM on Caddy 2.x
- **Database:** MariaDB 10.11+ (MySQL 8.0 compatible)
- **Frontend:** React 18 + Vite + Tailwind CSS + Leaflet (maps) + Chart.js + TanStack Query
- **External APIs:** OpenSky Network (live OAuth2), OpenFlights (aircraft DB — not yet integrated)
- **Deployment:** OpenClaw host (Pterodactyl, Ubuntu), Caddy reverse-proxy, same box as `openclaw.kersch.at`
- **Subpath:** All routes under `https://openclaw.kersch.at/flightnoisetracker/`

## 5. Data Sources

### 5.1 Primary — Live Data (OpenSky Network OAuth2)

**Authentication:** OAuth2 `client_credentials` flow against `auth.opensky-network.org`. Tokens expire after 30 minutes, auto-refreshed by `OpenSkyAuth.php`.

**Credit system (3 independent daily buckets, 4,000 credits each on free auth'd tier):**

| Endpoint Group | Credits/Day | Unit Cost |
|----------------|-------------|-----------|
| `/states/*`    | 4,000       | 1–4 credits (by area) |
| `/tracks/*`    | 4,000       | 4 credits (single timestamp) |
| `/flights/*`   | 4,000       | 4–30 credits (by partitions) |

**Live polling:** `GET /states/all` (unfiltered, ~1 credit per call). The poller fetches all states every 60 s via systemd timer, then filters server-side by bounding box.

**States include:** ICAO24, callsign, origin_country, lat, lon, altitude, velocity, heading, vertical_rate, on_ground, timestamp.

### 5.2 Historical Backfill (OpenSky — same OAuth2 credentials)

A CLI PHP script `cron/backfill.php` (v3) fetches historical flights via:

1. **`/flights/arrival?airport=LOWW`** — LOWW arrivals (≤2 day windows, ~30 credits)
2. **`/flights/departure?airport=LOWW`** — LOWW departures (>2 day windows, ~30 credits)
3. **Pre-filter** by flight origin/destination ICAO code:
   - Western/Northern European airports (UK, NL, BE, FR, ES, PT, CH, DK, SE, NO, FI) → skip (approach from west/north, rarely cross box)
   - Italian airports (LI*) → keep (approach from south, frequently cross)
   - German (ED*) + rest of world → keep
   - Unknown → keep
   - Typical reduction: ~66% (109 LOWW flights → 37 candidates)
4. **`/tracks/all?icao24=X&time=T`** — parallel curl_multi (concurrency 8) for candidate flights, ~4 credits each
5. **Filter tracks** by bounding box, insert matches

**Limitation:** Track data only available for up to 30 days in the past.

### 5.3 Aircraft Metadata (OpenFlights — planned)

`aircraft.dat` (CSV, monthly refresh) maps ICAO24 → aircraft type. Table exists but metadata population not yet implemented.

### 5.4 Noise Measurements

Manual entry only for v1. Users record a dB level at a given time, optionally correlated with a flight known to have passed overhead. Implemented as a simple form in the React frontend, stored in `noise_readings` table.

## 6. Database Schema

```sql
-- Cached flight tracks fetched from OpenSky /tracks/all
CREATE TABLE flight_tracks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT UNSIGNED NOT NULL,
    icao24 CHAR(6) NOT NULL,
    track_data JSON NOT NULL,              -- raw GeoJSON track from OpenSky
    fetched_at DATETIME NOT NULL,
    source ENUM('opensky-tracks') NOT NULL DEFAULT 'opensky-tracks',
    INDEX idx_flight (flight_id),
    INDEX idx_icao24 (icao24),
    FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Core flight records (one per unique flight through the box)
CREATE TABLE flights (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    icao24 CHAR(6) NOT NULL,
    callsign VARCHAR(8),
    origin_country VARCHAR(64),
    first_seen DATETIME NOT NULL,        -- first detection in our box
    last_seen DATETIME NOT NULL,         -- last detection in our box
    max_altitude_m SMALLINT UNSIGNED,
    min_altitude_m SMALLINT UNSIGNED,
    -- VIE classification fields
    is_vie_related BOOLEAN DEFAULT FALSE, -- TRUE if heading indicates VIE dep/arr
    runway_used ENUM('11/29','16/34','UNKNOWN') DEFAULT 'UNKNOWN',
    runway_confidence DECIMAL(3,2),      -- 0.00–1.00 confidence score
    operator VARCHAR(128),
    aircraft_type VARCHAR(8) DEFAULT NULL,  -- ICAO aircraft type code (e.g., B738, A320)
    registration VARCHAR(16),
    estimated_db DECIMAL(5,1) DEFAULT NULL, -- estimated peak noise at Mannersdorf center
    INDEX idx_icao24 (icao24),
    INDEX idx_first_seen (first_seen),
    INDEX idx_vie_related (is_vie_related),
    INDEX idx_runway (runway_used),
    UNIQUE KEY uniq_icao_seen (icao24, first_seen)
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Flight position samples (track data, high volume)
CREATE TABLE flight_positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flight_id BIGINT UNSIGNED NOT NULL,
    captured_at DATETIME(3) NOT NULL,    -- millisecond precision
    lat DECIMAL(9,6) NOT NULL,
    lon DECIMAL(9,6) NOT NULL,
    altitude_m SMALLINT UNSIGNED,
    speed_mps DECIMAL(5,1),
    heading_deg DECIMAL(5,1),
    vertical_rate_mps DECIMAL(4,1),
    on_ground BOOLEAN DEFAULT FALSE,
    distance_km DECIMAL(6,2),           -- haversine distance from Mannersdorf center
    source ENUM('opensky','adsbexchange','opensky-historical') NOT NULL DEFAULT 'opensky',
    INDEX idx_flight (flight_id),
    INDEX idx_captured (captured_at),
    FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Noise readings (manual entry)
CREATE TABLE noise_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    measured_at DATETIME NOT NULL,
    db_level DECIMAL(4,1) NOT NULL,
    lat DECIMAL(9,6),
    lon DECIMAL(9,6),
    notes TEXT,
    correlated_flight_id BIGINT UNSIGNED NULL,
    INDEX idx_measured (measured_at),
    FOREIGN KEY (correlated_flight_id) REFERENCES flights(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Aircraft metadata cache (from OpenFlights)
CREATE TABLE aircraft (
    icao24 CHAR(6) PRIMARY KEY,
    registration VARCHAR(16),
    aircraft_type VARCHAR(32),           -- e.g., 'A320', 'B738'
    operator VARCHAR(128),
    model VARCHAR(64),
    last_updated DATETIME NOT NULL
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Poller state tracking
CREATE TABLE poll_state (
    source VARCHAR(32) PRIMARY KEY,
    last_poll_at DATETIME NOT NULL,
    last_success_at DATETIME,
    last_error TEXT,
    rows_inserted INT DEFAULT 0,
    rows_updated INT DEFAULT 0
) ENGINE=InnoDB CHARSET=utf8mb4;

-- Materialized daily runway stats (refreshed by poller & backfill)
CREATE TABLE daily_stats (
    stat_date DATE PRIMARY KEY,
    total_flights INT DEFAULT 0,
    vie_related INT DEFAULT 0,
    runway_11_29 INT DEFAULT 0,
    runway_16_34 INT DEFAULT 0,
    runway_unknown INT DEFAULT 0,
    overflights INT DEFAULT 0,
    avg_altitude_m DECIMAL(7,1),
    hourly_breakdown JSON,               -- {"00":5, "01":3, ... "23":12}
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARSET=utf8mb4;
```

**Note on closest distance:** The `closest_distance_km` shown in the UI is computed as a SQL subquery:
```sql
SELECT MIN(distance_km) FROM flight_positions WHERE flight_id = flights.id AS closest_distance_km
```
It is injected into the flight list/detail queries — not a stored column.

## 7. Runway Classification Algorithm

Runway determination is a server-side PHP function in `RunwayClassifier.php`:

```
function classify(array $positions): array
```

**Heuristic:**
1. Find the position sample with the **lowest altitude** within 30 km of the airport
2. Determine if the flight is **departing** (altitude increasing + heading away from airport) or **arriving** (altitude decreasing + heading toward airport)
3. Check the **heading** at that closest point:
   - Headings 260°–320° → **RWY 11/29** (approaching from east) or departing toward west
   - Headings 80°–140° → **RWY 11/29** (approaching from west) or departing toward east
   - Headings 140°–200° → **RWY 16/34** (approaching from north) or departing toward south
   - Headings 320°–20° → **RWY 16/34** (approaching from south) or departing toward north
4. Assign confidence based on altitude (lower altitude = higher confidence) and heading clarity
5. Returns `['runway' => '11/29'|'16/34'|'UNKNOWN', 'confidence' => 0.00-1.00, 'is_vie_related' => bool]`

A flight is classified as **VIE-related** if its closest approach to LOWW is within 50 km and its altitude at that point is below 6,000 m. Otherwise it's an **overflight**.

## 8. API Design

### REST Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/flights` | List flights (paginated, filterable) |
| GET | `/api/flights/{id}` | Single flight with positions |
| GET | `/api/flights/{id}/track` | GeoJSON FeatureCollection flight track |
| GET | `/api/flights/vie/daily` | Daily VIE-related flight list |
| GET | `/api/stats/summary` | Aggregated counts (day/week/month) |
| GET | `/api/stats/runways` | Per-runway breakdown over date range |
| GET | `/api/stats/hourly?date=YYYY-MM-DD` | Hourly breakdown for a given day |
| GET | `/api/stats/trend?days=30` | Trend data (flights/day over N days) |
| POST | `/api/noise` | Submit noise reading (also accepts `lat`/`lon` or `latitude`/`longitude`) |
| GET | `/api/noise` | List noise readings |
| GET | `/api/aircraft/{icao24}` | Aircraft metadata |
| GET | `/api/health` | Service health (last poll time, DB status — sanitized errors) |

### Query Parameters

- Pagination: `?page=1&per_page=50` (max 500)
- Filtering: `?date_from=2026-07-01&date_to=2026-07-26`
- Filtering: `?vie_only=true`
- Filtering: `?runway=11/29`
- Sorting: `?sort=first_seen&order=desc`

### Response Format

```json
{
  "data": [ ... ],
  "meta": {
    "page": 1,
    "per_page": 50,
    "total": 1234,
    "pages": 25
  }
}
```

Track endpoint returns GeoJSON FeatureCollection:

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "LineString",
        "coordinates": [[lon, lat, alt], ...]
      },
      "properties": { ... }
    }
  ]
}
```

Errors:

```json
{
  "error": "Human-readable message",
  "code": "INVALID_PARAMETER"
}
```

## 9. Frontend Pages

### 9.1 Dashboard (default landing page)
- Summary cards: total flights today/this week, VIE-related count, runway breakdown
- Recent flights table with inline pagination (Callsign, Aircraft, Enter, Runway, Alt, Est. dB) — compact layout
- **Daily trend chart** (the core feature): flights over Mannersdorf per day, broken into runway 11/29, runway 16/34, overflights, stacked bar chart
- **Hourly chart** for a selected day: flights per hour, colored by runway

### 9.2 Map View
- Leaflet map centered on Mannersdorf (47.974, 16.604) with bounding box overlay
- Historical flight tracks rendered as polylines, color-coded by runway (11/29 = blue, 16/34 = red, unknown = grey)
- Date range picker to filter by time
- Toggle: show all flights / VIE-related only
- Hover/click on a track → flight detail tooltip (callsign, altitude, runway)

### 9.3 Flight Detail
- Single flight page with:
  - Callsign, ICAO24, aircraft type, operator, registration
  - VIE classification (yes/no), runway (if VIE), confidence
  - **Entering Airspace** / **Leaving Airspace** timestamps
  - **Duration Over Airspace** (h/m/s)
  - **Closest Point to Mannersdorf** (km)
  - **Aircraft Type** (ICAO type code, displayed next to callsign)
  - **Estimated Noise** (Est. dB — estimated peak noise at Mannersdorf center)
  - Track on Leaflet map
  - Altitude profile chart (altitude over time)
  - Raw data table of position samples
- Track is fetched from OpenSky when stored positions are insufficient, providing a complete flight path through the region

### 9.4 Noise Log
- Simple form: date/time, dB level, optional notes, optional correlation with flight
- Date list of previous entries
- Option to correlate with a flight from the database

### 9.5 About Page
- Lazy-loaded route at `/about` (separate chunk, ~2.7 kB gzipped)
- Content: purpose, data sources, bounding box rationale (~55 dBA audibility), reference point (Mannersdorfer Schloss), runway classification method, noise tracking, statistics, technical notes
- **Noise Estimation Explanation:** How the geometric model works (formula, reference values L_ref=80 dBA at d_ref=300 m, assumptions), why it provides consistent relative comparison rather than calibrated measurement
- **Aircraft Type Resolution:** How ICAO type codes are fetched from the OpenSky aircraft metadata database
- **Future Improvements:** Per-type reference levels, atmospheric attenuation, ground reflection modeling

### 9.6 Tooltip Icons

Reusable `TooltipIcon` component renders a small gray question-mark (`?`) circle next to any label. On hover (desktop) or tap (mobile), it displays a short explanation in a positioned tooltip box.

**Props:**
- `text` (required): tooltip explanation text
- `position` (optional): `'center' | 'left' | 'right'` — controls horizontal positioning of the tooltip box. Defaults to `'center'`. `'left'` positioning is used in the Dashboard "Recent Flights" Callsign header to prevent the tooltip from being clipped by the adjacent map.

**Where used:**
- **Dashboard** — all 4 summary cards (Today's Flights, VIE-Related, Runway 11/29, Overflights), all 6 table headers (Callsign, Enter, Leave, Runway, Alt, Closest), and all 3 period cards (This Week, This Month, All Time)
- **Statistics page** — section headers (Trend, Runway Usage by Day, Hourly Breakdown) and key-number cards (Total Flights, Avg/Day, Most Active Hour, Most Used Runway)
- **RunwayChart** — "Runway Distribution" heading: *"Distribution of flights by runway configuration for today"*

Implementation: `ui/src/components/TooltipIcon.tsx` — Tailwind CSS hover classes, dark-mode compatible, max-width wrapping on small screens.

### 9.7 Navigation
- Navbar with links: Dashboard, Map, Flights, Noise, Statistics, About
- Responsive: desktop horizontal bar, mobile hamburger menu
- Active route highlighting

### 9.8 Flight Track Visualization

The Flight Detail page fetches full flight tracks from the OpenSky `/tracks/all` API (OAuth2) when stored position data is insufficient (< 2 points in `flight_positions`). A new `FlightTrackService` handles fetching and caching tracks in the `flight_tracks` table to avoid repeated API calls.

**Flow:**
1. Flight Detail page requests the track GeoJSON endpoint (`/api/flights/{id}/track`)
2. Server checks `flight_positions` count for the flight
3. If < 2 positions, `FlightTrackService` queries `flight_tracks` table for a cached track
4. If not cached, fetches from OpenSky `GET /tracks/all?icao24=X&time=T` (4 credits) and stores in `flight_tracks`
5. Track data is merged into the GeoJSON response

**Map rendering:**
- Altitude-colored track segments (gradient from low to high altitude)
- Start and end markers (green = start, red = end)
- Mannersdorf center reference point marker
- Bounding box overlay

### 9.9 Noise Estimation Model

The system calculates an estimated peak A-weighted sound level (L_Amax) at Mannersdorf center for each flight using a simple geometric spreading model.

**Formula:**

```
L_est = L_ref − 20 × log₁₀(d_slant / d_ref)
```

**Parameters:**

| Parameter | Value | Notes |
|-----------|-------|-------|
| L_ref | 80 dBA | Reference level — generic jet on approach, gear down, approach thrust |
| d_ref | 300 m | Reference distance |
| d_slant | √(d_horiz² + alt²) | Slant distance in km |

**Slant distance calculation:**
- `d_horiz` = closest horizontal distance from flight's closest position to Mannersdorf center (47.974°N, 16.604°E), in km
- `alt` = altitude_m / 1000 (meters → km)

**Constraints:**
- **Clamping:** capped at 95 dBA maximum, minimum 0 dBA
- **Precision:** one decimal place, stored as `DECIMAL(5,1)` in DB
- **Displayed as:** "Est. dB" column with tooltip explaining the model and its limitations

**Important caveats (documented on About page):**
- No engine power/thrust data
- No atmospheric attenuation
- No ground reflection
- No aircraft-type differentiation
- Single-point estimate using closest approach only
- Provides consistent relative comparison rather than calibrated measurement

### 9.10 Aircraft Type Resolution

Each flight stores an ICAO aircraft type code (e.g., B738, A320, B772) fetched from the ADSB.lol public API.

**Lookup process:**
1. During flight processing in the poller, the ICAO24 is used to query ADSB.lol
2. **URL:** `https://api.adsb.lol/v2/icao/{icao24}`
3. **Auth:** None required (public API)
4. **Response:** JSON with `ac[].t` field containing ICAO aircraft type code (e.g. `"BCS3"` for A220-300, `"B738"` for 737-800)
5. **Fallback:** `null` if lookup fails (displayed as "—" in UI)

**Display:**
- Shown as "Aircraft" column in flight tables (font-mono styling)
- Tooltip explains the lookup source (ADSB.lol)

### 9.11 Dashboard "Recent Flights" Table Redesign

The Dashboard "Recent Flights" table is redesigned for a more compact and informative layout.

**Removed columns:**
- **"Leave" (Leaving Airspace):** Flights cross the small bounding box in ~1 minute, so enter/leave timestamps are functionally identical with the current 60-second polling resolution
- **"Closest":** Moved to the Flight Detail page only

**Added columns:**
- **"Aircraft":** ICAO aircraft type code (font-mono), with tooltip explaining lookup source
- **"Est. dB":** Estimated peak noise at Mannersdorf center, with tooltip explaining the geometric model

**Compact column order:** Callsign → Aircraft → Enter → Runway → Alt → Est. dB

### 9.12 Stats Page Additions

Three new sections added to the Statistics page:

1. **Aircraft Type Distribution:** Bar chart showing top aircraft types by flight frequency, with tooltip explaining the data source
2. **Noise Level Statistics:** Summary card showing min/max/avg estimated dB values across all flights, with tooltip explaining the estimation model
3. **Noise Distribution:** Bar chart (histogram) showing estimated dB range buckets: <45, 45–50, 50–55, 55–60, 60+ dBA, with tooltip

### 9.13 About Page Updates

New sections added to the About page:

- **Noise Estimation:** Explanation of the geometric spreading model — formula (`L_est = L_ref − 20 × log₁₀(d_slant / d_ref)`), reference values (L_ref = 80 dBA at d_ref = 300 m), and all assumptions/caveats
- **Aircraft Type Resolution:** How ICAO type codes are fetched from the OpenSky aircraft metadata database, fallback behavior
- **Calibration Disclaimer:** Why this provides a consistent relative comparison across flights, not a calibrated absolute measurement
- **Future Improvements:** Planned enhancements — per-type reference levels (different L_ref for A320 vs B77W vs turboprop), atmospheric attenuation modeling, ground reflection corrections

## 10. Polling Implementation

PHP CLI script `cron/poll.php` runs via systemd timer `fnt-poll.timer` every 60 seconds.

**Flow:**
1. OpenSky `GET /states/all` with OAuth2 Bearer token (via `OpenSkyAuth.php`)
2. Filter states where lat/lon fall within the Mannersdorf bounding box
3. For each match:
   - Normalize ICAO24 to lowercase, strip whitespace from callsign
   - Check if `flights` table already has a flight with (icao24, first_seen) within the last **60 minutes** (not 24h — tightened during QA)
   - If new flight: INSERT into `flights`, set is_vie_related and runway via `classifyRunway()`
   - If existing flight: UPDATE `last_seen`, `max_altitude`, etc.
   - INSERT position sample into `flight_positions` with haversine `distance_km` from Mannersdorf center
4. Update `poll_state` with timestamp and row counts

**Systemd setup:**
- `/etc/systemd/system/fnt-poll.service` — runs `php /var/www/flightnoisetracker/cron/poll.php`
  - Environment vars: `FNT_DB_NAME`, `FNT_DB_USER`, `FNT_DB_PASS`, `FNT_OSKY_CLIENT_ID`, `FNT_OSKY_CLIENT_SECRET`
- `/etc/systemd/system/fnt-poll.timer` — fires every 60 seconds, no random delay

**Token management:** `OpenSkyAuth.php` handles automatic token fetch and refresh (30-min expiry, refreshes at 25-min margin). Single `TokenManager` instance reused across all requests.

## 11. Configuration

`config/app.php` (returning array):

```php
<?php
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
        'runway_classify_max_km' => 30,
        'runway_classify_max_alt_m' => 6000,
    ],
    'opensky' => [
        'client_id' => getenv('FNT_OSKY_CLIENT_ID') ?: null,   // OAuth2, not username/password
        'client_secret' => getenv('FNT_OSKY_CLIENT_SECRET') ?: null,
    ],
    'adsbexchange_api_key' => null,   // unused in v1
    'base_path' => getenv('FNT_BASE_PATH') ?: '/',    // subpath prefix, e.g. '/flightnoisetracker'
    'db' => [
        'host' => getenv('FNT_DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('FNT_DB_PORT') ?: 3306),
        'name' => getenv('FNT_DB_NAME') ?: 'fnt',
        'user' => getenv('FNT_DB_USER') ?: 'fnt_app',
        'pass' => getenv('FNT_DB_PASS') ?: '',
    ],
];
```

**Runtime env overrides:** All sensitive values (`FNT_DB_PASS`, `FNT_OSKY_CLIENT_SECRET`) are set via environment variables in the systemd service file, not in source control. Config defaults are safe fallbacks.

## 12. Deployment

**Current host:** OpenClaw node (Pterodactyl)
- Hostname: `pterodactyl` (LAN: `192.168.1.76`)
- Web server: Caddy 2.x (not Apache)
- Domain: `openclaw.kersch.at` (Let's Encrypt via Caddy auto-TLS)

**Caddy config snippet:**

```
handle_path /flightnoisetracker* {
    root * /var/www/flightnoisetracker/public
    route {
        @php path *.php
        php_fastcgi @php unix//run/php/php8.3-fpm.sock { ... }
        @api path /api/*
        php_fastcgi @api unix//run/php/php8.3-fpm.sock { ... }
        @public { not path *.php; not path /api/* }
        handle @public {
            try_files {path} /index.html
            file_server
        }
    }
}
```

**Directory layout:**

```
/var/www/flightnoisetracker/
├── public/              # Caddy document root
│   ├── index.html       # React SPA entry (Vite build output)
│   ├── index.php        # API bootstrap (front controller)
│   ├── .htaccess        # (kept for git parity, not active under Caddy)
│   └── assets/          # Vite build output (JS, CSS)
├── src/
│   ├── Controllers/     # FlightController, StatsController, etc.
│   ├── Models/          # Flight, FlightPosition, NoiseReading, Aircraft
│   ├── Services/        # RunwayClassifier, OpenSkyAuth, OpenSkyPoller
│   ├── Config/          # Database.php
│   └── Router.php       # Lightweight REST router
├── cron/
│   ├── poll.php         # Live poller (every 60s via systemd timer)
│   └── backfill.php     # Historical backfill (manual CLI, v3)
├── config/
│   └── app.php          # All app config with env var overrides
├── migrations/
│   └── 001_schema.sql
├── ui/                  # React source (Vite project)
│   ├── src/             # Pages, components, hooks, types
│   ├── vite.config.ts   # base: '/flightnoisetracker/'
│   └── package.json
├── vendor/              # Composer dependencies
├── composer.json
└── SPEC.md              # This file
```

**Build notes for SPA:**
- Vite `base: '/flightnoisetracker/'` — baked into compiled JS at build time
- Env vars **must** be set at build time: `VITE_BASE_URL=/flightnoisetracker` `VITE_API_BASE=/flightnoisetracker/api`
- `emptyOutDir: false` in `vite.config.ts` — preserves PHP files (`index.php`, `.htaccess`) in `public/` across rebuilds
- After build, ensure `public/index.php` and `.htaccess` are still present (git-restore if wiped)
- `ui/index.html` sets Cache-Control no-cache meta tags to prevent stale HTML from caching old JS chunk references

## 13. Implementation Status

| Phase | Scope | Status |
|-------|-------|--------|
| **1. Backend API** | PHP REST API + DB schema + 6 controllers + router | ✅ Done |
| **2. OpenSky Poller** | `cron/poll.php` + systemd timer + OAuth2 + RunwayClassifier | ✅ Done |
| **3. Frontend** | React + Vite, 6 pages, 12+ components | ✅ Done |
| **4. Stats Dashboard** | Chart.js daily/hourly runway breakdown + trend | ✅ Done |
| **5. Historical Backfill** | `cron/backfill.php` v3 with airport pre-filter | ✅ Done (run pending credit reset) |
| **6. Noise Log** | Manual entry form + list | ✅ Done |
| **7. Polish & Deploy** | Caddy config, systemd, SSL, subpath routing | ✅ Done |
| — | **About page** | ✅ Done |
| — | **Closest-distance UI** | ✅ Done |
| — | **QA fixes (8 issues)** | ✅ Done & re-reviewed |
| — | **OpenSky OAuth2 migration** | ✅ Done |
| — | **Boundary box audibility refinement** | ✅ Done |
| — | **Tooltip icons on Dashboard & Stats** | ✅ Done |
| — | **Cache-control meta tags (stale cache fix)** | ✅ Done |
| — | **Track Visualization on Flight Detail** | ✅ Done |
| — | **Runway Distribution Tooltip** | ✅ Done |
| — | **Callsign Tooltip Positioning Fix** | ✅ Done |
| — | Aircraft type resolution (OpenSky metadata) | ❌ Not yet implemented |
| — | Noise estimation (geometric model) | ❌ Not yet implemented |
| — | Dashboard table redesign (remove Leave, add Aircraft/Est. dB) | ❌ Not yet implemented |
| — | Stats page aircraft/noise cards | ❌ Not yet implemented |
| — | About page noise/aircraft explanation | ❌ Not yet implemented |

## 14. Data Retention & Privacy

- **Retention:** Indefinite (keep everything). The app runs on a private VPS/personal server with adequate storage.
- **Privacy:** Only public ADS-B data (broadcast by aircraft, aggregated by OpenSky). No PII collected.
- **Backup:** Not yet configured. DB is on local disk only.

## 15. Open Items (Deferred)

| Item | Status |
|------|--------|
| Aircraft metadata population (OpenFlights) | ⏳ Not started |
| ADS-B Exchange historical (not needed — OpenSky sufficient) | ❌ v2 |
| Additional airports (Linz, Graz, Bratislava) | ❌ v2 |
| Noise sensor integration | ❌ v2 |
| Unit tests / CI pipeline | ❌ v2 |
| Database backup automation | ❌ v2 |
| Historical backfill 7-day run (waiting for track bucket reset) | ⏳ Tue Jul 27 |
