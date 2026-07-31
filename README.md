# FlightNoiseTracker

A web application that tracks aircraft movements over **Mannersdorf am Leithagebirge (2452)**, Lower Austria — approximately 20 km SSE of Vienna International Airport (LOWW/VIE).

The app determines whether the volume of flights routed across the city is increasing over time, classifies which runway (RWY 11/29 or RWY 16/34) each Vienna-bound/departing flight uses, and provides daily and hourly statistics on runway usage and noise exposure.

## Architecture

```
React SPA (Vite)  →  PHP REST API (Apache)  →  MariaDB
                           ↓
                   OpenSky Network API
                   (dual-poller: /states/all + /states/own,
                    offset 30s, two cron entries alternating)
```

## Tech Stack

- **Backend:** PHP 8.2+ on Apache 2.4 (production, Plesk-managed on Hetzner shared hosting) or Caddy 2.x with PHP-FPM (local dev)
- **Database:** MariaDB 10.11+ (MySQL 8.0 compatible)
- **Autoloading:** Composer PSR-4 (`App\` → `src/`)
- **External APIs:** OpenSky Network (live + OAuth2), ADSB.lol (aircraft type lookup)

## Project Structure

```
flightnoisetracker/
├── public/                 # Apache document root
│   ├── index.php           # API router entry point
│   └── .htaccess           # URL rewrite rules
├── src/
│   ├── Controllers/        # Request handlers (Flight, Stats, Noise, Aircraft, Health)
│   ├── Models/             # Database models (Flight, FlightPosition, NoiseReading, Aircraft)
│   ├── Services/           # Business logic (RunwayClassifier, OpenSkyPoller)
│   ├── Config/             # Database connection
│   └── Router.php          # Lightweight REST router
├── cron/
│   └── poll.php            # OpenSky polling script (CLI flags: --endpoint, --source, --refresh-stats)
├── config/
│   └── app.php             # Application configuration
├── migrations/
│   ├── 001_schema.sql      # Database schema
│   ├── 002_flight_tracks.sql
│   ├── 003_noise_aircraft.sql
│   ├── 004_add_sel_db.sql
│   ├── 005_contact_messages.sql
│   └── 006_source_home_adsb.sql  # extends flight_positions.source ENUM with 'home-adsb'
├── composer.json
└── README.md
```

## Setup

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure Database

Edit `config/app.php` or set environment variables:

```bash
export FNT_DB_HOST=localhost
export FNT_DB_PORT=3306
export FNT_DB_NAME=fnt
export FNT_DB_USER=fnt_app
export FNT_DB_PASS=your_password
```

### 3. Run Migrations

```bash
mysql -u fnt_app -p fnt < migrations/001_schema.sql
```

### 4. Configure Apache

Point your Apache document root to `public/` and ensure `mod_rewrite` is enabled.

### 5. Set Up Polling (crontab, dual-poller)

The poller runs as two parallel cron entries staggered by 30 s, each targeting one OpenSky endpoint. Both write into the same `flights` / `flight_positions` tables; the source tag in `flight_positions.source` distinguishes rows (`opensky` for `states/all`, `home-adsb` for `states/own`).

```cron
# /etc/cron.d/fnt-poll
* * * * * www-data sleep 0;  /usr/bin/php /var/www/fnt/cron/poll.php --endpoint=states/all --source=opensky    >> /var/log/fnt/poll-osk.log  2>&1
* * * * * www-data sleep 15; /usr/bin/php /var/www/fnt/cron/poll.php --endpoint=states/own --source=home-adsb >> /var/log/fnt/poll-home.log 2>&1
* * * * * www-data sleep 30; /usr/bin/php /var/www/fnt/cron/poll.php --endpoint=states/all --source=opensky    >> /var/log/fnt/poll-osk.log  2>&1
* * * * * www-data sleep 45; /usr/bin/php /var/www/fnt/cron/poll.php --endpoint=states/own --source=home-adsb >> /var/log/fnt/poll-home.log 2>&1
```

Net effect: each endpoint fires on a 60 s wall-clock cadence, with the two endpoints offset by 30 s (so a poll happens every 30 s on the wire). A `flock(LOCK_EX | LOCK_NB)` per endpoint on `/tmp/fnt-poll-states_{all,own}.lock` prevents concurrent runs of the same endpoint; different endpoints never conflict.

Cost: `states/all` is 1 credit per call (~1,440 credits/day at this cadence). `states/own` is free. 1,440 credits/day fits well within the 4,000/day Standard-tier quota.

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/flights` | List flights (paginated) |
| GET | `/api/flights/{id}` | Flight detail with positions |
| GET | `/api/flights/{id}/track` | GeoJSON flight track |
| GET | `/api/flights/vie/daily` | VIE-related flights for a date |
| GET | `/api/stats/summary` | Aggregated counts (day/week/month/all) |
| GET | `/api/stats/runways` | Per-runway breakdown by date range |
| GET | `/api/stats/hourly?date=YYYY-MM-DD` | Hourly breakdown for a day |
| GET | `/api/stats/trend?days=30` | Daily trend over N days |
| POST | `/api/noise` | Submit noise reading |
| GET | `/api/noise` | List noise readings |
| GET | `/api/aircraft/{icao24}` | Aircraft metadata |
| GET | `/api/health` | Service health check |

### Query Parameters

- **Pagination:** `?page=1&per_page=50` (max 500)
- **Date range:** `?date_from=2026-07-01&date_to=2026-07-26`
- **VIE filter:** `?vie_only=true`
- **Runway filter:** `?runway=11/29`
- **Sorting:** `?sort=first_seen&order=desc`

## Runway Classification

Flights are classified using a heuristic based on aircraft heading, altitude, and proximity to LOWW (48.1103°N, 16.5697°E):

- **RWY 11/29** — headings 80°–140° or 260°–320° (ESE-WNW)
- **RWY 16/34** — headings 140°–200° or 320°–20° (NNW-SSE)

A flight is **VIE-related** if its closest approach to LOWW is within 50 km at below 6,000 m altitude.

## License

MIT
