# Installation & Validation Guide

## Prerequisites

This project requires:
- **PHP 8.2+** with extensions: pdo, pdo_mysql, curl, json
- **Composer** (for autoloading)
- **MariaDB 10.11+** or **MySQL 8.0+**
- **Apache 2.4** with mod_rewrite

## Installation Steps

### 1. Install PHP 8.2

```bash
# Ubuntu 24.04
apt-get update
apt-get install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-curl php8.2-xml php8.2-mbstring

# Verify
php -v
```

### 2. Install Composer

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

### 3. Install Dependencies

```bash
cd /path/to/flightnoisetracker
composer install
```

### 4. Validate Files

Run the validation script to check all PHP files:

```bash
chmod +x validate.sh
./validate.sh
```

Or manually validate each file:

```bash
# Validate all PHP files
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;

# Validate composer.json
composer validate
```

### 5. Database Setup

```bash
# Create database and user
mysql -u root -p <<EOF
CREATE DATABASE fnt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fnt_app'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON fnt.* TO 'fnt_app'@'localhost';
FLUSH PRIVILEGES;
EOF

# Run migrations
mysql -u fnt_app -p fnt < migrations/001_schema.sql
```

### 6. Configure Application

Edit `config/app.php` or set environment variables:

```bash
export FNT_DB_HOST=localhost
export FNT_DB_PORT=3306
export FNT_DB_NAME=fnt
export FNT_DB_USER=fnt_app
export FNT_DB_PASS=your_secure_password
```

### 7. Apache Configuration

Create virtual host:

```apache
<VirtualHost *:80>
    ServerName flightnoise.example.com
    DocumentRoot /var/www/flightnoisetracker/public
    
    <Directory /var/www/flightnoisetracker/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/fnt_error.log
    CustomLog ${APACHE_LOG_DIR}/fnt_access.log combined
</VirtualHost>
```

Enable mod_rewrite and restart:

```bash
a2enmod rewrite
systemctl restart apache2
```

### 8. Set Up Polling (dual-poller crontab)

The poller runs as two parallel cron jobs staggered by 30 s, each targeting one OpenSky endpoint. Both write into the same tables; the `flight_positions.source` ENUM (`opensky` or `home-adsb`) distinguishes rows.

```bash
# Create cron file
cat > /etc/cron.d/fnt-poll <<'EOF'
* * * * * www-data sleep 0;  /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/all --source=opensky    >> /var/log/fnt/poll-osk.log  2>&1
* * * * * www-data sleep 15; /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/own --source=home-adsb >> /var/log/fnt/poll-home.log 2>&1
* * * * * www-data sleep 30; /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/all --source=opensky    >> /var/log/fnt/poll-osk.log  2>&1
* * * * * www-data sleep 45; /usr/bin/php /var/www/flightnoisetracker/cron/poll.php --endpoint=states/own --source=home-adsb >> /var/log/fnt/poll-home.log 2>&1
EOF

chmod 644 /etc/cron.d/fnt-poll
mkdir -p /var/log/fnt
touch /var/log/fnt/poll-osk.log /var/log/fnt/poll-home.log
chown -R www-data:adm /var/log/fnt
```

Net effect: each endpoint fires on a 60 s wall-clock cadence, with the two endpoints offset by 30 s, so a poll happens every 30 s on the wire. A `flock(LOCK_EX | LOCK_NB)` per endpoint on `/tmp/fnt-poll-states_{all,own}.lock` keeps two concurrent runs of the same endpoint from colliding; different endpoints do not share a lock, so both can run in parallel.

Cost: `states/all` is 1 credit per call (~1,440 credits/day at this cadence). `states/own` is free. 1,440 credits/day sits well within the 4,000/day Standard-tier quota.

CLI flags accepted by `cron/poll.php`:

- `--endpoint=states/all|states/own` — which OpenSky REST endpoint to query
- `--source=opensky|home-adsb` — tag written to `flight_positions.source` for rows produced by this run
- `--refresh-stats` — also refresh the `daily_stats` materialized table for today (UTC date)

When `--endpoint` and `--source` are omitted, values fall back to `config['opensky']['endpoint']` and `config['opensky']['source']` (default `states/all` / `opensky`).

### 9. Create Log Directory

```bash
mkdir -p /var/www/flightnoisetracker/var/log
chown -R www-data:www-data /var/www/flightnoisetracker/var
```

### 10. Test Installation

```bash
# Test health endpoint
curl http://localhost/api/health

# Test flights endpoint
curl http://localhost/api/flights

# Check poll logs
tail -f /var/log/fnt/poll-osk.log
tail -f /var/log/fnt/poll-home.log
```

## Validation Checklist

Run these checks to verify installation:

- [ ] `php -v` shows PHP 8.2+
- [ ] `composer install` completes without errors
- [ ] `./validate.sh` reports 0 errors
- [ ] Database connection works (`php -r "require 'vendor/autoload.php'; \App\Config\Database::getConnection();"`)
- [ ] Apache serves `/api/health` with JSON response
- [ ] Polling cron is installed (`cat /etc/cron.d/fnt-poll`)
- [ ] Poll logs show successful runs (`/var/log/fnt/poll-osk.log`, `/var/log/fnt/poll-home.log`)

## Troubleshooting

### PHP Syntax Errors

If validation shows syntax errors, check PHP version:

```bash
php -v  # Must be 8.2+
```

### Database Connection Failed

Verify credentials in `config/app.php` and ensure the database exists:

```bash
mysql -u fnt_app -p -e "SHOW DATABASES;"
```

### Polling Not Running

Check cron entries and per-endpoint logs:

```bash
cat /etc/cron.d/fnt-poll
grep CRON /var/log/syslog | grep fnt-poll | tail -20
tail -50 /var/log/fnt/poll-osk.log
tail -50 /var/log/fnt/poll-home.log

# If a poll slot is being skipped (per-endpoint file lock busy), confirm via:
ls -l /tmp/fnt-poll-states_all.lock /tmp/fnt-poll-states_own.lock
```

### Permission Issues

Ensure web server can write to log directory:

```bash
chown -R www-data:www-data /var/www/flightnoisetracker/var
chmod -R 755 /var/www/flightnoisetracker/var
```
