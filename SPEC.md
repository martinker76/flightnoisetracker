# FlightNoiseTracker — Specification

**Version:** 1.0  
**Status:** Final (ready for implementation)  
**Target Model for Implementation:** Qwen 3.7 Max (dashscope)  
**Target Model for QA/Review:** Claude Sonnet 4.5 (modelrelay)

## 1. Purpose

A web application that tracks aircraft movements over **Mannersdorf am Leithagebirge (2452)**, Lower Austria — a small city approximately 20 km SSE of Vienna International Airport (LOWW). The app determines whether the volume of flights routed across the city is increasing over time, classifies which runway (RWY 11/29 or RWY 16/34) each Vienna-bound/departing flight uses, and provides daily and hourly statistics on runway usage and noise exposure.

**Core question:** Is there an increasing number of flights crossing over Mannersdorf, resulting in growing noise emissions?

## 2. Geographic Scope

### Bounding Box (Mannersdorf am Leithagebirge)

The target area is centered on Mannersdorf (~47.97 N, 16.61 E). The bounding box should generously cover the town and the immediate airspace through which VIE arrivals and departures pass overhead.

```
min_lat: 47.93
max_lat: 48.01
min_lon: 16.56
max_lon: 16.66
```

Flights **entering this box at any altitude** (0–12,000 m) are captured and persisted.

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
- Publicly accessible (no login required)
- Unlimited data retention (keep everything, the app runs indefinitely)

### Non-Goals (v1)
- Live noise sensor integration (manual entries only)
- Mobile native apps
- Predictive modelling
- Multi-airport support (VIE only)
- User authentication

## 4. Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  React SPA  │────▶│  PHP REST API │────▶│  MySQL DB   │
│  (Vite)     │     │  (Apache)     │     │  (MariaDB)  │
└─────────────┘     └──────┬───────┘     └─────────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │ External APIs   │
                  │ - OpenSky       │
                  │ - ADS-B Exchange│
                  │ - OpenFlights   │
                  └─────────────────┘
                           │
                           ▼
                  ┌─────────────────┐
                  │  Cron: poll.php │  ← runs every 60s
                  └─────────────────┘
```

### Tech Stack
- **Backend:** PHP 8.2+ on Apache 2.4
- **Database:** MariaDB 10.11+ (MySQL 8.0 compatible)
- **Frontend:** React 18 + Vite + Tailwind CSS + Leaflet (maps) + Chart.js + TanStack Query
- **External APIs:** OpenSky Network (live), ADS-B Exchange (historical), OpenFlights (aircraft DB)
- **Deployment:** Hetzner Cloud CX22 (2 vCPU, 4GB RAM, 40GB SSD) — Ubuntu 24.04 LAMP stack

## 5. Data Sources

### 5.1 Primary — Live Data

**OpenSky Network REST API** — `GET /states/all` (no auth needed for anonymous)
- Returns all aircraft within/unfiltered — we filter server-side by bounding box
- Anonymous rate limit: ~10 s intervals
- Authenticated (free signup): 400 credits/day for historical queries
- States include: ICAO24, callsign, origin_country, lat, lon, altitude, velocity, heading, vertical_rate, on_ground, timestamp

**Polling:** A PHP cron script runs every 60 seconds via systemd timer. It fetches states matching the bounding box, deduplicates by ICAO24 + first_seen, and inserts/updates flights + positions.

### 5.2 Secondary — Historical Backfill

**ADS-B Exchange** (via RapidAPI) or **ADS-B Exchange REST API v2**
- Provides historical flight tracks (typically last 30 days)
- Requires API key (paid tier — approx $10–20/month)
- Can be used to fill the database on first run with "the last few weeks"

**Alternatively:** OpenSky authenticated API (free, but limited to 400 credits/day)
- `/get/flights/aircraft` or `/get/flights/area` — fetches historical tracks per aircraft
- 400 credits/day means ~40–80 queries/day, which is enough to backfill aircraft by ICAO24

### 5.3 Aircraft Metadata

**OpenFlights** aircraft database — `aircraft.dat` (CSV, monthly refresh)
- Maps ICAO24 → aircraft type (e.g., A320, B738), manufacturer
- Cached in `aircraft` table

### 5.4 Noise Measurements

Manual entry only for v1. Users record a dB level at a given time, optionally correlated with a flight known to have passed overhead. Implemented as a simple form in the React frontend, stored in `noise_readings` table.

## 6. Database Schema

```sql
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
    aircraft_type VARCHAR(32),
    registration VARCHAR(16),
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
    distance_km DECIMAL(6,2),           -- distance from Mannersdorf center
    source ENUM('opensky','adsbexchange') NOT NULL DEFAULT 'opensky',
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

-- Materialized daily runway stats (refreshed by cron)
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

## 7. Runway Classification Algorithm

Runway determination is a server-side PHP function triggered when a flight's first_seen position is within ~30 km of LOWW (48.1103, 16.5697):

```
function classifyRunway(array $positions, float $airportLat, float $airportLon): array
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
- Returns `['runway' => '11/29'|'16/34'|'UNKNOWN', 'confidence' => 0.00-1.00]`

A flight is classified as **VIE-related** if its closest approach to LOWW is within 50 km and its altitude at that point is below 6,000 m. Otherwise it's an **overflight**.

## 8. API Design

### REST Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/flights` | List flights (paginated) |
| GET | `/api/flights/{id}` | Single flight with positions |
| GET | `/api/flights/{id}/track` | GeoJSON flight track |
| GET | `/api/flights/vie/daily` | Daily VIE-related flight list |
| GET | `/api/stats/summary` | Aggregated counts (day/week/month) |
| GET | `/api/stats/runways` | Per-runway breakdown over date range |
| GET | `/api/stats/hourly?date=YYYY-MM-DD` | Hourly breakdown for a given day |
| GET | `/api/stats/trend?days=30` | Trend data (flights/day over N days) |
| POST | `/api/noise` | Submit noise reading |
| GET | `/api/noise` | List noise readings |
| GET | `/api/aircraft/{icao24}` | Aircraft metadata |
| GET | `/api/health` | Service health (last poll time, DB status) |

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

Errors:
```json
{
  "error": "Human-readable message",
  "code": "INVALID_PARAMETER"
}
```

## 9. Frontend Pages

### 9.1 Map View (default landing page)
- Leaflet map centered on Mannersdorf (47.97, 16.61) with bounding box overlay
- Historical flight tracks rendered as polylines, color-coded by runway (11/29 = blue, 16/34 = red, unknown = grey)
- Date range picker to filter by time
- Toggle: show all flights / VIE-related only
- Hover/click on a track → flight detail tooltip (callsign, aircraft type, runway)

### 9.2 Stats Dashboard
- **Daily trend chart** (the core feature): flights over Mannersdorf per day, broken into runway 11/29, runway 16/34, overflights, stacked bar chart
- **Hourly chart** for a selected day: flights per hour, colored by runway
- **Monthly aggregation**: total, VIE, per-runway counts
- **Operator breakdown**: top 10 operators passing over Mannersdorf
- **Date selector** to view specific ranges
- Embedded in the same React SPA, tab-based navigation

### 9.3 Flight Detail
- Single flight page with:
  - Callsign, ICAO24, aircraft type, operator, registration
  - VIE classification (yes/no), runway (if VIE), confidence
  - Track on Leaflet map
  - Altitude profile chart (altitude over time)
  - Speed profile chart
  - Raw data table of position samples

### 9.4 Noise Log
- Simple form: date/time, dB level, optional notes
- Date list of previous entries
- Option to correlate with a flight from the database

## 10. Polling Implementation

A PHP CLI script `cron/poll.php` runs via systemd timer every 60 seconds.

**Flow:**
1. OpenSky `GET /states/all` → receive all aircraft states
2. Filter states where lat/lon fall within the Mannersdorf bounding box
3. For each match:
   - Normalize ICAO24 to lowercase, strip whitespace from callsign
   - Check if `flights` table already has a flight with (icao24, first_seen) within the last 24 h
   - If new flight: INSERT into `flights`, set is_vie_related and runway via `classifyRunway()`
   - If existing flight: UPDATE `last_seen`, `max_altitude`, etc.
   - INSERT position sample into `flight_positions`
4. Update `poll_state` with timestamp and row counts

**Systemd timer setup:**
- `/etc/systemd/system/fnt-poll.service` — runs `php /var/www/fnt/cron/poll.php`
- `/etc/systemd/system/fnt-poll.timer` — fires every 60 seconds, randomized delay of 0–10 s

## 11. Configuration

`config/app.php` (returning array):
```php
<?php
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
        'username' => null,   // optional, for authenticated tier
        'password' => null,
    ],
    'adsbexchange_api_key' => null,
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'fnt',
        'user' => 'fnt_app',
        'pass' => '...',
    ],
];
```

## 12. Deployment (Hetzner)

**Target:** Hetzner Cloud CX22 (2 vCPU, 4GB RAM, 40GB SSD) — ~€5/month

**Stack setup:**
- Ubuntu 24.04 LTS
- Apache 2.4 with mod_rewrite + PHP 8.2-FPM
- MariaDB 10.11
- Let's Encrypt SSL via certbot
- Git deployment from `https://github.com/martinker76/flightnoisetracker`

**Directory layout:**
```
/var/www/fnt/
├── public/              # Apache document root
│   ├── index.html       # React SPA entry
│   ├── index.php        # API bootstrap
│   └── .htaccess
├── src/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   │   └── RunwayClassifier.php
│   ├── Config/
   │   └── Database.php
├── cron/
│   └── poll.php
├── config/
│   └── app.php
├── migrations/
│   └── 001_schema.sql
├── vendor/              # Composer dependencies
└── composer.json
```

## 13. Implementation Roadmap

| Phase | Scope | Est. Time |
|-------|-------|-----------|
| **1. Backend API** | PHP REST API + DB schema + migration + flight listing endpoints | 1–2 days |
| **2. OpenSky Poller** | `cron/poll.php` + systemd timer + runway classification | 1 day |
| **3. Frontend (Map)** | React + Vite setup, Leaflet map, flight overlay, detail page | 2 days |
| **4. Stats Dashboard** | Chart.js daily/hourly runway breakdown, trend view, date picker | 1–2 days |
| **5. Historical Backfill** | ADS-B Exchange or OpenSky authenticated backfill script | 1 day |
| **6. Noise Log** | Simple manual entry form + list | 0.5 day |
| **7. Polish & Deploy** | Hetzner setup, SSL, cron, monitoring, go-live | 1–2 days |

## 14. Open Items (Deferred)

| Item | Status |
|------|--------|
| ADS-B Exchange API key procurement | ⏳ After MVP |
| Historical backfill of first few weeks | ⏳ Phase 5 |
| Additional airports (Linz, Graz, Bratislava) | ❌ v2 |
| Noise sensor integration | ❌ v2 |
| Unit tests / CI pipeline | ❌ v2 |

---

## Phase Transition

**Next: Phase 1 — Implementation by Qwen 3.7 Max**

The following spec section will be implemented by switching to `dashscope/qwen3.7-max`:

> Build the PHP backend with the complete REST API, database schema migrations, and the OpenSky polling cron script. Output production-ready files in `/root/.openclaw/workspace/flightnoisetracker/`.
