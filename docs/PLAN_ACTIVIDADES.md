# Plan — Actividades para tus hijos

> Plan de una funcionalidad. Entra en la hoja de ruta de `PLAN.md` (§3.13 y §7). Al implementarse,
> las reglas pasan a `business-logic.md` y los endpoints a `API_V1.md` (contrato primero, app primero).

**Pedido (27/09/2026):** en la app del tutor, un panel con las actividades que sus hijos pueden hacer.
Cada actividad pertenece a una organización (academia, club, escuela).

---

## 1. Idea

- El tutor ve **qué puede hacer cada hijo**: actividades publicadas por organizaciones, filtradas por la edad
  de sus hijos, con días y horarios, sede, costo y cupo.
- Desde la actividad pide la inscripción ("Quiero inscribir a Sofía"). La organización la aprueba en el panel
  y el chico queda inscripto con el circuito que ya existe (alumno, tutor, inscripción y cuotas).
- Para la organización es un canal para llenar cupos: otra disciplina, la colonia de verano, un curso o una clínica.

```
Organización publica → el tutor la ve (filtrada por edad) → pide inscripción → la organización aprueba
→ alumno + inscripción + cuotas del plan → push al tutor
```

Ejemplos: la colonia de verano de Jakare para el hermano que no juega al fútbol; "Pádel Inicial, 8 a 12 años,
mar y jue 18:00"; una academia de danza del mismo barrio (etapa C).

## 2. Etapas

| Etapa | Qué ve el tutor | Qué implica | Cuándo (propuesta) |
|---|---|---|---|
| **A. Actividades de mis organizaciones** | Lo que publican las organizaciones donde ya está | Entidad nueva por organización; todo con `X-Organization` | Fase 2 |
| **B. Solicitud de inscripción** | "Quiero inscribirlo" y el estado de la solicitud | Solicitud con aprobación; al aprobar se reutiliza `RegisterStudent` | Fase 2, junto con A (reemplaza "solicitud de inscripción online") |
| **C. Catálogo entre organizaciones** | Actividades de cualquier organización del SaaS que publique en el catálogo, por zona | Endpoints de plataforma sin `X-Organization`, perfil público, hijos a nivel usuario, consentimiento | Fase 4 (SaaS comercial), cuando haya varias organizaciones |
| **D. Después** | Clase de prueba, pago online de la inscripción, favoritos, aviso de actividades nuevas | Pasarela (Fase 4) y avisos segmentados por edad | Después de C |

**Propuesta: empezar por A + B.** Ya sirve en el piloto (Jakare con varias disciplinas y colonias), no toca el
aislamiento entre organizaciones y deja cargados los datos que el catálogo va a necesitar.

## 3. Conceptos

- **Actividad** — en el código `Offering` / `offerings`; en pantalla, "Actividades". Se evita `Activity`
  porque choca con el modelo de spatie/activitylog y con las "otras actividades" de recaudación (§3.8 de `PLAN.md`).
  - Organización, título, descripción y fotos (medialibrary).
  - Se arma sobre lo que ya existe: **temporada + disciplina + categorías**. Una colonia o un curso es una
    temporada corta con su plan de cobro.
  - Edades: salen de las categorías (`min_age`/`max_age`); con criterio por nivel, el nivel.
  - Días, horarios y sede: de los horarios de las categorías.
  - Costo: resumen del plan de cobro de la temporada ("Inscripción ₲ 100.000 + ₲ 150.000 por mes"),
    o texto manual si no tiene plan.
  - Cupo: capacidad de las categorías − inscripciones activas → "Últimos lugares", "Completo", lista de espera.
  - Ventana de inscripción (desde / hasta) y estado: `borrador`, `publicada`, `cerrada`.
  - Visibilidad: `familias` (solo quienes ya están en la organización) o `catálogo` (etapa C, si la
    plataforma le habilita el módulo `catalog`).
- **Solicitud de inscripción** (`EnrollmentRequest`) — usuario tutor + hijo + actividad + categoría sugerida.
  Estados: `pendiente → aprobada | rechazada (con motivo) | en espera | cancelada`.
- **Perfil público de la organización** (C) — descripción, logo, ciudad/barrio, dirección y ubicación,
  contacto (teléfono, WhatsApp) y redes.
- **Hijo del usuario** (C) — hoy un chico es un `Student` por organización (el mismo chico en dos organizaciones
  son dos registros). Para ver actividades de otras organizaciones, el tutor necesita a sus hijos a nivel usuario
  (nombre y fecha de nacimiento), vinculados a los `Student` de cada organización. Se arma con los hijos que ya
  tiene y el tutor puede agregar uno nuevo.

## 4. Reglas de negocio (propuestas)

1. Solo aparecen actividades publicadas, dentro de su ventana de inscripción, de organizaciones activas (no suspendidas).
2. **Para quién es:** la API calcula qué hijos entran, con el mismo criterio que las categorías (edad que se cumple
   en el año de la temporada, como `Group::suggestFor`). Con criterio por nivel se muestra "El nivel se define en la
   primera clase". La app no calcula edades ni elegibilidad.
3. Un hijo que ya está inscripto en esa disciplina y temporada ve "Ya está inscripto", no la sugerencia.
4. Una sola solicitud pendiente por hijo y actividad; el tutor la puede cancelar mientras está pendiente.
5. **La solicitud no genera cargos.** Al aprobarla, en una sola operación e idempotente:
   - alumno (se reutiliza si ya existe, por documento o por nombre + fecha de nacimiento);
   - tutor vinculado a su usuario, sin invitación; membresía activa si era nuevo en la organización;
   - inscripción en la categoría sugerida (se puede cambiar al aprobar) y cuotas según el plan de la temporada;
   - push al tutor; si la organización era nueva para él, aparece en su lista.
6. **Cupo:** aprobar por encima del cupo pide confirmación; con la actividad completa, las solicitudes nuevas quedan
   "en espera" por orden de llegada.
7. **Quién aprueba:** permiso nuevo "Gestionar solicitudes de inscripción"; al llegar una solicitud se avisa a quienes lo tienen.
8. **Datos de menores (C):** una organización ve datos de un chico recién cuando el tutor le manda una solicitud, con
   consentimiento explícito que queda registrado (qué datos, a quién y cuándo). La edad de los hijos solo se usa en la
   plataforma para filtrar; nunca llega a organizaciones a las que no se mandó solicitud. La ficha médica no viaja en la solicitud.
9. **Moderación (C):** la plataforma puede ocultar una actividad del catálogo, con motivo.

## 5. App (primero)

- **Inicio del tutor:** tarjeta "Actividades para tus hijos" ("3 para Sofía, 1 para Mateo"), solo si hay alguna.
- **`/actividades`:** chips por hijo ("Para Sofía (8)", "Para Mateo (11)", "Todas") y después disciplina y día.
  Cada tarjeta: organización (logo y nombre, en C), actividad, edades, días y hora, sede, costo por mes y estado
  ("Inscripción abierta hasta el 15/10", "Últimos lugares", "Completo", "Empieza el …").
- **`/actividades/:id`:** fotos, descripción, horarios, sede ("Cómo llegar", abre mapas con `urlLauncherProvider`),
  costo en palabras (inscripción + cuotas), cupo, contacto por WhatsApp y **"Quiero inscribir a …"** → elegir hijos →
  confirmar (en C, con el texto de consentimiento) → "Solicitud enviada".
- **Mis solicitudes:** pestaña en `/actividades` y sección en la ficha del hijo, con estado y motivo si se rechazó.
- **Alumno adulto:** ve las actividades "Para vos" (a confirmar).
- **Etapa C:** `/actividades` deja de depender de la organización activa: se abre con sesión aunque no haya
  organización elegida (excepción en `sessionRedirect`, como `/invitacion`) y sus endpoints van sin `X-Organization`.
  "Agregar hijo" (nombre y fecha de nacimiento) para los que todavía no están en ninguna organización.
- **Código:** `lib/features/activities/` (data + presentation), `ActivitiesRepository(Dio, SessionStorage)`.
  Tests: repositorio con `fakeDio`, filtro por hijo, estados de la tarjeta, validación de la solicitud y (C) la
  redirección de `/actividades` sin organización.

## 6. API y panel (después)

- **Contrato (borrador; pasa a `API_V1.md` al arrancar el sprint):**
  - A + B, con `X-Organization`:
    - `GET offerings` — por actividad, `eligible_students` y `enrolled_students` (ids de sus hijos), cupo y estado.
    - `GET offerings/{id}`
    - `POST offerings/{id}/requests` `{student_ids, notes}`
    - `GET enrollment-requests` · `DELETE enrollment-requests/{id}` (cancelar)
    - `manage_enrollment_requests` en `membership.permissions` de `GET organization`.
  - C, sin `X-Organization`:
    - `GET catalog/offerings?child=&program=&city=` · `GET catalog/offerings/{id}`
    - `GET catalog/organizations/{slug}` (perfil público)
    - `POST catalog/offerings/{id}/requests` `{child_ids, consent: true}`
    - `GET/POST/PUT me/children`
- **Panel:**
  - "Actividades" en Académico: crear a partir de una temporada y disciplina (trae categorías, edades, horarios, sede
    y costo del plan; se ajusta lo que haga falta), fotos, publicar / cerrar y vista previa "así la ve el tutor".
  - "Solicitudes": filtro por estado; aprobar con la categoría sugerida y el efecto en el cupo y en las cuotas (como el
    asistente de inscripción); rechazar con motivo; lista de espera.
  - Al publicar una actividad, aviso opcional a las familias con hijos en edad (con los avisos segmentados del Sprint 5b).
  - C: perfil público en Configuración; `/plataforma` habilita el módulo `catalog` y puede ocultar actividades.
- **Tests (Pest):** aislamiento (lo de visibilidad `familias` no sale del inquilino; el catálogo sin `X-Organization`
  expone solo lo publicado y nada de alumnos), elegibilidad por edad, aprobación idempotente (no duplica alumno,
  tutor ni inscripción), cupo y lista de espera, consentimiento registrado.

## 7. Qué cuidar desde ya

Aunque se construya en Fase 2 y Fase 4, afecta lo que se haga antes:

- **Categorías y temporadas son la fuente de la oferta.** Mantener completos edades, nivel, cupo, horarios, sede y
  plan de cobro; no duplicar esos datos en otras entidades.
- **Un solo camino de alta de alumno:** `RegisterStudent` + inscribir (lo usan el panel y la importación; lo va a usar
  la aprobación de solicitudes). No crear otro.
- **La app no debe suponer que todo es de la organización activa.** Hoy `ApiClient` manda `X-Organization` siempre y
  `sessionRedirect` exige organización elegida; lo que sea de plataforma (catálogo, hijos del usuario) va a necesitar
  pedidos sin ese header y rutas fuera de la organización.
- **Hijos a nivel usuario:** "Mis hijos" hoy es por organización. No atar funcionalidades nuevas a que un chico está en
  una sola organización (ej. el mismo hijo en Jakare y en una academia de danza).
- **Avisos segmentados (5b):** prever segmentar por edad y por "familias sin inscripción en tal disciplina".
- **Eventos (Fase 2):** clínicas y torneos abiertos podrían publicarse como actividad; diseñar `Event` con eso en mente.
- **Registro abierto:** hoy el alta es solo por invitación; el catálogo lo va a necesitar para tutores que llegan sin invitación.
- **Nombres:** `Offering` en el código y "Actividades" en pantalla; conviene llamar "eventos de recaudación" a las
  actividades de §3.8 (cantina, pollada, festival).

## 8. Decisiones pendientes

- ⏳ ¿Arrancar por A + B (dentro de las organizaciones del tutor) o ir directo al catálogo entre organizaciones?
- ⏳ Qué hace el botón de la actividad: solicitud con aprobación (propuesta), contacto directo por WhatsApp o clase de prueba.
- ⏳ Registro abierto para tutores que llegan por el catálogo.
- ⏳ Modelo comercial del catálogo: incluido en el plan, módulo pago o actividades destacadas.
- ⏳ Ubicación: ciudad/barrio (simple) o mapa con distancia (pide permiso de ubicación).
- ⏳ ¿El alumno adulto también ve actividades para sí mismo? (propuesta: sí, "Para vos").
