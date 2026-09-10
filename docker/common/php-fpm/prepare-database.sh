#!/bin/sh
set -e

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

echo "Running migrations..."
php artisan migrate --force

if php "$SCRIPT_DIR/roles-exist.php"; then
    echo "Spatie roles already present; skipping db:seed."
else
    echo "Empty roles catalogue; seeding..."
    php artisan db:seed --force
fi
