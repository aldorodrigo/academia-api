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
./vendor/bin/sail artisan horizon        # colas con panel (el servicio `queue` ya las procesa)
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
- Mailpit: http://localhost:8025 — con `WHATSAPP_DEV_DRIVER=mail` también llegan ahí los códigos "de WhatsApp"
  (correo a `{número}@whatsapp.test`, ej. `595981123456@whatsapp.test`).
- Colas: el servicio `queue` del `compose.yaml` (contenedor `academia-api-queue-1`) corre `queue:work` siempre;
  después de cambiar jobs o mails, `sail artisan queue:restart`.
- Turnstile en local: claves de prueba de Cloudflare `TURNSTILE_SITE_KEY=1x00000000000000000000AA` y
  `TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA` (siempre aprueban); sin claves no se pide captcha.
- Con `APP_PORT`/`VITE_PORT` en `.env` (ej. 8080/5174) para convivir con otros proyectos Sail; imagen propia `academia-api/app`.

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
- **Cuenta con celular o correo** (Sprint 5d): `users.phone` (E.164, único) o `users.email`; se entra con cualquiera
  (`User::findByLogin`, `App\Filament\Pages\Auth\Login`). Teléfonos con `App\Support\Phone` (libphonenumber, PY por
  defecto; `mobile()`, `normalize()`, `display()`). `isVerified()` = celular o correo verificado (`hasVerifiedEmail()`
  lo devuelve para Filament).
- **Códigos** (crear cuenta y "Olvidé mi contraseña"): `SendVerificationCode` / `VerifyCode` / `ResetPasswordWithCode`,
  por WhatsApp (`App\Support\WhatsApp\WhatsAppSender`: Cloud API de Meta con `WHATSAPP_TOKEN`, si no al log; job
  `SendWhatsAppCode`) o por correo. **Todo envío pasa por `App\Support\Verification\CodeGuard`** (Turnstile, campo
  trampa, países permitidos, límites por destino/IP/cuenta, tope diario y corte automático; pausa en
  `platform_settings`). No mandes códigos por fuera de esas acciones.
- **Lo que sale por WhatsApp sale también por correo** (ver `docs/WHATSAPP.md`): el código lleva una copia con su
  propio código (verifica el correo), las invitaciones con celular y correo van por los dos lados y los avisos push
  también van por correo. Correo para copias: `User::mailableEmail()` (el verificado o, sin celular, el de la cuenta).
  Cada dato se verifica por separado: un celular o correo sin verificar no ocupa el dato (`User::owning()`,
  `User::releaseContacts()`, regla `ContactAvailable`).
- **Avisos:** extender `App\Notifications\PushNotification` (push + correo con la marca a partir de `toPush()`;
  `mailActions()` y `mailPose()` para los botones y la mascota). No crear notificaciones solo push.
- **Correos con la marca Tuku:** componentes en `resources/views/vendor/mail` (tema `tuku.css`, `mascot`, `buttons`) y
  `vendor/notifications/email.blade.php` en español. Los links de los correos que cambian algo abren una página
  (`site.aviso`) con un botón que hace el `POST`: los antivirus de correo abren los links solos.
- **Alta autoservicio** (Sprint 5d): `/admin/register` (`RegisterAccount`, celular o correo) → código
  (`VerifyAccount`, `->emailVerification()`) → `/admin/new` "Tu club" (`RegisterOrganization`,
  `->tenantRegistration()`). Un usuario sin ninguna membresía puede entrar al panel solo para eso. "Olvidé mi
  contraseña" del panel: `RequestPasswordReset` (código, no link). Invitar: `ContactField` (celular o correo).
- **Guía "Primeros pasos"** (`App\Filament\Pages\Onboarding`, `/admin/{slug}/primeros-pasos`): checklist de
  `App\Support\Onboarding\Checklist` (el mismo que `GET onboarding` de la app) con un panel lateral por paso;
  el `Dashboard` propio redirige ahí mientras esté incompleta y sin cerrar. Las acciones de cada paso
  (`CreatePrograms`, `SaveGroups`, `Seasons\CreateSeason`, `ManageInstructors`) las usan también los
  endpoints `setup/*` de la API: no dupliques lógica en el panel ni en los controladores.
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
- Bajas (`docs/PLAN_BAJAS.md`): `Enrollments\WithdrawEnrollment` (fecha, motivo y aviso opcional a la familia
  `StudentWithdrawn` con `defaultNotice()`; la deuda queda), `ReactivateEnrollment` (cuotas desde el período en curso),
  `ReportDropout` (aviso del técnico "dejó de venir" o del tutor "deja el club", `dropout_source`; `DropoutReported`);
  acciones del panel en `Filament\Support\WithdrawalActions`; endpoints de la app en `Api\V1\WithdrawalController`.
  Dados de baja en informes: `Support\Enrollments\Withdrawals`. Condonar: `Billing\WaiveCharges` (permiso
  `Waive:Charge`, admin/tesorero/presidente por defecto; anulación con `waived_amount`, estado `condonado`, fila en
  `ChargeCondonation`) y `Billing\UnwaiveCharge` (deshacer); acciones `Filament\Support\WaiveChargeAction`.
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
