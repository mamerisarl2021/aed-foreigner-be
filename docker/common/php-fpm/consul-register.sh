#!/bin/sh
set -e

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

echo "Waiting for Nginx ${HEALTH_HOST:-web}:${HEALTH_PORT:-80}${CONSUL_HEALTH_PATH:-/api/v1/health} ..."
i=0
while [ "$i" -lt 30 ]; do
    if php "$SCRIPT_DIR/wait-for-web-health.php"; then
        break
    fi
    i=$((i + 1))
    sleep 2
done

if [ "$i" -ge 30 ]; then
    echo "Nginx health endpoint did not become UP." >&2
    exit 1
fi

echo "Registering in Consul (Address=${CONSUL_SERVICE_IP:-<hostname>}:${CONSUL_SERVICE_PORT:-8000})..."
php artisan consul:deregister || true

i=0
while [ "$i" -lt 15 ]; do
    if php artisan consul:register; then
        exit 0
    fi
    i=$((i + 1))
    sleep 4
done

echo "Consul registration failed after retries." >&2
exit 1
