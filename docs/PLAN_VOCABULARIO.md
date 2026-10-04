# Vocabulario por deporte (D2 y D3 de la prueba integral)

Decisión del usuario (2026-10-04): cuando una academia (o escuela, o comisión) enseña un deporte, se le propone
**Categoría, Técnico y Cancha** en vez de Grupo, Profesor y Sala, y el usuario decide cómo quedan.

## Cuándo se sabe que es un deporte

El tipo se elige en "Tu club"; la disciplina, recién en el paso 1 de "Primeros pasos".

**Decisión: (a) se propone al guardar el paso 1**, no se pregunta en "Tu club".

- En "Tu club" todavía no se sabe qué enseñan: preguntar "¿es de deporte?" suma una pregunta a todos (también a
  danza, música o idiomas) y duplica lo que el paso 1 ya pregunta.
- En el paso 1 la disciplina ya está elegida: la propuesta le aparece solo a quien le sirve, con un toque, y justo
  antes del paso 2 ("Categorías y horarios"), que es donde las palabras empiezan a importar.
- Sirve igual para los clubes creados desde el panel y para quien agrega un deporte más tarde (mientras no haya
  decidido nada sobre el vocabulario).

### Regla (la decide la API, `App\Support\Onboarding\VocabularySuggestion`)

Hay propuesta si:

1. la organización tiene al menos una disciplina deportiva (`Templates::sports()`: los deportes de equipo que se
   arman por edad — Fútbol, Futsal, Básquet, Vóley, Handball, Hockey, Rugby —; se compara sin mayúsculas ni tildes
   y por la primera palabra, así "Fútbol 7" o "Futbol infantil" también cuentan);
2. alguna de las palabras `group`, `instructor`, `space` sigue siendo la que trae el tipo y es distinta de la de
   deporte (`Templates::sportTerminology()`: Categoría, Técnico, Cancha). Solo se proponen esas palabras: lo que el
   usuario ya eligió no se toca;
3. el vocabulario no se confirmó (`organizations.terminology_confirmed_at`). Se confirma al aceptar o rechazar la
   propuesta, al guardar el vocabulario desde la app o desde Configuración del panel, y al crear la organización con
   palabras distintas de las del tipo (el usuario ya eligió en "Tu club").

Un club nunca la ve (ya tiene esas palabras).

### Cómo se propone

- **App:** al guardar el paso 1, si `GET onboarding` trae `terminology_suggestion`, una hoja "¿Cómo les dicen?"
  con las palabras propuestas ya elegidas (chips por palabra, con las opciones de siempre y "Otra…") y dos botones:
  "Usar estas palabras" y "Dejar como estaba (grupo, profesor y sala)". No se cierra tocando afuera: hay que elegir.
  Después sigue al paso 2.
- **Panel:** lo mismo al guardar "¿Qué enseñan?" en la guía: un modal con un desplegable por palabra (con las
  opciones y lo que quiera escribir) y "Dejar como estaba".

## Cómo se cambia después

- **Panel:** ya existe Configuración → "Vocabulario" (admin). Se mantiene; guardarlo confirma el vocabulario.
- **App:** no había forma. Se suma "Cómo les dicen" en "Mi cuenta" (solo con `configure_organization`) →
  `/vocabulario`: una fila de chips por palabra (alumnos, profesores, grupos, lugares) con las opciones de
  `terminology_options` y "Otra…" para escribirla.

## Contrato de API

- `GET onboarding` suma `terminology_suggestion`: `null` o
  `{ "programs": ["Fútbol"], "current": { "group": "Grupo", "instructor": "Profesor", "space": "Sala" },
  "suggested": { "group": "Categoría", "instructor": "Técnico", "space": "Cancha" } }` (solo las palabras que se
  proponen cambiar).
- **Nuevo** `PUT organization/terminology` (permiso `configure_organization`):
  `{ "terminology": { "group": "Categoría", "instructor": "Técnico", "space": "Cancha" } }` →
  `{ "data": { "terminology": { …las seis, combinadas } } }`. Claves `program, group, student, instructor, guardian,
  space`; cada una hasta 30 caracteres; vacía = la del tipo. `{ "terminology": {} }` = "Dejar como estaba" (solo
  confirma). Siempre deja el vocabulario confirmado.
- `GET onboarding/templates`: sin cambios de forma (el ejemplo del doc se actualiza: ya traía `space`).
- La descripción del paso 1 dice "… que ofrece el club / la academia / la escuela / la comisión" según el tipo.

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
