# Concordancia de género en el vocabulario (rama `feat/genero-vocabulario`)

Decisión del usuario (2026-10-05): **"Palabra + persona"**.

1. Los textos concuerdan con el género de la palabra que eligió la organización (Categoría / Grupo, Clase, Aula…).
2. Dato **opcional** de género de las personas para nombrarlas bien ("Técnica", "Jugadora", "Tutora").
3. (Pedido del coordinador) "club" deja de estar fijo: "del club / de la academia / de la escuela / de la comisión".

## Una sola fuente de reglas

- **API:** `App\Support\Vocabulary` (y `App\Filament\Support\Terms` para el panel, que la usa con la organización
  activa). Género de la palabra (`m`, `f`, `c` = común), artículos y contracciones (`the`, `a`, `of`, `to`, con "el
  aula"), plural, formas de persona (`feminine`, `masculine`, `forPerson`, `forPeople`, `groupGender`) y `agree` para
  adjetivos de una persona concreta ("Jugadora inscripta").
- **App:** recibe el resultado en `GET organization` → `vocabulary` (plural, género, artículo, formas de persona y
  `organization`) y lo usa con `Word` (`lib/core/vocabulary/vocabulary.dart`). La app conserva una copia mínima de las
  reglas **solo** para palabras que todavía no están guardadas (la vista previa de "Tu club" y "Cómo les dicen") o si
  la API no manda `vocabulary`; los tests de las dos suites comparan los mismos casos.

### Reglas

- Se mira la primera palabra ("Grupo de entrenamiento" es masculina) y se conservan las mayúsculas.
- Femeninas: -a, -dad, -tad, -tud, -ión, -umbre y una lista (clase, sede, base…). Masculinas en -a: programa, día,
  tema, idioma… Masculinas en -ión: avión, camión.
- **Género común** (el/la atleta): Atleta, Guía, Profe, Coach, Responsable, Miembro, las terminadas en -ista (salvo
  pista, lista…) y en -nte (Estudiante, Docente). Concuerdan en masculino genérico; con una persona concreta, según
  ella ("la atleta").
- **"El aula"**: femeninas con a tónica (aula, área, agua, ala, arma, acta, hacha…) llevan "el"/"un" en singular; el
  resto de la concordancia sigue en femenino ("las aulas", "esta aula", "otra aula").
- Formas de persona: -o → -a, -or → -ora, -ín/-ón/-án/-és → -ina/-ona/-ana/-esa, más Padre → Madre, Presidente →
  Presidenta, Jefe → Jefa. Común: igual. La organización ajusta la femenina si la regla no alcanza
  (`organizations.terminology_feminine`).
- **Grupos de personas**: femenino solo si son **todas** mujeres; masculino genérico si hay algún varón; si no se sabe
  de nadie, la palabra de la organización ("Alumna" si la academia eligió "Alumna"). Se calcula solo donde ya están
  las personas a mano (recibo, resumen de técnicos de Primeros pasos, técnicos de un grupo en la ficha): es barato y
  no suma consultas. En listas genéricas ("Jugadores" como título) queda el plural de la palabra del club.

## El dato de género

| Quién | Dónde se guarda | Dónde se carga |
| --- | --- | --- |
| Alumno | `students.gender` | App: alta del admin y inscripción del tutor. Panel: alta/edición del alumno, importación (columna opcional `genero`: F/M, femenino/masculino, mujer/varón). Solicitud: `enrollment_requests.gender` (completa el del alumno si no tenía). |
| Personal (técnicos, comisión, admin) | `users.gender` | App: "Mi cuenta" (`PATCH me`). Invitación (app: paso Técnicos; panel: Invitaciones y Primeros pasos) en `invitations.gender`, que pasa a la cuenta al aceptarla si no tenía. Panel: Miembros → "Género". |
| Tutor | — (no se pregunta) | Se deduce del parentesco: Madre, Abuela, Tía → femenino; Padre, Abuelo, Tío → masculino; Tutor/a y Otro → sin especificar. Si el tutor eligió el suyo en "Mi cuenta", manda ese. |

- Valores: `female`, `male`, `null` (sin especificar). Enum `App\Enums\Gender`.
- Migración `2026_10_11_000001_add_gender_to_people_and_feminine_terms` (columnas nulas: no hace falta completar nada).
- Parentesco: se suman `abuela`, `tio`, `tia`; `abuelo` pasa a "Abuelo" (masculino) y `tutor` se muestra "Tutor/a".

## Dónde se nombra a una persona

- Invitación (app, correo, panel): "Te invitaron como Técnica" / "Tutora".
- Chips de rol en el inicio y Miembros del panel: `RoleAssignment::label()` con `User::genderIn($org)`; los cargos de
  comisión también (Presidenta, Tesorera, Secretaria, Síndica, Administradora; Vocal igual).
- Recibo PDF: la columna de los alumnos ("Jugadora" si son todas chicas).
- Panel: "Jugadora inscripta", "Le avisamos a la tutora" (solicitudes y comprobantes), "Activa/Inactiva" en Miembros,
  "Ya era técnica".
- App: ficha del hijo ("Técnica: Ana" / "Técnicos: Ana, Juan"), chips y la invitación.

## Textos corregidos

Ver el resumen del commit y la lista de la sesión. Criterio: todo texto que arma una frase con una palabra del
vocabulario usa `Vocabulary`/`Terms` (o `Word` en la app); los que tenían la palabra fija y concordancia ("Elegí la
categoría", "Le avisamos al tutor", "Agregar otro tutor") pasan a usar la del club.

Filament trae textos que no concuerdan ("Borrar :label seleccionados", "Todos :label"): se reemplazaron por formas
neutras en `lang/vendor/filament-*/es`.

### "club" fijo

Pasa a `Vocabulary::the/of($organization->typeNoun())` (API) y `organization` en `vocabulary` (app). Se deja "club" a
propósito:

- Antes de que exista la organización (alta autoservicio): "Registrá tu club", "Tu club", "Verificá tu cuenta para crear
  un club", "¿Te invitaron a un club?".
- Textos de marca y portada: "academias, clubes y escuelas".
- El slug técnico (`-club`).

## Contrato

`docs/API_V1.md` → "Género y concordancia".

## Preguntas abiertas

- `abuelo` antes era "Abuelo/a": las abuelas cargadas así figuran ahora como "Abuelo" (masculino). ¿Migrar a mano los
  del piloto o volver a "Abuelo/a" sin género?
- El género del personal es de la persona (vale en todas sus organizaciones) y un admin lo puede cambiar desde Miembros.
  ¿Debería ser solo de la persona ("Mi cuenta")?
- ¿Mostrar el género en listas (alumnos del panel, planilla de asistencia) o solo usarlo para nombrar?
- Palabras de género común en plural mixto: "Atletas" sirve para todos; ¿hace falta "Responsables" vs "Encargadas"?
- Las formas masculinas no se pueden ajustar (solo la femenina): si una academia usa "Alumna" y tiene un varón sale
  "Alumno" por regla.
