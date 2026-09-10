#!/bin/sh
set -e

# Clear configurations to avoid caching issues in development
echo "Clearing configurations..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

/var/www/docker/common/php-fpm/prepare-database.sh

exec "$@"
