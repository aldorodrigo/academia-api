#!/bin/sh
set -eu

if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$DB_PASSWORD_FILE" ]; then
        echo "DB_PASSWORD_FILE is not readable" >&2
        exit 1
    fi

    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    export DB_PASSWORD
fi

if [ -n "${REDIS_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$REDIS_PASSWORD_FILE" ]; then
        echo "REDIS_PASSWORD_FILE is not readable" >&2
        exit 1
    fi

    REDIS_PASSWORD="$(cat "$REDIS_PASSWORD_FILE")"
    export REDIS_PASSWORD
fi

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

php artisan package:discover --no-ansi > /dev/null
php artisan config:cache --no-ansi > /dev/null
php artisan view:cache --no-ansi > /dev/null

exec "$@"
