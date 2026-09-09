#!/bin/sh
set -eu
mkdir -p /run/fahrtwerk
if [ ! -s /run/fahrtwerk/db_password ]; then
    umask 077
    if [ -n "${FAHRTWERK_DB_PASSWORD:-}" ]; then
        printf '%s' "$FAHRTWERK_DB_PASSWORD" > /run/fahrtwerk/db_password
    else
        head -c 48 /dev/urandom | base64 | tr -d '\n' > /run/fahrtwerk/db_password
    fi
    # Nur die beiden Container bekommen dieses Volume; App ausschließlich read-only.
    chmod 444 /run/fahrtwerk/db_password
fi
unset FAHRTWERK_DB_PASSWORD
exec /usr/local/bin/docker-entrypoint.sh postgres
