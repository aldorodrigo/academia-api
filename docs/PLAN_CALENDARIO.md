# Plan — Calendario de actividades

> Plan de una funcionalidad. Entra en la hoja de ruta de `PLAN.md` (§3.5, §3.11 y Fase 2). Contrato en `API_V1.md`
> («Calendario de actividades»). Orden: contrato → app contra fakes → API y panel.

## Contexto

Hoy cada fecha vive en su propio rincón: el tutor solo ve la **próxima** clase de cada hijo (`GET agenda`, 7 días), el
técnico solo "Hoy" (`GET classes?date=`) y el mes de cada grupo **hasta hoy**, y el profesor de particulares una semana
(`/particulares/agenda`). No hay forma de ver "¿qué hay este mes?", ni de avisar un torneo, una reunión o un feriado.
PLAN.md lo tiene en Fase 2 (§3.5 eventos, §3.11 calendario); no hay nada construido (ni modelo `Event`, ni paquete de
calendario, ni chequeo de feriados en `ResolveClassSessions`).

**Decisiones del usuario (2026-10-04):**
- El calendario muestra lo que ya existe (clases, recuperaciones, suspendidas, reprogramadas, particulares) **más**
  eventos publicados y "días sin clase".
- Eventos **solo publicar** (sin confirmación ni costo). Publican: técnico (solo a **sus** grupos), admin, secretario,
  tesorero (y prosecretario/protesorero) a cualquier grupo o a todo el club. Al publicar se eligen los grupos: a esos
  se les manda push y lo ven en su calendario.
- Se cargan desde el **panel y la app**.
- "Día sin clase" **suspende solo** las clases de esos grupos y días, con las reglas de suspensión que ya existen
  (incluido "No cobrar" en temporadas por día). Lo marcan los mismos que publican, con el mismo alcance.

Orden del proyecto: contrato en `API_V1.md` → app contra fakes → API y panel.

---

## 1. Contrato (ver `API_V1.md`, «Calendario de actividades»)

- `GET organization`: permiso nuevo `publish_events` en `membership.permissions`.
- **`GET calendar?from&to[&student_id][&group_id]`** — máximo 42 días (grilla de 6 semanas), hoy ± 365.
  ```json
  { "data": { "from": "…", "to": "…",
    "classes":  [{ …ClassSessionResource sin counts, "day_off_id": 7|null, "can_take_attendance": true,
                   "students": [{ "id", "first_name", "response": "va|no_va|null", "can_respond", "attendance": "presente|ausente|justificado|null" }] }],
    "bookings": [{ …BookingResource, "as": "student|teacher" }],
    "events":   [{ …EventResource resumido }] } }
  ```
  Clases: grupos que instruye + grupos de los hijos a cargo. Bookings solo con `private_lessons`, sin canceladas.
  Eventos cancelados vienen con `cancelled: true` (para que la familia note el cambio).
- **`EventResource`**: `id, kind (evento|sin_clase), category, category_label, title, description, starts_on, ends_on,
  starts_at|null, ends_at|null` (null = todo el día), `venue|null, place|null, audience{everyone, groups[]},
  waive_charge, cancelled, cancel_reason, created_by{name}, created_at, can_edit, can_cancel`; el detalle suma
  `affected_classes`.
- `GET events/{id}` · `GET events/options` (`can_target_organization`, grupos publicables, `venues`, categorías:
  evento → torneo/amistoso/festival/reunión/otro; sin_clase → feriado/vacaciones/lluvia/otro).
- `POST events/preview` (mismo cuerpo que publicar) → `recipients{families, instructors}`,
  `classes{suspended, skipped_started, already_off, items≤50}`, `can_waive_charge`.
- `POST events` · `PUT events/{id}` (+ `notify`, por defecto true) · `POST events/{id}/cancel {reason?}`. **Sin DELETE.**
- 422 en español ("Elegí al menos un grupo.", "Solo podés publicar para tus grupos.", "Un día sin clase no puede
  empezar antes de hoy."…); 403 si no puede publicar.
- **Push** (con `organization` en `data`): `event_published`, `event_changed`, `event_cancelled` → `/eventos/{id}`;
  `days_off`, `days_off_cancelled` → `/calendario?fecha=…`. **Un push por persona** (no N `ClassSuspended`), sin
  quien publicó.

## 2. App — UI/UX

### `/calendario` (`CalendarScreen`)
- **AppBar:** "Calendario" (subtítulo: organización), botón "Hoy", `SegmentedButton` **Mes | Lista** (recordado por
  viewer en shared_preferences, con try/catch).
- **Filtros (una fila de chips):** tutor con más de un hijo → "Todos · Mateo · Sofía" (`student_id`); staff →
  "Mis grupos ▾" (sheet por disciplina, `term('group')`); tipos "Clases · Particulares · Eventos" (en el cliente).
- **Mes (teléfono):** encabezado con chevrons + swipe (`PageView`); `MonthGrid` lunes primero, celdas ≥ 48dp; debajo,
  la lista del día elegido. Al scrollear, la grilla se colapsa a `WeekStrip` fija; en pantallas bajas o texto > 1.3
  arranca como semana.
- **Marcas por día (forma + color, nunca solo color):** clase ● `primary`, suspendida ○, particular ■
  `onSurfaceVariant`, evento ◆ `tertiary`; máximo 3 y "+". Día sin clase: fondo `surfaceContainerHighest` + ícono
  `event_busy`. Hoy: aro; elegido: círculo `primaryContainer`. Leyenda plegable.
- **Lista:** agenda desde hoy con encabezados fijos por día (`formatShortDay`), carga por mes al scrollear,
  pull-to-refresh.
- **Ancho ≥ 840dp (web/tablet):** dos paneles: mes grande a la izquierda (hasta 2 títulos por celda), día a la derecha;
  "Publicar" pasa a botón del AppBar.

### Filas (`CalendarEntryTile`: ícono en círculo tonal, título, hora y lugar)
- **Clase:** "Fútbol · Sub-10" (tutor: "— Mateo y Sofía"); chip "Va / No va / ¿Lo llevás?" o, si pasó, la asistencia
  (`AttendanceStatusLabel`); "Recuperación" (chip existente); suspendida tachada + `SuspendedChip` + motivo;
  reprogramada "Pasó al jue 9, 18:00".
- **Particular:** "Clase particular con {profesor}" / para el profesor, el alumno.
- **Evento:** ícono por categoría (torneo `emoji_events`, amistoso `sports`, festival `celebration`, reunión `forum`,
  otro `event`), color `tertiary`, "Todo el día", "Día 1 de 2"; cancelado tachado + "Cancelado".
- **Día sin clase:** `DayOffBanner` arriba del día ("Sin clases: Vacaciones de invierno (7 al 18 de julio) · Para:
  Sub-10, Sub-12").

### Al tocar
- Clase (tutor) → `showClassEntrySheet` con detalle y "¿Lo llevás?" por hijo (`GuardianActions.respond`).
- Clase (staff con `can_take_attendance`) → `/clases/:id`.
- Particular → `showBookingSheet` (profesor) o `/reservas` (alumno/tutor).
- Evento / día sin clase → `/eventos/:id`.

### Publicar (solo con `can('publish_events')`)
- FAB "Publicar" → sheet "Evento" / "Día sin clase" → `/calendario/nuevo?tipo=…&fecha=<día elegido>`.
- **`EventFormScreen`** (un solo formulario, también `/eventos/:id/editar`):
  1. Tipo (bloqueado al editar). 2. Categoría en chips (en sin clase sugiere el título). 3. Título y fechas
  ("Desde/Hasta" con `showDateRangePicker`; evento: "Todo el día" o horas). 4. Lugar (sedes de `venues` u "Otro
  lugar…"). 5. Descripción. 6. **"¿Para quién?"** (`AudiencePicker`): "Todo el club" (solo si
  `can_target_organization`) o grupos por disciplina con casilla tri-estado; técnico con un solo grupo → preelegido.
  7. Sin clase: "No cobrar las clases suspendidas" solo si `can_waive_charge`.
  8. **`EventPreviewCard`** (debounce 400 ms): "Les llega a 48 familias y 3 técnicos." / "Se suspenden 12 clases. 2 ya
  empezaron y no se tocan." (lista plegable).
- Botón "Publicar y avisar"; sin clase pide confirmación ("Se suspenden 12 clases y avisamos a 48 familias.
  ¿Publicamos?"). Éxito: "Listo, publicado." y vuelve al día. 422 por campo. Al editar: "Avisar del cambio".

### `/eventos/:id` (`EventScreen`)
Ícono + categoría, título, rango ("Sáb 11 y dom 12 de octubre · 8:00 a 18:00"), lugar, descripción, "Para: …",
"Publicado por Ana el 2 de octubre"; cancelado → banner con motivo; sin clase → clases suspendidas. Menú (según
`can_edit`/`can_cancel`): Editar, "Cancelar evento" (sin clase: "Las 12 clases vuelven a quedar programadas y
avisamos a las familias."). Si el push es de otra organización: "Esto es de {org}. Cambiar a {org}".

### Estados y accesibilidad
- Cargando: se mantiene el mes anterior con `LinearProgressIndicator` arriba (sin parpadeo). Vacío: "No hay nada
  este día." / "Todavía no hay clases ni eventos este mes." Error: `apiErrorMessage` + "Reintentar". Sin conexión:
  lo último en memoria con aviso; publicar requiere conexión.
- `Semantics` por celda ("martes 7 de octubre, hoy, 2 clases, 1 evento, sin clases"), tooltips en íconos, ícono +
  texto en todo estado, objetivos ≥ 48dp, modo oscuro con tokens del `ColorScheme`.

### Entradas
- `QuickAction` "Calendario" (primero, para todos). La "Agenda" del profesor pasa a `Icons.event_note_outlined`.
- `UpcomingEventsCard` en el inicio (próximos 3 eventos o días sin clase en 30 días; se oculta si no hay; "Ver
  calendario").
- "Ver calendario" en `NextClassesList`.
- Push `event_*`/`days_off*` abren su ruta; en primer plano invalidan providers + SnackBar.

## 3. App — archivos

**Nuevo `lib/features/calendar/`:**
- `data/models.dart`: `CalendarClass` (envuelve `ClassSession` + `students` + `dayOffId` + `canTakeAttendance`),
  `CalendarBooking` (envuelve `Booking`), `CalendarEvent`, `EventKind`, `EventCategory`, `EventAudience`,
  `CalendarRange` con helpers puros (`entriesOn`, `markersOn`, `dayOffOn`, `gridRange(month)`), `EventOptions`,
  `EventDraft`, `EventPreview`.
- `data/calendar_repository.dart` (recibe `Dio` y `SessionStorage`): `calendar`, `event`, `options`, `preview`,
  `publish`, `update`, `cancel`.
- `data/calendar_providers.dart`: `calendarRangeProvider` (family, autoDispose), filtros, vista, día elegido,
  `eventProvider`, `eventOptionsProvider`, `upcomingEventsProvider`.
- `data/event_form_controller.dart` + `data/event_validation.dart` (funciones puras) + `data/calendar_actions.dart`
  (publicar/editar/cancelar e invalidar calendario, `agendaProvider`, `classesProvider`).
- `presentation/`: `calendar_screen`, `month_grid`, `week_strip`, `day_entries` (+ `DayOffBanner`), `agenda_list`,
  `calendar_entry_tile`, `calendar_style` (ícono/color/etiqueta por tipo), `class_entry_sheet`, `event_screen`,
  `event_form_screen`, `audience_picker`, `event_preview_card`, `cancel_event_dialog`, `upcoming_events_card`.

**Se tocan:** `lib/router.dart` (`/calendario`, `/calendario/nuevo`, `/eventos/:id`, `/eventos/:id/editar`),
`features/home/data/quick_actions.dart`, `features/home/presentation/home_screen.dart`,
`features/attendance/presentation/next_class_card.dart`, `guardian_actions.dart` (invalida el calendario),
`core/push/` (tipos nuevos), `CLAUDE.md`.

**Se reusan:** `ClassSession.fromJson`, `Booking`, `SuspendedChip`/`AttendanceStatusLabel`
(`attendance_status_style.dart`), `GuardianActions.respond`, `showBookingSheet`, `venuesProvider`,
`instructorGroupsProvider`, `format.dart` (`formatShortDay`, `weekdayShort`, `apiDate`, `formatPeriod`),
`todayProvider`, `OrganizationDetails.can/term/hasFeature`, `apiErrorMessage`. Sin paquete de calendario: la grilla
es propia (simple, controla tema y accesibilidad).

**Tests:** `test/features/calendar/calendar_json.dart` (sobre `class_json.dart` y `lesson_json.dart`); modelos (grilla
entre meses/años, marcas, eventos de varios días, días sin clase); repositorio con `fakeDio` (query y cuerpos);
validación; pantalla (marcas y semántica, elegir día, filtro por hijo manda `student_id`, "¿Lo llevás?" desde el
sheet, técnico abre `/clases/:id`, vacío/error, layout ancho a 1100px); formulario (técnico solo sus grupos y sin
"Todo el club", texto del preview, "No cobrar" solo si se puede, confirmación, 422 inline); detalle (cancelar,
permisos, otra organización); `quick_actions_test` y `router_redirect_test`.

## 4. API y panel (worktree nuevo de `main` en academia-api; el checkout actual tiene trabajo sin commitear de otra sesión)

- **Migraciones:** `events` (organización, kind, category, title, description, starts_on/ends_on, starts_at/ends_at
  null, venue_id/place, for_everyone, waive_charge, created_by/updated_by, cancelled_at/by/reason, SoftDeletes +
  deleted_by); pivote `event_group`; `class_sessions.event_id` ("suspendida por este día sin clase").
- **`Event`** (`BelongsToOrganization`, scopes `overlapping`, `visibleTo`, `active`), enums `EventKind`,
  `EventCategory`. Independiente de `Offering` (a futuro, `offering_id` nullable); sin RSVP ni costo; no se ata a
  `Feature::Tournaments`.
- **`EventAccess`** (patrón de `PaymentReportAccess`): permiso `Publish:Events` o cargo vigente
  secretario/prosecretario/tesorero/protesorero (admin pasa por Gate), o instructor de algún grupo (solo sus grupos,
  sin "todo el club"). `publish_events` en `CurrentOrganizationController`; `DefaultPermissions` para esos roles.
- **`ResolveClassSessions::between()` en lote:** horarios, temporadas y días sin clase una vez; inserta lo que falta
  con `insertOrIgnore`; si cae en día sin clase nace suspendida con `event_id` (y waiver si corresponde); oculta
  (no borra) sesiones futuras intactas que ya no coinciden con un horario. `forDate` usa el mismo chequeo, así
  recordatorios y agenda lo respetan solos.
- **`SuspendClass`:** extraer `apply()` sin notificación; `handle()` = `apply()` + push. Reanudar a mano limpia
  `event_id` (la clase deja de ser del evento).
- **`ApplyDayOff::sync(event, user, dryRun)`**, un solo camino para publicar, editar, cancelar y preview: crea las
  sesiones del rango (el waiver necesita filas reales, `SeasonPeriods::waivedDates()`), suspende las programadas que no
  empezaron y sin asistencia; no toca empezadas, reprogramadas ni suspendidas a mano; al achicar/cancelar reanuda solo
  las que siguen suspendidas por ese evento.
- `PublishEvent`/`UpdateEvent`/`CancelEvent` en transacción, push después del commit; `EventRecipients`
  (tutores y alumnos adultos de inscriptos vigentes + técnicos; todo el club = miembros activos; sin repetir, sin
  autor). Notificaciones `EventPublished/Changed/Cancelled` y `DaysOff` (texto agrupado por persona). Evaluar no mandar
  copia por correo de cada evento.
- `CalendarController` → `BuildCalendar` (sin `counts()`, alumnos y asistencias en una consulta cada uno),
  `EventController`, `EventResource`. `RescheduleClass` avisa (no bloquea) si la recuperación cae en día sin clase.
- **Panel:** `EventResource` (grupo "Comunicación"): tabla (fecha, tipo, título, para, estado), filtros, formulario
  como el de la app con preview en vivo, acción "Cancelar" con motivo; sin borrar activos (solo cancelados, soft delete
  con `deleted_by` y restaurar). "Suspendida por: {evento}" en `ClassSessionsRelationManager` y `TodayClasses`.
- **Pest:** calendario (rango, visibilidad tutor/técnico/otra organización, eventos cancelados, consultas acotadas),
  resolver en lote = por día, matriz de roles, un `DaysOff` por tutor y ningún `ClassSuspended`, reglas de salto,
  waiver solo en temporadas por día, preview = resultado real, editar/cancelar reanuda solo lo propio, panel.

## 5. Entregas

1. **API PR 0 (docs):** sección del contrato en `API_V1.md` + nota en `PLAN.md` (§3.5 partido: publicar ahora,
   confirmación y costo después; §10 estado).
2. **App PR 1:** capa de datos, modelos, repositorio, providers, fixtures y tests unitarios.
3. **App PR 2:** calendario de lectura (mes, lista, ancho, filtros, filas, sheets, rutas, accesos del inicio).
4. **App PR 3:** detalle de evento, publicar/editar/cancelar con preview, `UpcomingEventsCard`, push, `CLAUDE.md`.
5. **API PR 4:** migraciones, modelos, resolver en lote con días sin clase, refactor de `SuspendClass`, `GET calendar`.
6. **API PR 5:** `EventAccess`, acciones de eventos, notificaciones, `publish_events`, aviso en reprogramar.
7. **API PR 6:** panel (`EventResource`, marcas en clases), tests y `business-logic.md`.

## 6. Verificación

- App: `flutter analyze`, `flutter test`, `dart format lib test`.
- API: `vendor/bin/pest`, `vendor/bin/pint`.
- Punta a punta con Sail y datos de Jakare: como secretario publicar un "Feriado" desde el panel y otro desde la app →
  clases suspendidas, waiver y cuotas en una temporada por día, un push por tutor (log); calendario del tutor en el
  emulador Android y el layout ancho en Chrome; cancelar → clases programadas de nuevo; como técnico, no puede elegir
  grupos ajenos ni "Todo el club"; evento "Torneo" con push y en `UpcomingEventsCard`.

## Riesgos

- **Sesiones futuras creadas al mirar el mes:** acotado a temporadas vigentes y hoy+365, filtro de huérfanas; si
  molesta, proyectar sin persistir más allá de 14 días (la app tendría que aceptar clases sin `id`).
- **Rendimiento:** `between()` día por día y `counts()` N+1 → lote + test de cantidad de consultas.
- **Multi-organización:** el calendario es de la organización activa (como todo hoy); los push llevan `org` y el
  repositorio queda listo para sumar organizaciones después (PLAN_ACTIVIDADES §7).
- **Zona horaria:** "ya empezó" lo decide la API con la zona del club.
- **Choque de docs:** la rama `informes-etapa-0` también edita `API_V1.md`/`PLAN.md`; la sección va al final y se
  rebasea.
- **Compatibilidad futura:** `for_everyone` + grupos sirve de base para los avisos segmentados (5b).
