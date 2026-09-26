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
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

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
docker compose -f compose.production.yaml exec app php artisan shield:generate --all --panel=admin
```
