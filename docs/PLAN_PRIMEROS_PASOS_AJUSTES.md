# Primeros pasos: ajustes de la prueba integral (E3, E6 y detalles)

Tercera ronda de la rama `feat/vocabulario-por-deporte` (app y API). Regla del proyecto: **siempre soft delete**.

## E3 · El paso 2 se pierde si se sale antes de crear

Decisión: **borrador en el servidor**, el mismo para la app y el panel.

- Tabla `onboarding_drafts` (organización, paso, `draft` JSON, quién, `deleted_at`). Uno vigente por paso; al crear
  las categorías (`SaveGroups`, app y panel) o al vaciarlo queda como usado (**soft delete**, nunca se borra).
- `GET/PUT/DELETE onboarding/steps/groups/draft` (permiso `configure_organization`; solo la clave `groups`).
  Formato: `{ program_id, ages, levels, capacity, groups: [{ name, min_age, max_age, level, slots: [...] }] }`;
  la API guarda solo lo que el paso entiende (`StepDrafts::normalizeGroups`).
- **App:** `GroupsStepController` guarda cada cambio (lista, horarios, cupo, edades) con una pausa de 800 ms y lo
  pendiente al salir de la pantalla; al abrir, retoma el borrador si su disciplina sigue existiendo (si no, sugiere
  como antes).
- **Panel:** "Categorías y horarios" arranca con el borrador (de la app o del panel) y guarda ahí cada cambio que
  llega del navegador (`updatedMountedActions`).

## E6 · Vencimiento a mitad de período

Se mantiene el plazo (`max(día de vencimiento, inscripción + due_days)`). La vista previa (app y asistente del panel)
muestra la fecha real para quien se inscribe hoy en el período en curso con `due_note` "para los que se inscriben
hoy". Una sola regla: `BillingPeriod::dueOnFor()`, que usan `IssueSeasonCharges` y `SeasonPlan::examples()`.

## Detalles

- **D1** "Yo también doy clases": arranca sin categorías y pide elegir al menos una (app: no guarda hasta elegir la
  primera ni deja sacar la última; panel: requerido; API: `422` con `teaches: true` sin `group_ids`).
- **D4** Plurales armados a mano: `countOf()` en la app (Morosos "1 familia", clases del mes "1 ausente", "1 día
  después", paquetes "1 clase") y `Vocabulary::count()` en el panel (cuotas en cola, becas, "Esperá 1 segundo",
  reinscribir, "Pasamos al jugador", "la cuota" en la temporada). El encabezado de Morosos del panel
  (`reports.blade.php`, "1 familias") es de `informes-etapa-0`: queda anotado para esa rama.
- **D5** "Temporada Temporada 2026": la app y el panel no anteponen "Temporada" si el nombre ya lo trae
  (`seasonLabel()`, `Vocabulary::season()`); el nombre sugerido de una anual es "Temporada 2026" y en la app el campo
  se actualiza cuando cambia la sugerencia.
- **D7** Panel: importar acepta `.xlsx` y `.csv` (`SpreadsheetImportAction`, primera hoja con OpenSpout; el ejemplo
  se descarga en Excel); voseo en los textos de Filament con overrides en `lang/vendor/*/es` (solo las claves que
  cambian); marca "Tuku" con el logo de `public/brand` sin depender de `APP_NAME`; "Roles y permisos" dentro de
  "Personas"; "Gastos recurrentes" (mayúscula solo al principio, `SentenceCaseLabels`).
- Otras disciplinas (artes marciales, patín, gimnasia, danza): sin propuesta de vocabulario por ahora.

## Soft delete (regla del proyecto)

Revisado lo agregado en la rama: el único registro nuevo que se "borra" es el borrador del paso 2, con `SoftDeletes`
(no aparece en ninguna lista ni informe). Vocabulario (`terminology`, `terminology_confirmed_at`) solo actualiza.
