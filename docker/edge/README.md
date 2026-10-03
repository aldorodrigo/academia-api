# Proxy de entrada compartido

En el servidor de Editorial Data (1 vCPU y 4 GB) también corre Tuku. Los puertos 80 y 443 y los
certificados son de un Caddy aparte (`/srv/edge`), que reparte por dominio por la red `edge`:

| Dominio | Destino |
|---|---|
| `editorialdata.org`, `www.editorialdata.org` | `editorial-data-proxy:80` (Caddy de Editorial Data) |
| `TUKU_DOMAIN` (`.env`) | `tuku-proxy:80` (Caddy de Tuku: API y panel) |
| `tukuha.app` | `tuku-proxy:80` solo para la landing (`/`, `/build/*`, `/brand/*`); lo demás redirige a `api.tukuha.app` |
| `www.tukuha.app` | redirige a `tukuha.app` |

Cada proyecto sigue con su compose, su base, sus secretos y su despliegue. Su Caddy atiende HTTP en la red
`edge` y confía en los `X-Forwarded-*` del proxy de entrada (IP del cliente y HTTPS reales).

## En el servidor

```
/srv/edge             compose.yaml, Caddyfile y .env (ACME_EMAIL, TUKU_DOMAIN)
/srv/tuku             compose.production.yaml, compose.edge.yaml, .env (compose), .env.production (Laravel), secrets/
/srv/editorial-data   su repo + compose.edge.yaml y Caddyfile.edge (copias de editorial-data/), COMPOSE_FILE en .env
```

`COMPOSE_FILE` en el `.env` de cada proyecto hace que un `docker compose up -d` sin `-f` use los dos archivos.
**Si Editorial Data se despliega con `-f compose.production.yaml`, agregar `-f compose.edge.yaml`**: si no,
su Caddy vuelve a pedir los puertos 80 y 443 y no arranca.
Editorial Data se maneja con `sudo docker compose` (su `.env.production` es de root).

## Tuku

Las imágenes se construyen fuera del servidor (tiene 1 vCPU) y se suben:

```bash
docker build -f Dockerfile.production --target app -t academia-app:latest .
docker build -f Dockerfile.production --target proxy -t academia-proxy:latest .
docker save academia-app:latest academia-proxy:latest | gzip -1 | ssh editorialdata-vps 'gunzip | docker load'
ssh editorialdata-vps 'cd /srv/tuku && docker compose up -d && docker compose exec -T app php artisan migrate --force'
```

Tinker y otros comandos que escriben en `HOME` necesitan uno con escritura (la imagen es de solo lectura):
`docker compose exec -e HOME=/tmp -e XDG_CONFIG_HOME=/tmp app php artisan tinker`.

Respaldo diario (`docker/production/backup.sh`, copiado a `/srv/tuku/backup.sh`): base y archivos subidos, 14 días
en `/srv/tuku/backups`, por `/etc/cron.d/tuku-backup` a las 03:15 UTC. **Quedan en el mismo servidor**: falta
copiarlos afuera.

Cambio de dominio: `TUKU_DOMAIN` en `/srv/edge/.env` (y `docker compose up -d` ahí) y `APP_URL` en
`/srv/tuku/.env.production` (y `docker compose up -d` en `/srv/tuku`).

## Primera vez

Hecho el 2026-10-03. `sh docker/edge/switch.sh` copia los certificados de Editorial Data al proxy de entrada y cambia de proxy
(Editorial Data no responde unos segundos). Al final del script está cómo volver atrás.
