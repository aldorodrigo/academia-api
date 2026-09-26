# CLAUDE.md

Guía para Claude Code al trabajar en este repositorio.

## Proyecto

SaaS para **academias, clubes y escuelas de formación** (deporte, danza, música, idiomas…)
y comisiones de padres. Piloto: **Club Jakare** (fútbol infantil, Paraguay).
Nombre del producto: pendiente (nombre en clave del repo: `academia`).

Este repo es el **backend**: API para la app Flutter (`/api/v1`) + panel de administración
Filament (`/admin`) + colas (Horizon) + tareas programadas. La app móvil vive en otro repo.

El documento maestro de negocio es **`business-logic.md`**. Leelo antes de tocar reglas
de dinero, roles o tenancy.

## Idioma

**Solo español.** No hay sistema de traducciones: los textos (labels, mensajes, notificaciones)
se escriben directamente en español en el código. No uses `__()` ni archivos de traducción
propios. `lang/es` existe solo para los mensajes del framework (validación, login, contraseñas).
Los nombres de clases, tablas y columnas van en inglés.

## Stack

PHP 8.5 · Laravel 13 · Filament 5 · Livewire 4 · Tailwind 4 · Sanctum 4 · Fortify ·
Filament Shield + spatie/laravel-permission (teams = organización) · Horizon ·
spatie/activitylog · spatie/medialibrary · dompdf · kreait/laravel-firebase · Pest 5 · Pint.

**Base de datos: MariaDB 11.8 en todos los entornos** (dev, CI y producción), collation
`utf8mb4_uca1400_ai_ci`. **Redis 8** para colas (Horizon) y caché/locks. Sesiones en MariaDB.
No introduzcas SQL específico de MySQL ni uses SQLite en tests.

## Entorno de desarrollo (Sail)

```bash
./vendor/bin/sail up -d                  # app, mariadb, redis, mailpit
./vendor/bin/sail artisan migrate --seed # super admin + organización Jakare
./vendor/bin/sail artisan horizon        # procesar colas
./vendor/bin/sail composer test          # Pest
./vendor/bin/sail composer lint          # Pint
```

- Panel: http://localhost/admin — `admin@academia.test` / `password` (super admin, solo dev).
- Horizon: http://localhost/horizon (solo super admin).
- Mailpit: http://localhost:8025

## Arquitectura

### Multi-organización (tenancy) — regla de oro
- Una sola base de datos; toda tabla de dominio tiene `organization_id`.
- Todo modelo de dominio usa el trait **`App\Models\Concerns\BelongsToOrganization`**
  (global scope + asignación automática al crear). Un **arch test** lo exige
  (`tests/Arch/ArchTest.php`); solo `Organization`, `Membership` y `User` están exentos.
- La organización activa vive en **`App\Support\Tenancy\CurrentOrganization`** (scoped por request):
  - Panel: middleware `SetCurrentOrganizationFromPanel` (tenant de Filament, URL `/admin/{slug}`).
  - API: middleware `organization` (`ResolveOrganizationFromHeader`) con header `X-Organization: {slug}`.
  - Jobs y comandos: `app(CurrentOrganization::class)->run($organization, fn () => ...)`.
- Sin organización activa el scope **no filtra** (consola, super admin). Todo job que toque
  datos de una organización debe usar `run()`.
- Formularios de Filament: **nunca** exponer `organization_id` como campo editable.
- Roles/permisos: spatie/permission con `teams` = `organization_id` (se setea en `CurrentOrganization::set`).
- `users.is_super_admin`: acceso total a la plataforma (`Gate::before`).

### Dinero
- Montos en **enteros** (guaraníes, sin decimales). Nunca `float` para dinero.
- Value object `App\Support\Money` (`Money::pyg(150000)->format()` → `₲ 150.000`).
- Libro mayor inmutable: los movimientos no se editan ni borran; se anulan con contra-movimiento.

### Fechas
- Se guardan en UTC (`config/app.php`); se muestran en la zona horaria de la organización
  (`organizations.timezone`, por defecto `America/Asuncion`).

## Convenciones

- Tests con **Pest**; todo cambio de reglas de dinero o tenancy lleva test.
- Tests de aislamiento: un usuario nunca ve datos de otra organización.
- Modelos con atributos de Laravel 13 (`#[Fillable]`, `#[Hidden]`).
- Enums en `App\Enums`.
- Correr `composer lint` antes de commitear.
