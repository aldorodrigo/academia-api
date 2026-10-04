# Vocabulario por deporte (D2 y D3 de la prueba integral)

Decisiones del usuario (2026-10-04):

- Primera ronda: cuando una academia (o escuela, o comisión) enseña un deporte, se le propone Categoría, Técnico y
  Cancha en vez de Grupo, Profesor y Sala, y el usuario decide cómo quedan.
- Segunda ronda: **cada deporte con lo suyo** (también Alumno → Jugador en los de equipo), la propuesta sin contestar
  **se recuerda en la guía** hasta que conteste, y Configuración → Vocabulario del panel usa las palabras reales de la
  organización con nombres que se entienden.

## Cuándo se sabe qué enseñan

El tipo se elige en "Tu club"; la disciplina, recién en el paso 1 de "Primeros pasos".

**Decisión: (a) se propone al guardar el paso 1**, no se pregunta en "Tu club".

- En "Tu club" todavía no se sabe qué enseñan: preguntar "¿es de deporte?" suma una pregunta a todos (también a
  danza, música o idiomas) y duplica lo que el paso 1 ya pregunta.
- En el paso 1 la disciplina ya está elegida: la propuesta le aparece solo a quien le sirve, con un toque, y justo
  antes del paso 2 ("Categorías y horarios"), que es donde las palabras empiezan a importar.
- Sirve igual para las organizaciones creadas desde el panel y para quien agrega una disciplina más tarde (mientras no
  haya decidido nada sobre el vocabulario).

### Cada deporte con lo suyo (`Templates::programTerminology`)

| Disciplina | Alumno | Profesor | Grupo | Lugar |
| --- | --- | --- | --- | --- |
| Fútbol, Futsal, Básquet, Vóley, Handball, Hockey, Rugby | Jugador | Técnico | Categoría | Cancha |
| Natación | Alumno | Profesor | Nivel | Pileta |
| Tenis, Pádel | Alumno | Profesor | Nivel | Cancha |
| Danza, Patín, Gimnasia, Artes marciales, Ajedrez, Música, Inglés y las que escriban | — (sin propuesta) | | | |

Se compara sin mayúsculas ni tildes y por la primera palabra: "Fútbol 7", "futbol infantil" o "Padel" cuentan.
"Tenis de mesa" no (no hay cancha).

### Regla (la decide la API, `App\Support\Onboarding\VocabularySuggestion`)

1. **Manda la primera disciplina elegida que tenga propuesta** (por orden de alta; en la app y el panel, el orden en
   que se eligieron). Las sin propuesta (Danza…) no cuentan. Si la primera ya tiene sus palabras (un club de fútbol
   que después suma natación), no hay propuesta: es la disciplina principal y no se le cambian las palabras por una
   secundaria.
2. Solo se proponen las palabras que siguen como vinieron con el tipo y son distintas de las de esa disciplina; lo que
   el usuario ya eligió no se toca. Vale para cualquier tipo: un club de natación recibe Alumno, Profesor, Nivel y
   Pileta; uno de fútbol, nada.
3. El vocabulario no se confirmó (`organizations.terminology_confirmed_at`). Se confirma al usar las propuestas o
   dejar como estaba, al guardar el vocabulario desde la app o desde Configuración del panel, y al crear la
   organización con palabras distintas de las del tipo (el usuario ya eligió en "Tu club").

### Cómo se propone y se recuerda

- **App:** al guardar el paso 1, si `GET onboarding` trae `terminology_suggestion`, una hoja "¿Cómo les dicen?" con
  las palabras propuestas ya elegidas (chips por palabra, con las opciones de siempre y "Otra…") y "Usar estas
  palabras", "Dejar como estaba (alumno, profesor, grupo y sala)" y "Después". Cerrarla (o "Después") no decide
  nada: sigue al paso 2 y la tarjeta "Configurá tu academia" del inicio muestra **"Elegí cómo les dicen"** hasta que
  conteste. Con la guía completa y la propuesta sin contestar queda solo esa tarjeta.
- **Panel:** lo mismo al guardar "¿Qué enseñan?": un modal con un campo por palabra (con sugerencias y libre),
  "Usar estas palabras", "Dejar como estaba" y "Después". La guía del Escritorio (completa o achicada) muestra
  "Elegí cómo les dicen" con el botón que abre el modal; con la guía completa queda solo ese recordatorio
  (`guideMode()` = `vocabulary`).

## Cómo se cambia después

- **Panel:** Configuración → "Vocabulario" (admin). Muestra las palabras que usan hoy las pantallas, con nombres que
  se entienden ("A los que aprenden", "A quienes enseñan", "A los responsables de cada alumno"…), ejemplos y
  sugerencias; vacía = la que se usa en ese tipo ("una academia"), no la de un club. Guardarlo confirma el vocabulario.
- **App:** "Cómo les dicen" en "Mi cuenta" (solo con `configure_organization`) → `/vocabulario`: una fila de chips
  por palabra (alumnos, profesores, grupos, lugares) con las opciones de `terminology_options` y "Otra…".

## Contrato de API

- `GET onboarding` suma `terminology_suggestion`: `null` o
  `{ "programs": ["Fútbol"], "current": { "student": "Alumno", "instructor": "Profesor", "group": "Grupo", "space": "Sala" },
  "suggested": { "student": "Jugador", "instructor": "Técnico", "group": "Categoría", "space": "Cancha" } }` (solo las
  palabras que se proponen cambiar; `programs` trae la disciplina que la origina).
- **Nuevo** `PUT organization/terminology` (permiso `configure_organization`):
  `{ "terminology": { "group": "Categoría", "instructor": "Técnico", "space": "Cancha" } }` →
  `{ "data": { "terminology": { …las seis, combinadas } } }`. Claves `program, group, student, instructor, guardian,
  space`; cada una hasta 30 caracteres; vacía = la del tipo. `{ "terminology": {} }` = "Dejar como estaba" (solo
  confirma). Siempre deja el vocabulario confirmado.
- `GET onboarding/templates`: `terminology_options` suma `program` y `guardian` (el ejemplo del doc ya trae `space`).
- La descripción del paso 1 dice "… que ofrece el club / la academia / la escuela / la comisión" según el tipo.

## Preguntas abiertas (sin implementar)

- Otras disciplinas con palabras propias posibles: artes marciales (Sensei, Dojo), patín (Pista), gimnasia
  (Gimnasio), danza (Profesora, Sala). Hoy no se propone nada.
- Organizaciones de varias disciplinas con palabras distintas (fútbol y natación): una sola palabra por concepto;
  no hay vocabulario por disciplina.

## Textos fijos a corregir

D3 (la prueba integral):

- Recibo PDF (`resources/views/receipts/show.blade.php`): "Jugador" → término `student`.
- Panel, ficha del alumno → pestaña Tutores: "Crear guardian" (y "Vincular guardian", "Editar guardian") → término
  `guardian` (`GuardiansRelationManager`, `modelLabel` de la tabla).
- Panel, formulario del alumno: "ficha del jugador", "Ya hay otro jugador con este documento.", "El jugador es menor
  de edad…" → término `student` (también el mensaje de `RegisterStudent`, que usa la importación).
- Informes → Morosos "Jugadores": **ya lo corrige la rama `informes-etapa-0`** (vista `reports.blade.php`,
  `DelinquentsReport` y `BalancesReport` usan `term()`). No se toca acá para no chocar.

D2 (la guía cuando no es club):

- "Configurá tu club" (inicio de la app, título de los pasos y Escritorio del panel) → "Configurá tu academia /
  escuela / comisión" según el tipo.
- "Configurar el club" en "Mi cuenta" → "Configurar la academia…".
- "Disciplinas que ofrece el club." (checklist) → según el tipo.

Misma clase (textos del panel o de la app que dicen "jugador", "categoría" o "técnico" fijo y son del vocabulario):

- Panel: "Jugador inscripto." (alta), temporada ("cuotas de cada jugador", "Los días con horario de la categoría",
  "para que el técnico pueda corregirla"), pase de temporada ("jugadores", "la categoría de cada uno"), "Pasar
  jugadores", tarifas ("una tarifa de la categoría"), monto a mitad de período ("para esta categoría"),
  Configuración → Asistencia ("Aviso al técnico").
- App: temporada por día ("Los días con horario de la categoría").

Fuera de alcance: "Tu club" / "Registrá tu club" antes de crear la organización (todavía no hay tipo), los textos para
familias que dicen "el club" y la portada del sitio.

## Conflictos esperados

- `informes-etapa-0` (sin commitear en el checkout principal) toca `docs/API_V1.md`, `ManagePayments.php` y las
  vistas de informes: acá solo se cambia `API_V1.md` en las secciones de Sprint 5d.
- `fix/crear-club-terminology` agrega `space` a la validación de `POST organizations`: no se toca acá.
