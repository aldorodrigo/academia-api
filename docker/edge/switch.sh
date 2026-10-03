#!/bin/sh
# Pasa los puertos 80 y 443 del servidor compartido al proxy de entrada (/srv/edge).
# Se corre una sola vez desde la máquina de desarrollo:  sh docker/edge/switch.sh
# Editorial Data queda sin responder unos segundos mientras cambia de proxy.
set -eu

HOST=${HOST:-editorialdata-vps}
DIR=$(cd "$(dirname "$0")" && pwd)

test -s "$DIR/.env" || { echo "Falta docker/edge/.env (copiar .env.example)"; exit 1; }
scp -q "$DIR/compose.yaml" "$DIR/Caddyfile" "$DIR/.env" "$HOST:/srv/edge/"
scp -q "$DIR/editorial-data/compose.edge.yaml" "$DIR/editorial-data/Caddyfile.edge" "$HOST:/srv/editorial-data/"

ssh "$HOST" 'set -eu

# Los certificados actuales de editorialdata.org pasan al proxy de entrada:
# no se piden de nuevo y no hay espera.
docker volume create edge_caddy_data >/dev/null
docker volume create edge_caddy_config >/dev/null
docker run --rm -v editorial-data_caddy_data:/from:ro -v edge_caddy_data:/to alpine \
    sh -c "cp -a /from/. /to/ && chown -R 1000:1000 /to"

# Editorial Data se maneja con sudo: su .env.production es de root.
cd /srv/editorial-data
grep -q "^COMPOSE_FILE=" .env 2>/dev/null || echo "COMPOSE_FILE=compose.production.yaml:compose.edge.yaml" >> .env
sudo docker compose up -d --no-deps proxy

cd /srv/edge
docker compose up -d
docker compose ps
'

for url in https://editorialdata.org "https://$(ssh "$HOST" 'grep ^TUKU_DOMAIN= /srv/edge/.env | cut -d= -f2')/up"; do
    printf '%s ' "$url"; curl -s -o /dev/null -w '%{http_code}\n' --max-time 60 "$url" || true
done

# Para volver atrás:
#   ssh $HOST 'cd /srv/edge && docker compose down && cd /srv/editorial-data && sed -i "/^COMPOSE_FILE=/d" .env \
#     && sudo docker compose -f compose.production.yaml up -d --no-deps proxy'
