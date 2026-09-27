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
./vendor/bin/sail artisan queue:restart  # después de composer require o de cambiar jobs/mails
./vendor/bin/sail artisan roles:expire   # mandatos (agendado a diario)
./vendor/bin/sail artisan organizations:sync-roles   # roles base en organizaciones existentes
./vendor/bin/sail artisan users:super-admin {email} [--revoke]
```

- `APP_FRONTEND_URL`: URL de la app web Flutter; arma los links de invitación (`/invitacion/{token}`).

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

### Paneles
- **`/admin/{slug}`** (`AdminPanelProvider`): panel de cada organización (tenancy). Recursos en
  `app/Filament/Resources`, páginas en `app/Filament/Pages`.
- **`/plataforma`** (`PlatformPanelProvider`): solo super admins, sin tenancy. Organizaciones
  (alta con primer admin, suspender/reactivar, entrar al panel) y usuarios (super admins).
  Recursos en `app/Filament/Platform/Resources`. Sin organización activa los scopes no filtran.
- Vistas propias en paneles: usar componentes `x-filament::*` o estilos inline (las clases de
  Tailwind propias no están en el CSS de Filament).

### Roles e invitaciones
- Roles base en `App\Enums\OrganizationRole`; se crean al crear una organización.
- **Nunca** asignes roles con `assignRole()` directo: usá `App\Support\Roles\RoleAssigner`
  (`role_assignments` es la fuente de verdad y sincroniza spatie).
- Invitaciones: `CreateInvitation` / `AcceptInvitation` (`app/Actions/Invitations`). El token solo
  existe en claro al crear o reenviar; se guarda hasheado.
- Contrato de la API para la app: `docs/API_V1.md`. Plan del proyecto: `docs/PLAN.md`.
- Invitación de un tutor cargado: `CreateInvitation::forGuardian()`; al aceptarla, `guardians.user_id` queda vinculado.

### Académico
- Programa → Grupo → Horarios; `Enrollment` = alumno + grupo + temporada.
- Temporadas vigentes por fechas (varias a la vez, por disciplina): `Season::active()`, `open()` (vigentes o próximas),
  `forProgram()` (sin disciplinas = todas), `Season::defaultFor($program)`. No hay "temporada actual".
- "Mis hijos": `Student::inChargeOf($user)` (tutor vinculado o alumno adulto con `user_id`).
- Ficha médica: siempre chequear `can('viewMedical', $student)` (`StudentPolicy`); los campos clínicos van cifrados.
- Alta de jugador: `App\Actions\Students\RegisterStudent` (datos + inscripción + tutores + invitación). La usan
  el formulario "Nuevo jugador" y la importación (`ImportStudentRow` adapta la fila; el importer corre en cola y
  recibe `organization_id` en `options`).
- Familia: automática e invisible (`Family::syncFor($student)`), sin menú ni campo en el panel.
- Categoría sugerida por fecha de nacimiento: `Group::suggestFor($birthDate, $season, $program)`.
- Campos de inscripción (alta, acción "Inscribir", pestaña Inscripciones): `App\Filament\Support\EnrollmentForm`.
- Inscripciones de temporadas terminadas: finalizadas solas (`Enrollment::isFinished()`); solo generan cuota
  las de `Enrollment::billable()`. Pase de temporada: `App\Actions\Enrollments\TransferSeason` (cuotas en cola con
  `QueueEnrollmentCharges`, avisa al terminar).
- Nueva temporada: asistente `CreateSeason` (`Seasons\Support\SeasonPlanSteps` y `SeasonPlan`: valores por defecto,
  copia, resumen, cuotas de ejemplo, tarifas). "Configurar cobro" y "Cambiar monto": `SeasonActions`.
- Jugador existente: `Student::findExisting()` (documento, o nombre + fecha de nacimiento).
- Etiquetas del panel según el vocabulario de la organización: `App\Filament\Support\Terms`.

### Finanzas (cargos)
- `Charge` es inmutable (no se edita ni se borra): se anula con `VoidCharge` (motivo). Estado calculado: `Charge::status()`.
- Cuotas según el plan de la temporada (`Season`: `fee_frequency`, `daily_basis`, `daily_grouping`, `due_days`,
  `issue_upfront`, `mid_period`): períodos con `SeasonPeriods`; emisión por inscripción con `IssueSeasonCharges`
  (al crear la inscripción, idempotente por `unique_key` `enr:…:con:…:per:Y-m-d`); `GenerateSeasonCharges` (lock) y
  comando diario `charges:generate`. Baja o suspensión: `VoidFutureCharges`. `Charge::isUpcoming()` = próxima.
- Descuentos y becas: `DiscountCalculator`, en el orden de `organizations.billing.discount_order`.
- Tarifa aplicable: `Tariff::applicable()`. Configuración de cobros: `Organization::billing()`.
- Becas: `ScholarshipDecision` (permiso `Approve:Scholarship`).
- Cobros: `RegisterPayment` (imputación, pronto pago con `EarlyPaymentDiscount`, recibo correlativo, `LedgerEntry`),
  `ApplyCredit` (saldo a favor, se llama al generar cargos), `VoidPayment`. `Payment`, `PaymentAllocation` y
  `LedgerEntry` son inmutables. Recibo: `ReceiptController` (ruta firmada `recibos/{payment}`).
- Tesorería: `App\Actions\Treasury\ExpenseLedger` (registrar, pagar, anular, recurrentes) y `TransferFunds`.
- Informes: `App\Reports\*` (`data()` para API/panel, `pdf()`, `xlsx()` con openspout); descarga por ruta firmada
  `informes/{report}.{format}` (`ReportDownloadController`). Permiso `View:Reports`; la app lo recibe en
  `membership.permissions` como `view_reports`. Los jugadores sin familia cuentan como su propio grupo.

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
