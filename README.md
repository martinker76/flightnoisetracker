# FlightNoiseTracker

A web application that tracks aircraft movements over **Mannersdorf am Leithagebirge (2452)**, Lower Austria — approximately 20 km SSE of Vienna International Airport (LOWW/VIE).

The app determines whether the volume of flights routed across the city is increasing over time, classifies which runway (RWY 11/29 or RWY 16/34) each Vienna-bound/departing flight uses, and provides daily and hourly statistics on runway usage and noise exposure.

## Architecture

```
React SPA (Vite)  →  PHP REST API (Apache)  →  MariaDB
                           ↓
                   OpenSky Network API
                   (polled every 60s via cron)
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
│   └── poll.php            # OpenSky polling script (runs every 60s)
├── config/
│   └── app.php             # Application configuration
├── migrations/
│   └── 001_schema.sql      # Database schema
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

### 5. Set Up Polling (systemd)

Create the service and timer:

```ini
# /etc/systemd/system/fnt-poll.service
[Unit]
Description=FlightNoiseTracker OpenSky Poller

[Service]
Type=oneshot
ExecStart=/usr/bin/php /var/www/fnt/cron/poll.php --refresh-stats
WorkingDirectory=/var/www/fnt
User=www-data

# /etc/systemd/system/fnt-poll.timer
[Unit]
Description=FlightNoiseTracker Polling Timer

[Timer]
OnBootSec=30
OnUnitActiveSec=60
RandomizedDelaySec=10

[Install]
WantedBy=timers.target
```

Enable and start:

```bash
systemctl enable --now fnt-poll.timer
```

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
