#!/bin/sh
# Respaldo diario de Tuku en el servidor: base (mariadb-dump) y archivos subidos (volumen de storage).
# Se guardan 14 días en backups/. Cron del usuario deploy:
#   15 3 * * * /srv/tuku/backup.sh >> /srv/tuku/backups/backup.log 2>&1
set -eu

cd "$(dirname "$0")"
mkdir -p backups
stamp=$(date +%F)

docker compose exec -T database sh -c \
    'mariadb-dump -uroot -p"$(cat /run/secrets/db_root_password)" --single-transaction --routines --triggers --databases "$MARIADB_DATABASE"' \
    | gzip > "backups/db-$stamp.sql.gz.tmp"
mv "backups/db-$stamp.sql.gz.tmp" "backups/db-$stamp.sql.gz"

docker run --rm -v "${COMPOSE_PROJECT_NAME:-tuku}_app_storage:/storage:ro" alpine \
    tar -C /storage -cz app > "backups/storage-$stamp.tar.gz.tmp"
mv "backups/storage-$stamp.tar.gz.tmp" "backups/storage-$stamp.tar.gz"

find backups -name '*.gz' -mtime +14 -delete
echo "$(date -Is) ok $(du -h "backups/db-$stamp.sql.gz" | cut -f1)"
