#!/bin/bash
# FlightNoiseTracker — Validation Script
# Run this after PHP 8.2+ is installed to validate all files

set -e

echo "=== FlightNoiseTracker File Validation ==="
echo ""

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "❌ PHP not found. Install PHP 8.2+ first:"
    echo "   apt-get update && apt-get install -y php8.2-cli php8.2-mysql php8.2-curl php8.2-xml"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo "✓ PHP version: $PHP_VERSION"
echo ""

# Validate composer.json
echo "=== Validating composer.json ==="
if command -v composer &> /dev/null; then
    composer validate --no-check-all --no-check-publish
else
    echo "⚠ Composer not installed, checking JSON syntax only"
    php -r "json_decode(file_get_contents('composer.json')); echo json_last_error() === JSON_ERROR_NONE ? '✓ composer.json is valid JSON' : '✗ composer.json has JSON errors';"
fi
echo ""

# Validate all PHP files
echo "=== Validating PHP Syntax ==="
ERRORS=0
TOTAL=0

while IFS= read -r -d '' file; do
    TOTAL=$((TOTAL + 1))
    if php -l "$file" > /dev/null 2>&1; then
        echo "✓ $file"
    else
        echo "✗ $file"
        php -l "$file"
        ERRORS=$((ERRORS + 1))
    fi
done < <(find . -name "*.php" -not -path "./vendor/*" -print0)

echo ""
echo "=== Summary ==="
echo "Files checked: $TOTAL"
echo "Errors: $ERRORS"

if [ $ERRORS -eq 0 ]; then
    echo "✓ All PHP files have valid syntax"
    exit 0
else
    echo "✗ Some files have syntax errors"
    exit 1
fi
