#!/bin/sh
# Explizit destruktiver Restore in einen vorbereiteten Compose-Stack.
set -eu
if [ "$#" -ne 2 ] || [ "$2" != "--replace-current-data" ]; then
    echo 'Verwendung: scripts/restore.sh BACKUPVERZEICHNIS --replace-current-data' >&2
    exit 1
fi
source_dir="$1"
test -s "$source_dir/database.dump"
test -s "$source_dir/storage.tar.gz"
docker compose stop web app scheduler
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner --single-transaction' < "$source_dir/database.dump"
docker compose run --rm --no-deps --user root --entrypoint sh app -c 'tar -xzf - -C /var/www/html; chown -R www-data:www-data /var/www/html/storage; rm -f /var/www/html/storage/framework/down' < "$source_dir/storage.tar.gz"
docker compose up -d app web scheduler
echo 'Restore abgeschlossen. Healthchecks und Login prüfen.'
