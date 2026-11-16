#!/usr/bin/env sh
set -eu

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chmod -R 775 storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY is not set. Configure APP_KEY in Railway Variables." >&2
    exit 1
fi

echo "Caching Laravel configuration, routes, and views..."
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Running database migrations against PostgreSQL..."
php artisan migrate --force

echo "Starting background queue worker..."
(
    while true; do
        php artisan queue:work database --sleep=3 --tries=3 --backoff=15,60,180 --timeout=30 --max-time=3600 || true
        sleep 5
    done
) &

echo "Starting background Laravel scheduler..."
(
    while true; do
        php artisan schedule:work || true
        sleep 5
    done
) &

echo "Starting FrankenPHP web server on port ${PORT:-8080}..."
exec frankenphp run --config /app/Caddyfile
