# Academia — API y panel (nombre en clave)

Backend del SaaS para academias, clubes y escuelas de formación. Piloto: Club Jakare.

- **Lógica de negocio:** [`business-logic.md`](business-logic.md)
- **Guía para desarrollo con Claude Code:** [`CLAUDE.md`](CLAUDE.md)

## Stack

PHP 8.5 · Laravel 13 · Filament 5 · Sanctum · Horizon · Pest 5 ·
**MariaDB 11.8** y **Redis 8** en todos los entornos.

## Puesta en marcha (desarrollo)

```bash
cp .env.example .env
# Instala dependencias sin PHP local (la imagen php85-composer no existe; 8.4 alcanza para instalar)
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
./vendor/bin/sail artisan shield:generate --all --panel=admin
```

Si el puerto 80 está ocupado (ej. Apache en WSL), usá `APP_PORT=8080` y `APP_URL=http://localhost:8080` en `.env`.
Para correrlo junto a otro proyecto Sail, cambiá en `.env` los puertos que choquen (`APP_PORT`, `VITE_PORT`,
`FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT`); la imagen es
`academia-api/app` para no pisar la `sail-8.5/app` de otros proyectos.
Después de `composer require` o de cambiar código de jobs/mails, reiniciá los workers: `sail artisan queue:restart`.

- Panel: http://localhost/admin (`admin@academia.test` / `password`, solo desarrollo)
- Horizon: http://localhost/horizon
- Mailpit: http://localhost:8025

## Tests y formato

```bash
./vendor/bin/sail composer test   # Pest (contra MariaDB y Redis reales)
./vendor/bin/sail composer lint   # Pint
```

## Producción

`Dockerfile.production` + `compose.production.yaml`: app (PHP-FPM), Caddy (HTTPS),
MariaDB 11.8, Redis 8 (AOF), Horizon y scheduler.

```bash
cp .env.production.example .env.production   # completar valores
mkdir -p secrets
openssl rand -base64 32 > secrets/db_password
openssl rand -base64 32 > secrets/db_root_password
openssl rand -base64 32 > secrets/redis_password
docker compose -f compose.production.yaml up -d --build
docker compose -f compose.production.yaml exec app php artisan migrate --force
# la imagen es de solo lectura: las policies ya están en el repo, solo se cargan los permisos
docker compose -f compose.production.yaml exec app php artisan shield:generate --all --panel=admin --option=permissions
```

`APP_DOMAIN` y `ACME_EMAIL` son variables de compose: van en `.env` (no en `.env.production`). Los límites de
memoria, el buffer pool de MariaDB y los procesos de Horizon se ajustan con `DB_BUFFER_POOL_SIZE`, `*_MEM_LIMIT`
y `HORIZON_MAX_PROCESSES`. Para compartir el servidor con otros sitios, ver [`docker/edge/README.md`](docker/edge/README.md).
