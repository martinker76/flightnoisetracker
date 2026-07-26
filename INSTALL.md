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

### 8. Set Up Polling

Install systemd service and timer:

```bash
# Create service file
cat > /etc/systemd/system/fnt-poll.service <<'EOF'
[Unit]
Description=FlightNoiseTracker OpenSky Poller

[Service]
Type=oneshot
ExecStart=/usr/bin/php /var/www/flightnoisetracker/cron/poll.php --refresh-stats
WorkingDirectory=/var/www/flightnoisetracker
User=www-data
StandardOutput=append:/var/log/fnt-poll.log
StandardError=append:/var/log/fnt-poll-error.log
EOF

# Create timer file
cat > /etc/systemd/system/fnt-poll.timer <<'EOF'
[Unit]
Description=FlightNoiseTracker Polling Timer

[Timer]
OnBootSec=30
OnUnitActiveSec=60
RandomizedDelaySec=10

[Install]
WantedBy=timers.target
EOF

# Enable and start
systemctl daemon-reload
systemctl enable --now fnt-poll.timer

# Check status
systemctl status fnt-poll.timer
systemctl list-timers fnt-poll.timer
```

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
tail -f /var/log/fnt-poll.log
```

## Validation Checklist

Run these checks to verify installation:

- [ ] `php -v` shows PHP 8.2+
- [ ] `composer install` completes without errors
- [ ] `./validate.sh` reports 0 errors
- [ ] Database connection works (`php -r "require 'vendor/autoload.php'; \App\Config\Database::getConnection();"`)
- [ ] Apache serves `/api/health` with JSON response
- [ ] Polling timer is active (`systemctl status fnt-poll.timer`)
- [ ] Poll logs show successful runs

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

Check timer status and logs:

```bash
systemctl status fnt-poll.timer
journalctl -u fnt-poll.service -n 50
```

### Permission Issues

Ensure web server can write to log directory:

```bash
chown -R www-data:www-data /var/www/flightnoisetracker/var
chmod -R 755 /var/www/flightnoisetracker/var
```
