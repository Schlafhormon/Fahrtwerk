#!/bin/sh
# Auf dem Docker-Host vom Projektverzeichnis aus ausführen.
set -eu
umask 077
destination="${1:-backups/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$destination"
docker compose exec -T app php artisan down --retry=60
trap 'docker compose exec -T app php artisan up >/dev/null 2>&1 || true' EXIT HUP INT TERM
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$destination/database.dump"
docker compose exec -T app tar -czf - -C /var/www/html storage > "$destination/storage.tar.gz"
docker compose exec -T db tar -czf - -C /run fahrtwerk > "$destination/secrets.tar.gz"
if [ -f .env ]; then cp .env "$destination/environment.env"; fi
docker compose exec -T app php artisan up
trap - EXIT HUP INT TERM
printf 'Backup erstellt: %s\n' "$destination"
