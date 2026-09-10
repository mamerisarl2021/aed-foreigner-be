#!/bin/sh
set -e

echo "Starting queue worker (QUEUE_CONNECTION=${QUEUE_CONNECTION:-unset})..."
exec php artisan queue:work --sleep=1 --tries=3 --timeout=130
