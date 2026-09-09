#!/bin/sh
set -eu
umask 027
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s storage/app/private/application.key ]; then
        php -r 'file_put_contents("storage/app/private/application.key", "base64:".base64_encode(random_bytes(32)));'
        chmod 600 storage/app/private/application.key
    fi
    APP_KEY="$(cat storage/app/private/application.key)"
    export APP_KEY
fi
if [ -z "${DB_PASSWORD:-}" ] && [ -n "${DB_PASSWORD_FILE:-}" ]; then
    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    export DB_PASSWORD
fi
php artisan migrate --force --no-interaction
php artisan fahrtwerk:bootstrap --no-interaction
php artisan view:cache
exec "$@"
