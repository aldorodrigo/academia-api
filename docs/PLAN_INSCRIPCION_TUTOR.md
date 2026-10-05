# Plan — Inscribir desde la app (tutor y admin)

> Plan de una funcionalidad. Es un **subconjunto de la etapa B de `PLAN_ACTIVIDADES.md`** (solicitud de inscripción
> con aprobación) y la **primera pieza del Sprint 5e** ("Inscripciones por link", `PLAN_PRIMEROS_PASOS.md` etapa 2):
> la solicitud, la confirmación y la lista del club. No incluye la página pública ni las actividades.
> Las reglas están en `business-logic.md` §3 y los endpoints en `API_V1.md` («Inscripción desde la app»).
> `PLAN.md` lo integra el usuario.

**Pedido (04/10/2026):** "permite a los tutores inscribir alumnos desde la app". Viene del hallazgo **F2** de la prueba
integral: los alumnos solo se cargaban desde el panel; el fundador que arranca desde el celular tenía que ir a la
compu, y la familia que ya está en el club no podía sumar al hermano sin pedírselo a alguien.

**Segunda ronda (04/10/2026), decisiones del usuario:**
1. **"Entra ya, se confirma después"**: no se bloquea al chico. Al pedir, aparece en la lista del técnico como "Nuevo,
   por confirmar" y va a clases (se le toma asistencia). Las cuotas se emiten al confirmar. Si se rechaza, sale.
2. **Confirman por defecto el admin, el secretario y el técnico** (el técnico, en sus categorías). Editable por rol.
3. Si quien pide puede confirmar, **se confirma sola**. Siempre queda quién confirmó o rechazó.
4. **Documento del chico obligatorio** (app, API y panel); los duplicados se detectan por documento.
5. Parentesco: arranca en "Madre" y se elige otro.
6. **El admin carga alumnos de otras familias desde la app**: alta directa con su tutor (nombre, celular, correo
   opcional) e invitación para mandar por WhatsApp (link `wa.me`, sin la API de WhatsApp).

**Tercera ronda (04/10/2026):**
1. El **prosecretario confirma por defecto** (queda como está). Antes de sumar otros roles, análisis de todos
   (sección «¿Quién más confirma?»); no se cambió ningún otro rol: lo decide el usuario.
2. "Nuevo, por confirmar" también en la **lista del mes del grupo** (`/grupos/:id`), con Confirmar y Rechazar.
3. **Nada se borra: soft delete.** Al rechazar o cancelar, el alumno creado por la solicitud, su inscripción pendiente
   y sus asistencias se **archivan** (`deleted_at`) y dejan de aparecer en listas, cuentas e informes. Si vuelve a
   pedirse el mismo documento, **se restaura el mismo alumno** con su historial (no se crea otro).

---

## 1. Idea

```
Tutor (ya en el club) → "Inscribir a otro hijo" → datos + documento + categoría sugerida por la API (+ ficha médica)
→ RegisterStudent: alumno + tutor + familia + inscripción PENDIENTE (va a clases, no se cobra)
→ "Nuevo, por confirmar" en la planilla del técnico · aviso a quienes confirman
Club (técnico en la planilla, secretario o admin en la app o el panel) → Confirmar (categoría, mitad de mes, cupo)
→ inscripción ACTIVO: cargo de inscripción + cuotas desde el día en que empezó · push al tutor
                     → Rechazar (motivo) → sale de la lista (se deshace el alta) · push al tutor
Admin → "Cargar alumno" → RegisterStudent (activo) + invitación del tutor → "Mandar por WhatsApp"
```

## 2. Alcance y decisiones

| Tema | Decisión |
|---|---|
| **Quién pide** | Cualquier miembro activo de la organización (tutor, técnico o admin con hijos), con `X-Organization`. |
| **Link público** | **Queda para 5e** (página pública, cuenta dentro del flujo, consentimiento, membresía nueva, actividades). Lo de acá se reutiliza: 5e le suma `offering_id`, el origen y la membresía. |
| **Modelo** | `EnrollmentRequest` (la solicitud: quién, qué pidió, quién la revisó) + el alta real hecha con `RegisterStudent` en estado `pendiente` (`student_id`, `enrollment_id`). Guarda lo necesario para deshacer: si el alumno lo creó la solicitud, el estado anterior de la inscripción (ej. baja) y si el tutor ya quedó vinculado. |
| **Inscripción pendiente** | Va a clases (las clases incluyen a los `pendiente` con una solicitud por confirmar) y **no se cobra**: ni cargo de inscripción ni cuotas hasta pasar a `activo`. Al confirmar se emiten desde `enrolled_on` (el día que pidió) con `MidPeriod`. |
| **Qué carga** | Nombre, apellido, fecha de nacimiento, **documento** (obligatorio), parentesco, disciplina, temporada y categoría (la sugiere la API), notas y ficha médica opcional. |
| **Chico de otra familia** | Si el documento ya es de un alumno con otros tutores, va a clases ya, pero el tutor se vincula (y ve sus datos) recién al confirmar; sus datos no se pisan. |
| **Volver después de una baja** | Si pide la categoría de la que se dio de baja, la inscripción vuelve a `pendiente` con fecha de hoy: no se cobran los meses que estuvo afuera. Si se rechaza, vuelve a la baja. |
| **Quién confirma** | `Manage:EnrollmentRequests` (todas; secretario y prosecretario por defecto, admin siempre) o `Confirm:GroupEnrollments` (en las categorías donde es técnico; el técnico por defecto). Los dos se editan en Roles; una migración se los da a los roles existentes. En la app: `manage_enrollment_requests`. |
| **Se confirma sola** | Si quien pide puede confirmar en esa categoría: `reviewed_by` = él mismo (`self_approved`), sin avisos. |
| **Cupo** | Ocupan las inscripciones activas, becadas o pendientes. Confirmar por encima del cupo (sin contar el lugar de la propia solicitud) pide confirmación. |
| **Rechazar o cancelar** | Sale de la lista sin borrar nada: se **archiva** (soft delete) la inscripción pendiente (o vuelve al estado anterior, ej. baja) y, si el alumno lo creó o restauró la solicitud y no tiene nada más, el alumno y sus asistencias. Rechazar pide motivo y avisa al tutor. |
| **Soft delete** | `students`, `enrollments` y `attendances` tienen `deleted_at` (también las bajas de "Borrar" del panel, que ahora archivan). Las consultas de Eloquent los excluyen solas; las de `DB::table` del Escritorio (`Metrics`) filtran `deleted_at`. **Duplicados:** el documento sigue siendo único por organización contando los archivados, así que el alta (`RegisterStudent`: app, panel e importación) **restaura** al archivado con ese documento (y sus asistencias) en vez de crear otro; una inscripción archivada vuelve como nueva (desde hoy, con sus cargos). La unicidad alumno + categoría + temporada cuenta solo las vigentes (columna generada `not_deleted`), así el panel puede volver a inscribir en la misma categoría. |
| **Cargar alumno (admin)** | `POST students` con `Create:Student`: `RegisterStudent` en `activo` (igual que "Nuevo jugador", `mustBeNew`), tutor con celular obligatorio; si no usa la app, `CreateInvitation::forGuardian` y la app ofrece "Mandar por WhatsApp" (`wa.me`) y "Copiar el link". |
| **Ficha médica** | Cifrada en la solicitud, no la ve quien confirma, pasa a la ficha del alumno al confirmar (si no tenía) y se borra al rechazar o cancelar. |
| **Avisos** | `EnrollmentRequested` a quienes pueden confirmar en esa categoría; `EnrollmentRequestReviewed` al tutor (push + correo). |

## 3. App

- **Tutor:** "Inscribir a otro hijo" en "Mis hijos" y en "Mi cuenta" → `/hijos/inscribir` (documento obligatorio,
  `PlacePicker` con la sugerida y el cupo) → "Solicitud enviada: ya puede ir a las clases" o "Inscripción confirmada"
  si se confirmó sola. Sus solicitudes arriba de "Mis hijos" ("Por confirmar", cancelar; "No aprobada" con motivo).
- **Técnico (planilla `/clases/:id` y lista del mes `/grupos/:id`):** "Nuevo, por confirmar" y, si puede, "Confirmar inscripción" (si está completa
  pregunta "Inscribir igual") y "Rechazar" (motivo; pide guardar la asistencia antes).
- **Secretario y admin:** `/solicitudes` y la tarjeta del inicio (Confirmar con categoría, mitad de mes y cupo; Rechazar),
  con quién la revisó o si se confirmó sola.
- **Admin:** "Cargar alumno" (`/alumnos/nuevo`, botón del inicio con `create_students`) → "Alumno cargado" → "Mandar por
  WhatsApp", "Copiar el link", "Cargar otro".

## 4. API y panel

- `SubmitEnrollmentRequest` (alta pendiente con `RegisterStudent`, duplicados, se confirma sola),
  `ReviewEnrollmentRequest` (confirmar, rechazar, cancelar y deshacer), `EnrollmentRequestAccess` (quién confirma,
  opciones, cupo, mitad de período), `StudentRegistrationController` (`POST students`).
- `Enrollment`: una pendiente no genera cargos al crearse; al pasar de pendiente a activo se emite el cargo de
  inscripción (además de las cuotas). `ClassSession::enrollmentsQuery()` suma las pendientes con solicitud por confirmar.
- Panel: "Solicitudes de inscripción" (solo las que puede confirmar; Confirmar/Rechazar; quién revisó) y documento
  obligatorio en "Nuevo jugador".

## 5. Fuera de este alcance

- Link público, registro de la familia dentro del flujo, consentimiento y membresía nueva (**5e**).
- Actividades (`Offering`) y lista de espera.
- "Me inscribo yo" (alumno adulto) y varios hijos en una solicitud.
- Inscribir a un hijo que ya está en el club en otra disciplina desde su ficha (la API lo acepta; falta el botón).
- Foto del chico; editar los datos al confirmar; ficha médica en "Cargar alumno".

## 6. Posibles conflictos con otras sesiones

- **F4 (bajas, `feat/bajas-condonacion`):** las dos ramas tocan los hooks `created`/`updated` de `Enrollment` (acá:
  pendiente sin cargos y cargo de inscripción al confirmar; allá: emitir desde el mes en curso al reactivar). Son
  compatibles (pendiente → activo no es "volver de una baja"), pero el merge del bloque `updated` es manual.
- **`DefaultPermissions`** (secretario e instructor) y `config/filament-shield.php`: otras ramas pueden sumar permisos ahí.
- **Soft delete** de `Student`, `Enrollment` y `Attendance` (migración `2026_10_09_200003`, cambia el índice único de
  `enrollments`): afecta a toda la app. F4 (bajas) e "informes" (`Metrics`, reportes) tienen que contar con que un
  `delete()` ahora archiva y con filtrar `deleted_at` en consultas con `DB::table`.
- `GroupController::show` (F4 le suma "dejó de venir" en el mismo `map` de alumnos), `routes/api.php`,
  `CurrentOrganizationController`, `ClassSessionResource`, `Metrics`, `business-logic.md`, `API_V1.md`, los
  `CLAUDE.md`: conflictos de pocas líneas.
- **D2/D3 (vocabulario):** se usan `term('group')`, `term('guardian')` y `Terms::label`; sin cambios en `Templates`.

## 7. ¿Quién más confirma?

Hoy confirman el admin (siempre), el secretario y el prosecretario (en todas las categorías) y el técnico (en las suyas).
Análisis de cada rol o perfil para que el usuario decida (no se cambió nada más):

| Rol o perfil | Recomendación | Por qué |
|---|---|---|
| Administrador | Todas (ya) | Puede todo en su organización (`Gate::before`). |
| Presidente | **Todas** (sumar) | Ve todo y responde por el club; sirve de respaldo cuando el secretario no está, sobre todo en comisiones chicas. |
| Vicepresidente | **Todas** (sumar) | Reemplaza al presidente: mismo criterio. |
| Secretario | Todas (ya) | Lleva alumnos, tutores e inscripciones (`business-logic.md` §2). |
| Prosecretario | Todas (ya, confirmado) | Suple al secretario. |
| Tesorero | No | Su área es el dinero; confirmar es una decisión académica (cupo, categoría) aunque dispare las cuotas, que igual ve. |
| Protesorero | No | Mismo motivo que el tesorero. |
| Vocal | No | Lee y vota; no gestiona alumnos. |
| Síndico | No | Controla y no modifica nada: confirmar sería incompatible con su función. |
| Técnico / instructor | Solo sus categorías (ya) | Es quien ve al chico en la clase y conoce el cupo real del grupo. |
| Coordinador (rol creado a mano con "Tomar asistencia en cualquier grupo") | Todas, si existe | Gestiona todos los grupos; se le da "Confirmar inscripciones (todas)" desde Roles. |
| Profesor de clases particulares (perfil, no rol) | No | Sus alumnos particulares no usan categorías ni temporadas; si además es técnico, ya confirma en sus categorías. |
| Tutor | No | Es quien pide; si además tiene un cargo que confirma, su pedido se confirma solo. |
| Alumno adulto (perfil) | No | Pide para sí mismo ("Me inscribo yo", fuera de alcance); no decide sobre otros. |
| Super admin de la plataforma | Todas (ya, soporte) | Pasa por `Gate::before`; no es parte del club. |

Si el usuario acepta presidente y vicepresidente: sumar `Manage:EnrollmentRequests` en `DefaultPermissions` y una
migración para los roles existentes (como la de secretario).

## 8. Preguntas abiertas

- ⏳ ¿Presidente y vicepresidente también confirman (sección 7)?
- ⏳ "Borrar" del panel (alumno sin cobros o inscripción) ahora archiva: ¿hace falta una vista de archivados para
  restaurar a mano, o alcanza con que vuelvan al cargarlos de nuevo con el mismo documento?
