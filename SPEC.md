# FlightNoiseTracker — Specification

**Version:** 1.5  
**Last updated:** 2026-07-31
**Status:** Live  
**Repository:** `https://github.com/martinker76/flightnoisetracker`  
**Live URLs:**
- **Production**: `https://kersch.at/flightnoisetracker/` (Hetzner shared hosting, public)
- **Local dev**: `https://openclaw.kersch.at/flightnoisetracker/` (Caddy on OpenClaw host)

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
- Poll OpenSky via two endpoints in parallel: `states/all` (global feed, paid) and `states/own` (home-feeder feed, free). The two pollers are offset by 30 s so wall-clock cadence is ~30 s; each individual poller fires every 60 s
- Persist every flight + position sample with timestamps. `flight_positions.source` distinguishes rows (`opensky` vs `home-adsb`); both can coexist per flight
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
                  ┌──────────────────────────────────────────┐
                  │  cron (4 entries on staggered 30s offsets)│
                  │  cron/poll.php (--endpoint=states/all)    │  ← every 60s
                  │  cron/poll.php (--endpoint=states/own)    │  ← every 60s, offset 30s
                  │  per-endpoint flock on                    │
                  │    /tmp/fnt-poll-states_{all,own}.lock    │
                  └──────────────────────────────────────────┘
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

**Live polling:** dual endpoint in parallel:

- `GET /states/all` (paid — 1 credit per call). Filtered server-side by the Mannersdorf bounding box.
- `GET /states/own` (free — returns only data fed by an OpenSky-registered ADS-B receiver). The endpoint accepts no bbox params; the poller filters client-side against the same bounding box.

Both endpoints run in parallel via two `cron/poll.php` invocations on a 30 s offset (4 cron entries total, see §10). Each invocation receives `--endpoint` and `--source` as CLI flags; values fall back to `config['opensky']['endpoint']` and `config['opensky']['source']` when omitted. A per-endpoint `flock(LOCK_EX | LOCK_NB)` on `/tmp/fnt-poll-states_{all,own}.lock` prevents concurrent runs of the same endpoint; different endpoints do not share the lock.

**Effective cadence:** each endpoint fires on a 60 s wall-clock cadence; the two endpoints are offset by 30 s, so a poll happens every ~30 s on the wire.

**States include:** ICAO24, callsign, origin_country, lat, lon, altitude, velocity, heading, vertical_rate, on_ground, timestamp.

### 5.2 Historical Backfill (OpenSky — separate OAuth2 credentials)

A CLI PHP script `cron/backfill.php` (**v4**, was v1 → v2 → v3 → v4, current) fetches historical flights:

**Credentials isolation (commit adfa20c):** Backfill has its **own** OAuth2 client (`opensky_backfill` block in `config/app.php`). Live poller (`OpenSkyPoller`) and flight-detail fetcher (`FlightController`) continue to use `opensky`. If `opensky_backfill` is missing, the script **refuses to run** — no silent fallback. This prevents the backfill from exhausting the live poller's `/states/*` credit bucket.

**Pipeline:**

1. **`/flights/arrival?airport=LOWW` AND `/flights/departure?airport=LOWW`** — day-aligned 2-day windows (start=00:00:00 UTC, end=23:59:59 UTC of next day). Each call ~30 credits on `/flights/*` bucket.
2. **Pre-filter** by flight origin/destination ICAO code (skip western/northern European airports, keep southern + rest of world) — reduces track fetches by ~75%.
3. **`/tracks/*`** — parallel `curl_multi` (default concurrency 4) fetches historical tracks, ~4 credits per call.
4. **Insert** box-crossing flights into `flights` and `flight_positions` with `source='opensky-historical'`.

**Critical fixes (commits 97f4eda + e5e07b2):**

- **v4 day-aligned windows** — earlier versions used windows that touched a 3rd day, triggering OpenSky's HTTP 400 "*You can only query across 2 partitions (days)*". v4 explicitly UTC-suffixes strtotime and bounds windows strictly: `2026-06-27 00:00:00 → 2026-06-28 23:59:59` (NOT 23:59:59 of the 3rd day).
- **Retry-After cap at 60s** — server has returned `X-Rate-Limit-Retry-After-Seconds: 9223372036` (epoch overflow, ~292 years). Without the cap, the script would camp forever. Now capped to 60s; on overflow, the batch pauses 2s and continues.
- **`/tracks/*` is account-shared, not per-client** — discovered July 2026. Both `mkersch` and `piotrc` clients see the same `/tracks/*` quota. New credentials help with `/flights/*` and `/states/*` but NOT `/tracks/*`. To fetch historical tracks, wait for the bucket's UTC midnight reset.
   - Typical reduction: ~66% (109 LOWW flights → 37 candidates)
4. **`/tracks/all?icao24=X&time=T`** — parallel curl_multi (concurrency 8) for candidate flights, ~4 credits each
5. **Filter tracks** by bounding box, insert matches

**Limitation:** Track data only available for up to 30 days in the past.

### 5.3 Aircraft Metadata (ADSB.lol — implemented)

Aircraft type resolution uses the public `api.adsb.lol/v2/icao/{icao24}` endpoint (was: planned OpenFlights CSV import). `OpenSkyPoller::resolveAircraftType()` is called when a new flight is inserted and re-tried on subsequent polls if the initial lookup returned null. Cost: zero credits (public API, no rate limit issues observed at LOWW's flight volume).

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
    source ENUM('opensky','adsbexchange','opensky-historical','home-adsb') NOT NULL DEFAULT 'opensky',  -- 'home-adsb' added by migration 006
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

**Algorithm (5 steps + heuristic fallback):**

1. **Find lowest-altitude position within `runway_classify_max_km` of the airport** (default 20 km, configurable; tighter than the VIE-related bounds so only geometry-clean traffic gets a runway stamp).

2. **Determine `is_vie_related` separately** with broader bounds (`vie_related_max_km` 50 km, `vie_related_max_alt_m` 6000 m). A flight may be VIE-related (in the noise area) without being close enough to stamp a runway.

3. **Classify runway from heading** at the lowest-altitude position:
   - Headings 260°–320° or 80°–140° → **RWY 11/29**
   - Headings 140°–200° or 320°–20° → **RWY 16/34**
   - Headings in the gap (20°–80° or 200°–260°) → returns UNKNOWN at this step

4. **(Commit 7cf3326) Heuristic fallback for "mid-turn" flights.** When heading falls in the gap AND the flight is VIE-related AND altitude < 3000 m, default to **RWY 16/34** with confidence capped at 0.5. Rationale: LOWW uses 16/34 ~93% of the time when classifiable (151/162 = 93.2% in prod data); 11/29 is reserved for night-time noise abatement. This catches real runway approaches captured during their turn to/from runway alignment — without it, ~9 flights/day are mis-classified as UNKNOWN. The lower confidence ceiling distinguishes heuristic from clean classifications (which average 0.91).

5. **Invariant enforcement** — `runway_used != 'UNKNOWN' IMPLIES is_vie_related = true`. If a non-VIE-related flight happened to be assigned a runway (e.g., overflight at 7000 m that briefly comes within 10 km of LOWW), the runway stamp is cleared back to UNKNOWN. This is what keeps the dashboard clean — overflights never get a runway attribution even if their heading happens to align.

6. **Confidence calculation** — base 0.5 + bonuses:
   - Heading clarity: +0.0 to +0.3 (perfect alignment +0.3, 30° off +0.0)
   - Altitude: +0.05 to +0.20 (lower = higher)
   - Distance to airport: +0.05 to +0.10 (closer = higher)
   - Capped at 1.0. Heuristic fallback caps at 0.5.

7. **Return value**: `['runway' => '11/29'|'16/34'|'UNKNOWN', 'confidence' => 0.00-1.00, 'is_vie_related' => bool, 'approach_type' => 'arrival'|'departure'|null]`

**Reclassification:** When a new position arrives for a flight already marked UNKNOWN + VIE-related, the poller calls `OpenSkyPoller::reclassifyFlight()` (threshold: 1 position, was 3 before commit 7cf3326). Most flights have only 1-2 position samples (OpenSky `/states/all` returns the latest state per flight), so this lower threshold was needed to keep the runway stamp up-to-date as better data arrives.

A flight is **VIE-related** if its closest approach to LOWW is within 50 km and its altitude at that point is below 6,000 m. Otherwise it's an **overflight**, and the classifier returns `runway = 'UNKNOWN'` regardless of heading (per the invariant in step 5).

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

PHP CLI script `cron/poll.php` runs as a **dual-poller**: two parallel cron jobs staggered by 30 s, each targeting one OpenSky endpoint. A single-host deployment (Hetzner) is sufficient — both endpoints run in parallel because they don't share a file lock.

**Crontab (`/etc/cron.d/fnt-poll`):**

```cron
* * * * * www-data sleep 0;  /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/all --source=opensky    >> /var/log/fnt/poll-osk.log  2>&1
* * * * * www-data sleep 15; /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/own --source=home-adsb >> /var/log/fnt/poll-home.log 2>&1
* * * * * www-data sleep 30; /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/all --source=opensky    >> /var/log/fnt/poll-osk.log  2>&1
* * * * * www-data sleep 45; /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/own --source=home-adsb >> /var/log/fnt/poll-home.log 2>&1
```

Net effect: each endpoint fires on a 60 s wall-clock cadence; the two endpoints are offset by 30 s, so a poll happens every ~30 s on the wire. Per-source logs (`poll-osk.log`, `poll-home.log`) make a noisy one endpoint easy to filter from the other.

**Per-endpoint file lock:** `OpenSkyPoller::poll()` opens `/tmp/fnt-poll-states_all.lock` or `/tmp/fnt-poll-states_own.lock` (the `/` in the endpoint is rewritten to `_`) and calls `flock(LOCK_EX | LOCK_NB)`. If the lock is held by another invocation of the same endpoint, the call logs `"Poll skipped: another <endpoint> poll is already running"` to STDERR and returns with zero counters. Different endpoints never share a lock, so the two parallel pollers do not block each other.

**CLI flags accepted by `cron/poll.php`:**
- `--endpoint=states/all|states/own` — which OpenSky REST endpoint to query. Falls back to `config['opensky']['endpoint']` when omitted (default `states/all`).
- `--source=opensky|home-adsb` — tag written to `flight_positions.source`. Falls back to `config['opensky']['source']` when omitted (default `opensky`).
- `--refresh-stats` — additionally refresh the `daily_stats` materialized table for the current UTC date.

**Endpoint behaviour:**
- `states/all`: ~1 credit/call. Accepts `lamin`/`lomin`/`lamax`/`lomax` bbox query params and is filtered server-side by OpenSky. ~1,440 credits/day at this cadence.
- `states/own`: free (no credits). Returns only aircraft received by an OpenSky-registered ADS-B receiver under our account. The endpoint accepts no bbox params, so the poller filters client-side against the same bounding box (a double-check after the API response). Requires migration 006 to extend the `flight_positions.source` ENUM to include `home-adsb`.

**Flow:**
1. OpenSky `GET /api/{endpoint}` with OAuth2 Bearer token (via `OpenSkyAuth.php`)
2. For each state vector:
   - Normalize ICAO24 to lowercase, strip whitespace from callsign
   - Server-side or client-side bbox filter (per endpoint above)
   - Check if `flights` table already has a flight with (icao24, last_seen) within the last **60 minutes**
   - If new flight: INSERT into `flights`, set is_vie_related and runway via `classifyRunway()`
   - If existing flight: UPDATE `last_seen`, `max_altitude`, etc.
   - INSERT position sample into `flight_positions` with haversine `distance_km` from Mannersdorf center and `source` = the poller's configured `$this->source`
3. Update `poll_state` with timestamp and row counts, keying on `$this->source` (not the literal `opensky` — see "Source-aware poll_state" below)

**Source-aware poll_state:** `updatePollState()` writes to the `poll_state` table using `$this->source` as the primary key. Before the dual-poller refactor the value was hardcoded to the literal `opensky`, so the poller running with `source='home-adsb'` would overwrite or miss the row regardless of which endpoint had actually run. Each endpoint now independently tracks its own health (`poll_state.source IN ('opensky', 'home-adsb')`).

**Cost:**
- `states/all`: ~1,440 credits/day at this cadence (1 credit × 1,440 calls/day). Well within the 4,000/day Standard-tier quota.
- `states/own`: free.

**Coverage rationale:** `states/all` (the global OpenSky feed) catches high-altitude overflights that the home antenna can't see, especially at night when our local receiver's ground gain favours arrivals within the radio horizon. `states/own` catches low/mid arrivals that the home antenna hears first (lower latency, lower credit cost). Both contribute to the same `flights` and `flight_positions` tables; per-row source tagging means downstream stats can attribute detections to one or both paths.

**Home feeder ("Kersch-Vienna"):** the OpenSky-registered ADS-B receiver running on the home rack. Receives via `dump1090-mutability` (gain 43.9), reads SBS/BaseStation on 127.0.0.1:30003, and forwards to OpenSky's network via the official `opensky-feeder` binary. OpenSky feeder registration serial: 2886971112. Feeder started 2026-07-30. Backend queries to that feed go through `states/own`.

**Environment variables** (set in the cron environment, panel-side, or `.htaccess`): `FNT_DB_NAME`, `FNT_DB_USER`, `FNT_DB_PASS`, `FNT_OSKY_CLIENT_ID`, `FNT_OSKY_CLIENT_SECRET`. The cron entries above assume the system environment passes them through to the `www-data` user; alternatively, wrap with `FNT_DB_PASS=...` inline prefixes per entry.

**Token management:** `OpenSkyAuth.php` handles automatic token fetch and refresh (30-min expiry, refreshes at 25-min margin). The token is shared across both pollers because they live in the same DB row — refreshing it for one endpoint refreshes it for the other. For `states/own`, OpenSky accepts both authenticated and anonymous calls from a registered feeder, so the Bearer is functionally optional there; `OpenSkyAuth::headers()` still returns the configured header when credentials are present.

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
        // Which REST endpoint to query. 'states/all' (global feed, paid) or
        // 'states/own' (home-feeder feed, free). Default 'states/all' to keep
        // existing behavior; switch the home-adsb cron entry to 'states/own'
        // once migration 006 has been applied.
        'endpoint' => getenv('FNT_OSKY_ENDPOINT') ?: 'states/all',
        // Tag written to `flight_positions.source` for rows produced by this
        // poller. 'opensky' or 'home-adsb'. Default 'opensky'.
        'source' => getenv('FNT_OSKY_SOURCE') ?: 'opensky',
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

**Runtime env overrides:** All sensitive values (`FNT_DB_PASS`, `FNT_OSKY_CLIENT_SECRET`) are set via environment variables (cron environment, PHP-FPM pool env, or PHP `getenv()`), not in source control. Config defaults are safe fallbacks.

**Where the two new `opensky` keys are read:**
- `opensky.endpoint` (`states/all` | `states/own`) is honoured by the constructor of `App\Services\OpenSkyPoller` and overridable per-invocation by `cron/poll.php --endpoint=...`.
- `opensky.source` (`opensky` | `home-adsb`) is honoured the same way and overridable by `cron/poll.php --source=...`.

Both keys default to the legacy behaviour (`states/all`, `opensky`) so the dual-poller is opt-in per cron entry, not per config.

`config/app.php` (not `app.example.php`) is intentionally gitignored — it carries the live OpenSky client secret and DB password on this host.

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
│   ├── poll.php         # Live poller (dual-poller, --endpoint / --source CLI flags)
│   └── backfill.php     # Historical backfill (manual CLI, v3)
├── config/
│   └── app.php          # All app config with env var overrides
├── migrations/
│   ├── 001_schema.sql              # core schema
│   ├── 002_flight_tracks.sql
│   ├── 003_noise_aircraft.sql
│   ├── 004_add_sel_db.sql
│   ├── 005_contact_messages.sql
│   └── 006_source_home_adsb.sql    # extends flight_positions.source ENUM
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

| Phase | Scope | Status | Commit |
|-------|-------|--------|--------|
| **1. Backend API** | PHP REST API + DB schema + 6 controllers + router | ✅ Done | initial |
| **2. OpenSky Poller** | `cron/poll.php` + Hetzner crontab (4 entries on 30s offsets) + OAuth2 + `RunwayClassifier` — dual-poller over `/states/all` + `/states/own` | ✅ Done | initial (single), 9fc9ad9 (dual-poller + home ADS-B) |
| **3. Frontend** | React + Vite, 6 pages, 12+ components | ✅ Done | initial |
| **4. Stats Dashboard** | Chart.js daily/hourly runway breakdown + trend | ✅ Done | initial |
| **5. Historical Backfill** | `cron/backfill.php` **v4** with day-aligned windows, Retry-After cap, separate OAuth2 creds | ✅ Done | 97f4eda + adfa20c + e5e07b2 |
| **6. Noise Log** | Manual entry form + list | ✅ Done | initial |
| **7. Polish & Deploy** | Caddy, Plesk+Apache, SSL, subpath routing | ✅ Done | initial |
| — | **About page** | ✅ Done | initial |
| — | **Closest-distance UI** | ✅ Done | initial |
| — | **QA fixes (8 issues)** | ✅ Done & re-reviewed | initial |
| — | **OpenSky OAuth2 migration** | ✅ Done | initial |
| — | **Boundary box audibility refinement** | ✅ Done | initial |
| — | **Tooltip icons on Dashboard & Stats** | ✅ Done | initial |
| — | **Cache-control meta tags (stale cache fix)** | ✅ Done | initial |
| — | **Track Visualization on Flight Detail** | ✅ Done | initial |
| — | **Runway Distribution Tooltip** | ✅ Done | initial |
| — | **Callsign Tooltip Positioning Fix** | ✅ Done | initial |
| — | **v1.1 multi-component noise model (SEL + phase-aware dBA)** | ✅ Done | d9af538 |
| — | **Aircraft type resolution (ADSB.lol)** | ✅ Done | d9af538 |
| — | **Dashboard table redesign (overflight vs VIE-unk, Aircraft/Est. dB columns)** | ✅ Done | 97f4eda |
| — | **Mid-turn runway heuristic** (commit 7cf3326) | ✅ Done | 7cf3326 |
| — | **Reclassify existing flights cron** (`cron/reclassify-existing.php`) | ✅ Done | b445de8 |
| — | **Home ADS-B feeder + dual-poller** (Kersch-Vienna / dump1090-mutability, `/states/own`, `home-adsb` source tag, per-endpoint flock) | ✅ Done | 9fc9ad9 |
| — | **Open follow-ups** | ⏳ See "Open Items" | — |

## 14. Data Retention & Privacy

- **Retention:** Indefinite (keep everything). The app runs on a private VPS/personal server with adequate storage.
- **Privacy:** Only public ADS-B data (broadcast by aircraft, aggregated by OpenSky). No PII collected.
- **Backup:** Not yet configured. DB is on local disk only.

## 15. Open Items (Deferred)

| Item | Status | Notes |
|------|--------|-------|
| Aircraft metadata population (OpenFlights) | ❌ Replaced | Resolved via `adsb.lol` lookup per-flight (d9af538). |
| ADS-B Exchange historical | ❌ v2 | OpenSky sufficient for now. |
| Additional airports (Linz, Graz, Bratislava) | ❌ v2 | Single-airport scope (VIE only). |
| Noise sensor integration | ❌ v2 | Manual entries only for now. |
| Unit tests / CI pipeline | ⏳ Partial | `tests/RunwayClassifierTest.php` added (7cf3326). CI not yet wired. |
| Database backup automation | ❌ v2 | DB on local disk only, no offsite copy. |
| Historical backfill 7-day run | ⏳ Waiting | `/tracks/*` bucket exhausted. Reset @ UTC midnight; `cron/backfill.php --days 7 --refresh-stats --request-delay-ms 1000` produces ~1,320 tracks, 100–300 box-crossing flights. |
| FR24 one-time batch script | ⏳ Awaiting go-ahead | Requires FR24 API token + license confirmation. ~4h dev, standalone `cron/fetch-fr24.php`, no main-branch changes. |
| DB password rotation | 🔴 CRITICAL | Both `kersch_flightn_w` prod password (`s5!W!/2FQ6*f`) and dev password leaked in chat. Rotate via Plesk panel + MariaDB `ALTER USER`. |
| Hetzner `www.kersch.at` vhost | ⏳ Panel action | Re-add in KAS panel → Domains (was removed during HTTPS activation). |
| API filter bug (`runway_used=UNKNOWN` ignored) | ⏳ Minor | Filter params are not being respected; returns full dataset. Worth fixing for proper UI filtering. |
| 2026-07-30 11:24–13:10 UTC 429 storm on `/states/own` | ⏳ Investigate | OpenSky throttle tripped while the new home-adsb path was the only active feeder. Root cause not yet isolated; possibly registration-account warm-up or initial credibility window. No recurrence since 13:10 UTC. |
