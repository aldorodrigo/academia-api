# Plan — El tutor inscribe a su hijo desde la app

> Plan de una funcionalidad. Es un **subconjunto de la etapa B de `PLAN_ACTIVIDADES.md`** (solicitud de inscripción
> con aprobación) y la **primera pieza del Sprint 5e** ("Inscripciones por link", `PLAN_PRIMEROS_PASOS.md` etapa 2):
> la solicitud, la aprobación y la lista de solicitudes del club. No incluye la página pública ni las actividades.
> Al implementarse, las reglas pasan a `business-logic.md` y los endpoints a `API_V1.md` (contrato primero, app primero).
> `PLAN.md` lo integra el usuario.

**Pedido (04/10/2026):** "permite a los tutores inscribir alumnos desde la app". Viene del hallazgo **F2** de la prueba
integral: los alumnos solo se cargan desde el panel (importar CSV o "Crear alumno"); el fundador que arranca desde el
celular tiene que ir a la compu, y la familia que ya está en el club no puede sumar al hermano sin pedírselo a alguien.

---

## 1. Idea

```
Tutor (ya en el club) → "Inscribir a otro hijo" → datos del hijo + categoría sugerida por la API (+ ficha médica opcional)
→ "Solicitud enviada" (pendiente, no genera cargos) → push a quienes aprueban
Club (panel o app) → aprueba (confirma o cambia la categoría; mitad de mes; cupo) o rechaza con motivo
→ al aprobar: RegisterStudent (alumno + tutor vinculado + familia + inscripción + cuotas) → push al tutor
```

## 2. Alcance y decisiones

| Tema | Decisión |
|---|---|
| **Quién pide** | Cualquier usuario con **membresía activa** en la organización (tutor invitado, técnico o admin con hijos). Va con `X-Organization`. |
| **Link público** (quien todavía no es tutor) | **Fuera: queda para 5e.** Necesita la página pública del club, crear la cuenta dentro del flujo, consentimiento, la excepción en `sessionRedirect`, la membresía al aprobar y las actividades (`Offering`) para elegir qué se ofrece. Esta entrega deja hecho lo que 5e reutiliza: la solicitud (`EnrollmentRequest`), la aprobación (`ReviewEnrollmentRequest`, sobre `RegisterStudent`), los avisos y la lista de solicitudes (panel y app). 5e le suma `offering_id`, el origen (`app` / `link`) y la membresía nueva. |
| **Qué carga** | Nombre, apellido, fecha de nacimiento (obligatorios), documento (opcional, recomendado: evita duplicados), parentesco, disciplina y categoría, y notas. **Ficha médica opcional** (grupo sanguíneo, alergias, enfermedades, medicación, contacto de emergencia). |
| **Categoría** | La sugiere la API por año de nacimiento (`Group::suggestFor`, solo disciplinas por año); el tutor puede elegir otra de la disciplina. Con criterio por nivel no hay sugerencia ("El nivel lo define el club"). La app no calcula edades ni categorías. |
| **Temporada** | Las vigentes o próximas de la disciplina (`Season::open()->forProgram()`); una opción por disciplina × temporada. Una próxima se muestra "Empieza el …". |
| **Modelo** | Entidad nueva **`EnrollmentRequest`** (no una `Enrollment` en estado `pendiente`): el chico no existe como alumno hasta que se aprueba, la solicitud rechazada no deja alumnos sueltos, no toca los estados de `Enrollment` (que está cambiando F4) y es la entidad que ya prevé `PLAN_ACTIVIDADES.md` §3. |
| **Estados** | `pendiente` ("En revisión") → `aprobada` \| `rechazada` (con motivo) \| `cancelada` (la retira el tutor mientras está pendiente). "En espera" (lista de espera) queda para 5e, con el cupo de las actividades. |
| **Quién aprueba** | Permiso nuevo **"Gestionar solicitudes de inscripción"** (`Manage:EnrollmentRequests`) o quien ya puede **crear inscripciones** (`Create:Enrollment`: secretario y prosecretario por defecto); el admin por `Gate::before`. En la app: `manage_enrollment_requests` en `membership.permissions`. Sin tocar `DefaultPermissions`. |
| **Al aprobar** | Una sola operación, con lock (dos personas no aprueban la misma): `RegisterStudent` (se reutiliza el alumno si ya existe por documento o nombre + nacimiento; el tutor se vincula **a su usuario** sin invitación; familia automática, así queda con los hermanos), inscripción `activo` en la categoría elegida, **cuotas según el plan de la temporada** (`IssueSeasonCharges` al crear la inscripción) con **`MidPeriod`** elegido por quien aprueba (por defecto el del plan; solo se pregunta si el período en curso ya empezó). Ficha médica: se crea si el alumno no tenía (no pisa una existente). Si el usuario no tenía el rol `tutor`, se le asigna (`RoleAssigner`). |
| **Cupo** | Categoría con `capacity`: ocupado = inscripciones `activo`, `becado` o `pendiente` de la temporada. El tutor ve "Quedan 3 lugares" / "Completo: el club decide si hay lugar" y puede pedir igual. Aprobar por encima del cupo pide confirmación (`over_capacity`). |
| **Duplicados** | Una sola solicitud pendiente por chico (documento, o nombre + apellido + nacimiento) y usuario. Si ese chico ya es su hijo y está inscripto en la disciplina y temporada → "Sofía ya tiene inscripción en Fútbol (2026).". Si existe en el club pero no a su cargo, quien aprueba lo ve ("Ya está cargado: Sofía Benítez, tutores: …") antes de aprobar: aprobar lo vincula. |
| **Avisos** | `EnrollmentRequested` a quienes aprueban (push + correo, `route: /solicitudes`); `EnrollmentRequestReviewed` al tutor (aprobada → `route: /hijos/{id}`; no aprobada → motivo, `route: /hijos`). Extienden `PushNotification`. |
| **Datos sensibles** | La ficha médica de la solicitud se guarda **cifrada** y **no se muestra** a quien aprueba (solo "Cargó la ficha médica"): pasa a `MedicalRecord`, donde rige `StudentPolicy::viewMedical`. Al rechazar o cancelar se borra. (La regla 8 de `PLAN_ACTIVIDADES.md`, "la ficha médica no viaja en la solicitud", es para el catálogo entre organizaciones de la etapa C; acá el tutor ya es del club.) |

## 3. Reglas de negocio (pasan a `business-logic.md` §3)

1. Un miembro activo pide la inscripción de un hijo: datos del chico, disciplina, temporada vigente o próxima y
   categoría activa de esa disciplina. La solicitud **no genera cargos** ni crea alumnos.
2. Una sola solicitud pendiente por chico y usuario; si el chico ya está a su cargo e inscripto en esa disciplina y
   temporada, no se puede pedir.
3. Mientras está pendiente el tutor la puede cancelar. Revisada (aprobada, rechazada o cancelada) no cambia más.
4. Aprueba quien tiene "Gestionar solicitudes de inscripción" o puede crear inscripciones. Puede cambiar la categoría
   (de la misma disciplina) y qué se cobra del período en curso; por encima del cupo confirma. La temporada tiene que
   seguir abierta.
5. Al aprobar: alumno (reutilizado si existe), tutor vinculado al usuario, familia, inscripción `activo` y cuotas del
   plan, en una sola operación. Rechazar pide motivo. En los dos casos se avisa al tutor.

## 4. Contrato (`API_V1.md`, sección «Inscripción desde la app»)

Con token + `X-Organization`.

- Tutor: `GET enrollment-requests/options?birth_date=` (disciplinas, temporadas, categorías con sugerencia y cupo) ·
  `POST enrollment-requests` · `GET enrollment-requests` (las suyas: pendientes y las no aprobadas de los últimos 30
  días) · `DELETE enrollment-requests/{id}` (cancelar).
- Quien aprueba: `GET enrollment-requests/review?status=pendiente|todos` · `POST enrollment-requests/{id}/approve`
  `{group_id?, mid_period?, over_capacity?}` · `POST enrollment-requests/{id}/reject` `{reason}`.
- `GET organization`: `manage_enrollment_requests` en `membership.permissions`.
- Coincide con el borrador de `PLAN_ACTIVIDADES.md` §6 (`GET enrollment-requests`, `DELETE enrollment-requests/{id}`,
  `manage_enrollment_requests`); 5e agrega `POST offerings/{id}/requests`, que crea el mismo objeto.

## 5. App (primero)

- **`lib/features/enrollment/`** (el nombre que prevé `PLAN_PRIMEROS_PASOS.md` §6 para 5e):
  `EnrollmentRepository(Dio, SessionStorage)`, modelos, validaciones y pantallas.
- **"Mis hijos"** (inicio y `/hijos`): botón **"Inscribir a otro hijo"** (o "Inscribir a mi hijo" si no tiene) y,
  arriba de los hijos, las solicitudes propias ("Sofía Benítez · Sub-8 · En revisión", con "Cancelar"; las no
  aprobadas con el motivo). También desde "Mi cuenta" → "Inscribir a un hijo" para quien no es tutor.
- **`/hijos/inscribir`:** una columna: nombre, apellido, fecha de nacimiento, documento, parentesco (chips) → con la
  fecha, la API trae las opciones: disciplina (si hay varias), temporada (si hay varias), categoría con la sugerida
  marcada y el cupo → ficha médica (plegada, opcional) → notas → "Enviar solicitud" → "Solicitud enviada. Te avisamos
  cuando el club la apruebe." y vuelve al inicio.
- **Quien aprueba:** `EnrollmentRequestsCard` en el inicio ("2 para revisar"), botón "Solicitudes" en
  `QuickActionsBar` y **`/solicitudes`** ("Para revisar" / "Todas"): por solicitud, el chico (edad, documento), quién
  la pidió, la categoría pedida, el cupo y si ya está cargado. "Aprobar" (categoría, mitad de mes si aplica,
  confirmación de cupo) y "Rechazar" (motivo).
- **Tests:** repositorio con `fakeDio`, modelos, validaciones del formulario, flujo de la solicitud, tarjeta y
  pantalla de quien aprueba (aprobar con cupo lleno pide confirmación, rechazar pide motivo), botones del inicio,
  ruta `/hijos/inscribir`.

## 6. API y panel (después)

- `EnrollmentRequest` (`enrollment_requests`, `BelongsToOrganization`, registro de actividad), enum
  `EnrollmentRequestStatus`, `EnrollmentRequestAccess` (quién aprueba, a quién se avisa, opciones y cupo),
  `SubmitEnrollmentRequest`, `ReviewEnrollmentRequest`, `EnrollmentRequestController`, recursos y avisos.
- `RegisterStudent`: los tutores aceptan `user_id` (se busca primero por usuario y queda vinculado). Es el único
  cambio en el alta; no se duplica lógica.
- `MidPeriodPreview`: se extrae el cálculo del período en curso para usarlo fuera del formulario de Filament.
- **Panel:** "Solicitudes de inscripción" en Académico (filtro por estado, en revisión por defecto, contador en el
  menú), con "Aprobar" (categoría sugerida, mitad de mes, aviso de cupo con confirmación) y "Rechazar" (motivo).
  Permiso en Shield: "Gestionar solicitudes de inscripción".
- **Tests (Pest):** pedir (validaciones, duplicados, opciones con sugerencia y cupo, aviso a quienes aprueban),
  cancelar, aprobar (alumno + tutor vinculado + familia + inscripción + cuotas con `MidPeriod`, reutiliza el alumno,
  ficha médica, rol tutor, cupo, idempotente: dos aprobaciones → 422), rechazar, permisos, aislamiento entre
  organizaciones y el panel.

## 7. Fuera de este alcance

- Link público del club, registro de la familia dentro del flujo, consentimiento y membresía nueva al aprobar (**5e**).
- Actividades (`Offering`), lista de espera "en espera" y cupo por actividad (**5e / PLAN_ACTIVIDADES A y B**).
- "Me inscribo yo" (alumno adulto) y varios hijos en una sola solicitud (la app manda una por hijo).
- Inscribir a un hijo que ya está en el club en **otra disciplina** desde su ficha (la API ya lo acepta: el alumno se
  reutiliza; falta el botón en la ficha).
- Foto del chico, editar los datos al aprobar (se rechaza con motivo y el tutor la manda de nuevo), pago online de la
  inscripción.
- Que el admin **cargue alumnos de otras familias** desde la app (la otra mitad de F2: `RegisterStudent` con los datos
  del tutor e invitación). Ver preguntas abiertas.

## 8. Posibles conflictos con otras sesiones

- **F4 (bajas y condonación):** toca estados de inscripción y bajas. Acá no se cambia `Enrollment` ni
  `EnrollmentStatus`; `RegisterStudent::enroll()` reactiva una inscripción existente del mismo alumno, categoría y
  temporada (comportamiento de siempre). Si F4 cambia cómo se reactiva una baja, revisar la aprobación.
- **D2/D3 (vocabulario):** los textos usan `term('group')`/`Terms::label` donde ya existen; ningún cambio en `Templates`.
- **F1 (cobro en efectivo):** sin superposición.
- `CurrentOrganizationController` (permisos) y `routes/api.php` los tocan varias ramas: conflictos de una línea.

## 9. Preguntas abiertas

- ⏳ ¿El admin también tiene que poder **cargar alumnos de otras familias desde la app** (la otra mitad de F2)?
- ⏳ ¿Una solicitud de quien también puede aprobar se aprueba sola? (hoy queda pendiente y la aprueba él mismo).
- ⏳ ¿Documento obligatorio? (hoy opcional; sin documento el duplicado se detecta por nombre + nacimiento).
- ⏳ ¿El secretario aprueba por defecto (vía "crear inscripciones") o solo el admin y a quien se le dé el permiso?
