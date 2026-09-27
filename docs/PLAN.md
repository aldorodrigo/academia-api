# Plan del proyecto — SaaS para academias, clubes y escuelas

> Plan único. Reemplaza a `PLAN_JAKARE.md` (funcionalidades) y `PLAN_IMPLEMENTACION.md` (stack y hoja de ruta).
> Las reglas ya implementadas viven en `business-logic.md`, que es el documento maestro: si difiere de este plan, manda `business-logic.md`.

- **Nombre:** pendiente. Finalistas: Crecemy, Cantemy, Nidemy, Cluppy, Crecy, Retoño, Acompaño. Mientras tanto se usa el nombre en clave `academia`.
- **Piloto:** Club Jakare (fútbol infantil, Paraguay). Moneda ₲, zona horaria `America/Asuncion`.
- **Idioma:** **solo español**, sin sistema de traducciones.

---

## 1. Alcance y visión

SaaS multi-organización para **academias, clubes, escuelas de formación y comisiones de padres (ACE)**: deporte, danza, música, idiomas, etc.
Cada organización es un inquilino, con datos, usuarios, finanzas y comunicaciones aislados de las demás.

- **Núcleo:** alumnos, familias, programas, grupos, horarios, asistencia, inscripciones, tarifas, cuotas, becas, mora, cobros, gastos, cuentas, avisos push, eventos e informes.
- **Módulos opcionales** (feature flags por organización): comisión, actas y resoluciones (`board`), rifas (`fundraising`), indumentaria (`apparel`), torneos (`tournaments`), evaluaciones (`evaluations`) y SIFEN (`electronic_invoicing`).
- **Vocabulario configurable:** son etiquetas por organización, no traducciones.

  | Clave | Por defecto (Jakare) | Ej. academia de danza |
  |---|---|---|
  | `program` | Disciplina | Estilo |
  | `group` | Categoría | Nivel |
  | `student` | Jugador | Alumna/o |
  | `instructor` | Técnico | Profesor/a |
  | `guardian` | Tutor | Tutor |

- **Fuera de alcance:** la gestión académica de colegios formales.
- **Reparto app / panel:**
  - La **app Flutter** (Android, iOS y web) es para padres, técnicos y comisión "en movimiento": avisos, cuenta, pagos, asistencia y aprobaciones.
  - El **panel Filament** es para el trabajo de escritorio: carga masiva, actas, balances y configuración.
  - Flutter web queda disponible, pero no reemplaza al panel.

## 2. Actores y roles

| Rol | Qué hace | Alcance |
|---|---|---|
| Super admin de la plataforma | Da de alta organizaciones y planes; soporte | Todas |
| Admin de la organización | Configura la organización, usuarios y grupos | Su organización |
| Presidente / vice | Aprueba resoluciones, gastos grandes y becas; ve todo | Su organización |
| Secretario/a (y pro-) | Reuniones, actas, resoluciones y comunicados | Su organización |
| Tesorero/a (y pro-) | Cuotas, cobros, gastos, cuentas y balances | Su organización |
| Vocales / síndico | Leen actas e informes; auditoría | Su organización |
| Instructor (técnico, coordinador) | Asistencia, convocatorias y avisos a su grupo | Sus grupos |
| Padre / tutor | Ve a sus hijos, paga, recibe avisos y confirma asistencia | Sus hijos |
| Alumno adulto | Es su propio responsable | Él mismo |

- Una persona puede tener **varios roles** y pertenecer a **varias organizaciones**. Por ejemplo, un papá que es tesorero y además tutor: la app le muestra los dos perfiles.
- **Cada cargo de comisión es un rol** con permisos editables (Shield). La asignación tiene **mandato** (desde / hasta) y, al vencer, el rol se desactiva solo.

## 3. Funcionalidades por módulo

### 3.1 Plataforma SaaS
- Alta de organización: nombre, tipo, logo, colores, moneda, país, zona horaria, vocabulario y módulos.
- Invitación de usuarios por email, link o código QR. No hay registro abierto.
- Onboarding con un asistente inicial: crear grupos, cuentas y cuota base.
- Planes de suscripción por cantidad de alumnos o por módulos *(Fase 4)*.

### 3.2 Organización e institucional
- Datos de la organización, sedes y canchas (propias o alquiladas).
- Temporadas (ej. 2026), con una sola temporada `actual`. Todo se agrupa por temporada.
- Documentos: estatuto, reglamento interno y otros archivos.

### 3.3 Estructura académica
```
Organización → Programa (fútbol, pádel…) → Grupo (Sub-10, Inicial…) → Horarios
```
- Criterio de grupo por programa: **año de nacimiento** (fútbol) o **nivel** (pádel, danza).
- Cada grupo tiene instructores asignados, horarios y cancha.
- **Alumnos:** datos personales, foto, documento, fecha de nacimiento, talle y posición.
- **Ficha médica:** alergias, grupo sanguíneo, contacto de emergencia y apto médico con vencimiento. Solo la ven los roles autorizados.
- **Familia:** agrupa alumnos y tutores. Un tutor puede tener varios hijos y un hijo varios tutores.
- **Inscripción** = alumno + grupo + temporada. Un alumno puede tener varias, y cada una genera sus propios cargos.
  - Estados: `pendiente`, `activo`, `becado`, `suspendido`, `baja`.
  - MVP: la carga la hace el club, también por importación Excel. *(Fase 2: solicitud online del padre → revisión → aprobación → cargo de inscripción.)*
- **Pase automático de grupo** al abrir una nueva temporada, cuando el criterio es el año de nacimiento.

### 3.4 Entrenamientos y asistencia *(Fase 2)*
- Horarios recurrentes por grupo (ej. lun/mié/vie 17:00–18:30) y generación automática de sesiones.
- El instructor toma asistencia desde la app: presente, ausente o justificado.
- Suspensión de práctica (lluvia, etc.) con push inmediato al grupo.
- Reporte de asistencia por alumno y por grupo.

### 3.5 Eventos, torneos y partidos *(Fase 2)*
- Tipos de evento: torneo, amistoso, festival, reunión de padres, cena.
- Convocatoria: el padre **confirma o rechaza** la asistencia.
- Costo opcional, que genera un cargo a las familias convocadas.
- Resultados y fixture *(futuro)*.

### 3.6 Comisión, reuniones, actas y resoluciones *(módulo `board`)*
- Cargos con mandato e historial de comisiones *(Sprint 1)*.
- **Reunión:** convocatoria (fecha, lugar o virtual, orden del día), notificación, asistencia y **quórum** automático.
- **Acta:** numeración correlativa, estados `borrador → en revisión → aprobada` y PDF.
- **Resolución:** numerada (`RES-2026-014`), vinculada a un acta, con votación (a favor / en contra / abstención).
- **Publicación selectiva:** a toda la organización, a uno o varios grupos, o interna.
- **Acciones derivadas:** una resolución puede generar un cargo (ej. "Cuota de torneo Sub-10: ₲ 50.000") o un evento.

### 3.7 Finanzas (núcleo)
- **Cuentas:** banco, caja (efectivo) y billetera digital. Cada una con su saldo.
- **Conceptos de cobro:** inscripción, cuota mensual, torneo, indumentaria, rifa y otros.
- **Tarifa** = concepto + grupo + temporada + monto + vigencia.
- **Cuenta corriente por alumno**, con vista consolidada por familia.
- **Cuota mensual automática**, con descuentos, becas y mora.
- **Cobros:**
  - Registro manual del tesorero (efectivo o transferencia) y recibo PDF.
  - Comprobante de transferencia subido por el padre *(Fase 2)*.
  - Pasarela online *(Fase 4)*.
- **Gastos:** alquiler de cancha (recurrente, a proveedor), árbitros, materiales, transporte, etc. Cada uno lleva cuenta, categoría, proveedor y comprobante.
- **Otros:** proveedores y transferencias entre cuentas. Presupuesto y conciliación bancaria *(fases 3 y 4)*.

### 3.8 Recaudación *(módulo `fundraising`, Fase 3)*
- **Rifa:**
  - Números, precio, premios y fecha de sorteo.
  - Talonarios asignados a familias, registro de vendidos y rendición.
  - Sorteo y publicación de ganadores.
  - Resultado neto (ingresos − premios).
- **Otras actividades** (cantina, pollada, festival): ingresos y gastos agrupados por actividad.

### 3.9 Indumentaria *(módulo `apparel`, Fase 3)*
- Campaña (prenda, precio, fecha límite).
- Pedido por alumno con talle, que genera un cargo.
- Estado de entrega.

### 3.10 Informes
- MVP: ingresos y egresos por período, saldo por cuenta y morosos (quién debe, cuánto y desde cuándo). Exportación PDF/Excel.
- Fase 3: flujo de caja, resultado por grupo y por actividad, y memoria y balance para asamblea.

### 3.11 Comunicación
- **Avisos segmentados:** a toda la organización, un programa, uno o varios grupos, un evento o una familia.
- **Tipos:** general, torneo, indumentaria, pago, suspensión y resolución.
- Push (FCM) + email, con **confirmación de lectura** ("visto por 34 de 40 familias").
- **Recordatorios automáticos:** cuota por vencer, cuota vencida, evento mañana.
- Calendario, encuestas simples y preferencias de notificación por usuario *(Fase 2)*.

### 3.12 Transversales
- Auditoría: quién creó, modificó o anuló cada registro, sobre todo en finanzas y roles.
- Adjuntos en cualquier entidad (medialibrary + S3).
- Búsqueda global.
- Datos de menores con acceso mínimo por rol.

## 4. Reglas de negocio clave

1. **Aislamiento total por organización** (`organization_id`). Nadie ve datos de otra organización.
2. **Permisos por alcance:** el instructor solo ve sus grupos y el padre solo a sus hijos y sus cargos.
3. **Dinero en enteros (₲).** Los descuentos y recargos se redondean al guaraní entero; la organización puede configurar redondeo a 500 o 1.000.
4. **Libro mayor inmutable:** un movimiento no se edita ni se borra; se anula con un contra-movimiento, con motivo y auditoría.
5. **Saldo = suma de movimientos.** Nunca se edita a mano.
6. **Tarifas:** un cambio no altera los cargos ya emitidos; rige desde su vigencia. Opcionalmente queda vinculada a la resolución que la aprobó.
7. **Cuota mensual automática** para inscripciones `activo` (no para `becado` total ni `baja`). Es idempotente: lock en Redis + índice único (inscripción + concepto + período).
8. **Cargos:** todo cargo pertenece a un alumno y a su inscripción, y guarda el monto base, los ajustes y el monto final. Nunca queda negativo.
9. **Descuentos:**
   - Tipos: hermanos, beca, convenio, pronto pago u otro; por porcentaje o monto fijo; con conceptos a los que aplica y vigencia.
   - Hermanos: según la posición del hijo entre las inscripciones activas de la familia.
   - El orden de aplicación es configurable.
10. **Becas:** parcial o total, con motivo, vigencia y **aprobación** de quien tenga el permiso "Aprobar becas".
11. **Mora:**
    - Configurable por organización y opcionalmente por concepto: vencimiento, gracia, recargo fijo o %, frecuencia y tope.
    - Se puede desactivar, o exonerar un cargo con motivo.
    - Hay recordatorios.
    - Aviso o bloqueo por deuda de más de N meses: opcional y nunca automático sin decisión del club.
12. **Imputación de pagos:** un pago se imputa a uno o varios cargos de uno o varios hijos, por defecto los más antiguos primero. Lo que sobra queda como **saldo a favor** de la familia.
13. **Comprobante subido por el padre:** queda `pendiente` hasta que el tesorero lo valida; recién ahí impacta en la cuenta.
14. **Gasto > umbral** requiere doble aprobación (tesorero **y** presidente).
15. **Mandatos que vencen solos.**
16. **Actas:** un acta aprobada no se edita; se corrige con una nueva resolución.
17. **Resoluciones:** una resolución publicada a un grupo notifica a sus tutores y puede generar un cargo.
18. **Rifas:** un número no se vende dos veces; la familia rinde contra los números asignados.
19. **Fechas:** se guardan en UTC y se muestran en la zona horaria de la organización.

## 5. Stack (versiones verificadas el 26/09/2026)

- **Backend y panel:** PHP 8.5 · Laravel 13 · Filament 5.9 · Livewire 4 · Tailwind 4.3 · Vite 8 · Sanctum 4 · Fortify 1.40 · Filament Shield 4.3 + spatie/permission 8 (teams = organización) · activitylog 5 · medialibrary 11 + S3 · dompdf 3 · endroid/qr-code (QR de invitaciones) · kreait/laravel-firebase 7 · Pint · Sail.
- **Datos:** **MariaDB 11.8 LTS** en dev, CI y producción (`utf8mb4_uca1400_ai_ci`). **Redis 8 + Horizon 5** para colas y caché/locks. Sesiones en MariaDB. Regla: el mismo motor y la misma versión en todos los entornos.
- **Tests:** Pest 5 (sobre PHPUnit 13), con arch tests.
- **Producción:** Docker Compose en VPS: PHP-FPM, Caddy, MariaDB, Redis AOF, Horizon y scheduler.
- **App:** Flutter 3.47 / Dart 3.13 · Riverpod 3 · go_router · dio · flutter_secure_storage · firebase_messaging (Sprint 5).

## 6. Arquitectura

- **Dos repos:**
  - `academia-api`: API `/api/v1` + panel Filament `/admin/{slug}` + Horizon + scheduler.
  - `academia-app`: Flutter.
- **Tenancy en una sola base:**
  - `organization_id` + trait `BelongsToOrganization` (global scope).
  - Tenancy nativo de Filament en el panel y header `X-Organization` en la API.
  - Roles distintos por organización.
- **Dinero:** value object `Money` (`₲ 150.000`).
- **Contrato primero:** cada funcionalidad define su endpoint de API antes de construir la pantalla. La app se desarrolla contra ese contrato (con fakes en los tests) y después se implementa en la API.

### Entidades
Organization, Membership, Season, Program, Group, Schedule, Session, Attendance, Student, Guardian, Family, MedicalRecord, Enrollment, Account, LedgerEntry, FeeConcept, Tariff, Charge, ChargeAdjustment, DiscountRule, Scholarship, LateFeePolicy, Payment, PaymentAllocation, PaymentProof, Expense, Supplier, Approval, Announcement, AnnouncementRead, DeviceToken, Invitation, BoardPosition (cargo con mandato).

Módulos opcionales: Meeting, Minute, Resolution, Vote, Event, EventCall, Fundraiser, RaffleBook, RaffleNumber, ApparelCampaign, ApparelOrder.

## 7. Hoja de ruta

**Prioridad: primero la app.** Cada sprint entrega su parte de la app y después su parte de la API. Ya no se deja toda la app para el final.

### Fase 1 — MVP Jakare (sprints de ~2 semanas)

| Sprint | App (primero) | API y panel (después) |
|---|---|---|
| **0. Fundaciones** ✅ | Base Flutter: login, selector de organización, sesión segura, CI | Laravel 13 + Sail, Filament, Shield, Sanctum, Horizon, tenancy, Pest, CI, Docker de producción |
| **1. Organizaciones y roles** | Aceptar invitación (link/QR → crear cuenta o entrar), perfiles y roles del usuario (tutor + cargo), vocabulario y módulos de la organización, "Mi cuenta" | Invitaciones (email/link/QR) y sus endpoints, cargos con mandato que vencen solos, feature flags, vocabulario en el panel, roles en `GET organization`, tests de aislamiento |
| **2. Académico** | "Mis hijos": lista, ficha, grupo, horarios e inscripciones | Temporadas, programas, grupos, horarios, alumnos, tutores, familias, ficha médica, inscripciones con estados, importación Excel, endpoints de "mis hijos" |
| **3. Finanzas** ✅ | Estado de cuenta por hijo y consolidado por familia (cargos con detalle de ajustes) | Tarifario, cuota mensual automática, descuentos, becas, vencimientos y configuración de mora, cargos manuales, endpoints de cuenta corriente |
| **4a. Cobros** ✅ | Pagos y recibos PDF en el estado de cuenta, saldo a favor | Cuentas (caja, banco, billetera) y libro mayor, pagos con imputación, pronto pago, saldo a favor, anulación, recibo PDF |
| **4b. Gastos e informes** | — | Gastos con doble aprobación, proveedores, transferencias entre cuentas, informes (balance, saldos, morosos; PDF/Excel) |
| **5. Avisos** | Avisos con lectura, push (firebase_messaging), registro del dispositivo | Avisos segmentados con lectura, push por lotes vía Horizon, recordatorios de cuotas, documentación OpenAPI |
| **6. Publicación** | Pulido, builds: web + Android (interno) + iOS (TestFlight) | Ajustes de rendimiento y seguridad; backups verificados |
| **7. Piloto Jakare** | Correcciones del uso real | Carga de datos reales, capacitación, invitación a padres |

### Fase 2 — Institucional y deportiva
- Comisión: reuniones, actas y resoluciones (PDF, votación, publicación a grupos que notifica y genera cargos).
- Asistencia desde la app del instructor y suspensión de prácticas con aviso.
- Eventos y torneos con confirmación de los padres.
- Comprobante de pago subido por el padre + validación del tesorero.
- Solicitud de inscripción online, calendario, encuestas y preferencias de notificación.

### Fase 3 — Recaudación e informes
- Rifas e indumentaria.
- Informes por grupo y por actividad, memoria y balance para asamblea.
- Presupuesto.

### Fase 4 — SaaS comercial
- Alta autoservicio, planes y suscripciones.
- Marca por organización y landing.
- Pagos online (Bancard vPOS/QR, Pagopar).
- SIFEN, WhatsApp, conciliación bancaria y estadísticas.

## 8. Calidad y operación

- **Tests obligatorios:** reglas de dinero, aislamiento entre organizaciones y arch tests (Pest). En la app: redirecciones, repositorios y validaciones.
- **CI:** lint + tests por PR en la API; `flutter analyze` + `flutter test` en la app.
- **Seguridad:** auditoría en finanzas y roles; ficha médica restringida.
- **Operación:** Horizon con alerta de jobs fallidos, backups diarios cifrados de MariaDB con restauración probada, Redis con AOF.
- **Cuentas a crear:** Firebase, Google Play (USD 25), Apple Developer (USD 99/año), S3 y dominio (necesario también para los links de invitación y los deep links de la app).

## 9. Decisiones

- ✅ Paraguay/₲, recibos internos (SIFEN opcional a futuro).
- ✅ Cuenta corriente por alumno, con vista por familia.
- ✅ Cuota por grupo, multi-disciplina.
- ✅ Descuentos, becas y mora configurables.
- ✅ Un rol por cargo, con mandato.
- ✅ Laravel 13 + Filament 5 + Flutter 3.47.
- ✅ MariaDB 11.8 en todos lados.
- ✅ Redis + Horizon desde el inicio.
- ✅ Pest 5.
- ✅ Dos repos.
- ✅ Solo español.
- ✅ Prioridad de la app en cada sprint, con contrato de API primero.

### Pendientes
- ⏳ Nombre del producto.
- ⏳ Hosting (¿el mismo servidor que OpenSciRank?) y dominio.
- ⏳ Datos de Jakare: cantidad de jugadores, categorías y técnicos.
- ⏳ ¿Los técnicos cobran del club? Si es así, se agrega "pagos a técnicos" como gasto recurrente.
- ⏳ ¿El alquiler de cancha es mensual fijo o por hora/uso?

## 10. Estado

### Sprint 0 — completado (26/09/2026)
Repos: `aldorodrigo/academia-api` y `aldorodrigo/academia-app`, rama `main`.

- **`academia-api`** (Laravel 13.33, PHP 8.5):
  - Sail con MariaDB 11.8, Redis 8 y Mailpit.
  - Filament 5.9 con tenancy (`/admin/{slug}`), Shield con teams y Horizon.
  - `Organization`, `Membership`, `BelongsToOrganization` + `CurrentOrganization`, `Money` y `Season`.
  - API v1: `POST/DELETE auth/token`, `GET me`, `GET organization`.
  - 27 tests Pest. CI con MariaDB + Redis. Docker de producción.
- **`academia-app`** (Flutter 3.47.5):
  - Riverpod 3, go_router, dio y almacenamiento seguro.
  - Login → organización → inicio, probado contra la API.
  - 7 tests y CI.
- **Pendiente:**
  - Verificar el build de la imagen Docker de producción.
  - Corregir el README: `laravelsail/php85-composer` no existe; usar `php84-composer` para el `composer install` inicial.

### Sprint 1 — organizaciones y roles
- **App** (aldorodrigo/academia-app#1): aceptar invitación por link o código (cuenta nueva o existente),
  perfiles con mandato en el inicio, "Mi cuenta", vocabulario y módulos, URLs sin `#` en la web.
- **API y panel:**
  - Roles base por organización (`OrganizationRole`) con descripción; super admin (plataforma) vs. admin (organización).
  - `role_assignments` con mandatos, historial y vencimiento diario (`roles:expire`, fecha local).
  - Invitaciones con email + QR, token hasheado, un solo uso, 14 días; endpoints públicos de la API.
  - Panel: Invitaciones, Miembros (asignar/quitar roles, historial, activar/desactivar) y Configuración (vocabulario y módulos).
  - `GET organization` con `membership.roles`.
- **Probado de punta a punta:** invitación creada → email en Mailpit con link y QR → aceptada desde la app web
  en un navegador real → el inicio muestra "Tutor" y "Secretario · hasta 31/12/2027"; `roles:expire` quita un mandato vencido.
- Pendiente: permisos finos por rol en Shield (por ahora el admin tiene todo y el resto lo que se le otorgue);
  restringir el acceso al panel a quien tenga algún rol con permisos (hoy entra cualquier miembro activo, aunque no ve nada);
  deep links nativos (Android/iOS) cuando haya dominio.

### Panel de plataforma — completado
- `/plataforma` para super admins: tablero, organizaciones (alta con invitación al primer admin, editar,
  suspender/reactivar con motivo, entrar al panel, invitar otro admin) y usuarios (dar/quitar super admin,
  con resguardo de no quitarse a uno mismo ni al último).
- Organizaciones suspendidas: bloqueo en app, API, panel e invitaciones; el super admin sigue entrando.
- Módulos: los habilita la plataforma; el admin de la organización solo los ve.

### Sprint 2 — académico
- **Contrato:** `GET students` y `GET students/{id}` en `docs/API_V1.md` (primero), después la app y la API.
- **App** (aldorodrigo/academia-app, rama `sprint-2-mis-hijos`): "Mis hijos" en el inicio (edad, grupo, estado),
  ficha `/hijos/:id` con datos, inscripción (grupo, horarios, técnicos), tutores y ficha médica si la API la manda
  (aviso de apto vencido); "Mis inscripciones" para el alumno adulto.
- **API y panel:**
  - Modelos: `Venue`, `Program` (criterio por año de nacimiento o nivel), `Group` (edades o nivel, cupo, instructores),
    `Schedule`, `Family`, `Student` (alumno adulto con `user_id`), `Guardian`, `guardian_student` con parentesco,
    `MedicalRecord` (datos clínicos cifrados), `Enrollment` con estados; una sola temporada actual por organización.
  - `StudentPolicy::viewMedical`: permiso `ViewMedical:Student`, tutor del alumno, alumno adulto e instructor de su grupo.
  - Endpoints con API Resources (`app/Http/Resources/Api/V1`); `404` para alumnos ajenos.
  - Panel simplificado:
    - **Académico:** Categorías, Jugadores, Inscripciones, Temporadas.
    - **Personas:** Miembros, Invitaciones, Tutores.
    - Disciplinas y sedes se crean desde el formulario de Categoría; con una sola disciplina no se pregunta.
    - Familia automática e invisible (`Family::syncFor`): los hermanos que comparten tutor quedan juntos.
  - **Alta de jugador en un paso** (`RegisterStudent`):
    - datos, categoría sugerida por fecha de nacimiento (`Group::suggestFor`), temporada y estado;
    - tutores, reutilizando los ya cargados por correo, con invitación a la app;
    - un menor necesita al menos un tutor.

    Si el chico ya existe (por documento, o por nombre + fecha de nacimiento), el formulario lo avisa y lleva a su ficha.
  - **Reinscripción:**
    - acción "Inscribir" en la ficha (también con `?action=enroll`), con disciplina si hay varias, categoría sugerida y aviso si ya está en otra disciplina;
    - **pase de temporada** en bloque (Inscripciones → Pase de temporada, `TransferSeason`);
    - las inscripciones de temporadas anteriores quedan "Finalizada" solas.
  - Importación Excel/CSV de alumnos + hasta dos tutores: `ImportStudentRow` usa `RegisterStudent`, se puede reimportar sin duplicar, la categoría es opcional (se sugiere por edad) y la invitación es opcional.
  - Invitaciones con `guardian_id`: al aceptar, el tutor queda vinculado y la app muestra sus hijos.
  - Seeder de desarrollo: `tutor@academia.test` y `tecnico@academia.test` (`password`).
- **Probado de punta a punta:** tutor sembrado → login en la app web en un navegador real → inicio con sus dos hijos →
  ficha con grupo, horarios, técnico, tutores y ficha médica.
- Pendiente: foto del alumno en el panel (medialibrary está, falta el plugin de Filament); permisos finos por rol en Shield; vista del instructor
  en la app (Fase 2).

### Sprint 3 — finanzas (cargos)
- **Contrato:** `GET account` (consolidado de la familia) y `GET students/{id}/account`.
- **App:** tarjeta "Total a pagar / Vencido" en el inicio, `/estado-de-cuenta` (saldo total y por hijo, Pendientes/Todos,
  detalle desplegable de cada cargo) y sección "Estado de cuenta" en la ficha del hijo.
- **API y panel:**
  - Conceptos (cuota mensual e inscripción del sistema), tarifas por temporada y categoría con vigencia.
  - Cargos inmutables (se anulan con motivo) con ajustes: beca, hermanos (por posición), convenio y otro, en el orden configurable.
  - Cuota mensual automática idempotente (lock + índice único), comando `charges:generate` el día 1 y botón "Generar cuotas" con vista previa.
  - Cargo de inscripción automático al inscribir (si hay tarifa); cargos manuales para jugadores o categorías.
  - Becas pendientes → aprobadas por quien tiene "Aprobar becas"; la inscripción pasa a `becado`.
  - Vencimiento (día del mes + gracia) y estado "Vencido"; recargo por mora solo configurado.
  - Panel "Finanzas" (Cargos, Tarifas, Descuentos, Becas), sección Cobros en Configuración y pestaña Cuenta del jugador.
- **Probado de punta a punta** en el panel: tarifa → hermanos → beca aprobada → cuotas de septiembre y octubre
  (Sofía: ₲ 150.000 − beca ₲ 75.000 − hermanos ₲ 15.000 = ₲ 60.000) → volver a generar no duplica.
- **Se pasó al Sprint 4:** cuentas y libro mayor, recargos por mora y descuento por pronto pago (dependen de los pagos).

### Sprint 4a — cobros
- **Contrato:** el estado de cuenta suma `paid_amount`/`pending_amount` por cargo, `credit` (saldo a favor) y `payments` con recibo (link firmado).
- **App:** cargos pagados en parte ("Pagado ₲ X de ₲ Y"), saldo a favor, pestaña Pagos con "Ver recibo"; los links
  directos (web) sobreviven a la carga de la sesión (`/?from=…`).
- **API y panel:**
  - Cuentas (caja, banco, billetera) con libro mayor inmutable (saldo = suma de movimientos, contra-movimiento para anular).
  - Pagos por familia imputados del vencimiento más viejo al más nuevo (o a los cargos que elige el tesorero), pronto pago
    si salda a tiempo, saldo a favor que se aplica solo a los próximos cargos, recibo correlativo con monto en letras.
  - El recibo no cambia cuando su saldo a favor se aplica después (`from_credit`).
  - Anular pago: los cargos vuelven a pendientes, contra-movimiento y se revierte el saldo a favor aplicado.
  - Panel: Pagos (Registrar pago con preselección y resumen, Recibo, Anular), Cuentas con movimientos, pronto pago en Descuentos.
- **Probado de punta a punta** en el panel y la app (capturas): cuenta con saldo inicial → pago de ₲ 1.500.000 (pronto pago
  en octubre) → recibo PDF → pago con saldo a favor → diciembre se descuenta solo → la app muestra lo pagado y los recibos
  → anular vuelve los cargos a pendiente.
- Encontrado al probar: el recibo cambiaba al aplicarse el saldo a favor; los links directos de la web se perdían al recargar.
- Fuera del sprint: recargos por mora (queda la configuración).

### Próximo: Sprint 4b — gastos e informes
