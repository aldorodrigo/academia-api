# Plan — Bajas y condonación de deudas

> Nace del hallazgo **F4** de la prueba integral (2026-10-04): la baja era solo cambiar el estado de la inscripción a
> "Baja" en el panel. Una baja a principio de mes (Matías Zárate, 3/6) dejaba la cuota del mes ya emitida (1/6) y las
> anteriores impagas, y en "Morosos" (app, panel y PDF/Excel) no se veía que el alumno ya se había ido.
>
> Decisiones del usuario:
> - **Primera ronda:** la deuda queda pendiente como histórica y se condona según el administrador o quien tenga el
>   permiso. La cuota del mes en curso queda pendiente hasta que el administrador la anule, el chico vuelva y la pague,
>   o se le condone.
> - **Segunda ronda (2026-10-04):**
>   - Condonan por defecto también el **tesorero y el presidente**, y el permiso se agrega o quita por rol.
>   - La condonación se puede **deshacer**, con registro de quién, cuándo y por qué.
>   - Al dar de baja se **pregunta si se avisa a la familia**, con un mensaje amable "con las puertas abiertas",
>     prellenado y editable, por push y correo.
>   - El **tutor avisa desde la app** que su hijo deja el club.
>   - **Baja y condonación también desde la app** para quien tenga el permiso.
>
> Reglas implementadas: `business-logic.md` §3 (Bajas) y §5. Contrato: `docs/API_V1.md` → "Bajas y condonación".

## 1. Cómo se da la baja

**En el panel**, con la acción "Dar de baja":
- en **Inscripciones**, por fila o seleccionando varias;
- en la **ficha del jugador → Inscripciones**.

**En la app** (permiso `withdraw_students`), en la ficha del alumno `/alumnos/:id`. Se llega desde:
- "Avisos de baja" (`/bajas`, tarjeta en el inicio);
- el menú del alumno en Mis grupos ("Ver ficha");
- la marca "baja" en Morosos o Saldos.

| Campo | Regla |
|---|---|
| **Fecha de baja** | Hoy por defecto. Entre la fecha de inscripción y hoy (no hay bajas a futuro). |
| **Motivo** | Obligatorio, texto libre. Si hubo aviso, viene precargado con su nota. |
| **Avisar a la familia** | Prendido si hay tutores con la app (o el alumno adulto con cuenta). El mensaje viene prellenado (`WithdrawEnrollment::defaultNotice`, amable y con las puertas abiertas, sin hablar de plata) y se puede cambiar en el momento. Sale por push y correo (`StudentWithdrawn`). Sin tutores con la app, se sugiere avisar por WhatsApp a mano. Al dar de baja varias a la vez, se puede mandar el mensaje sugerido sin cambiarlo. |

Antes de confirmar, el panel muestra el efecto en dinero: cuántas cuotas quedan pendientes y por cuánto (también la
del período en curso) y cuántas cuotas futuras se anulan. La app lo resume en una línea.

Al confirmar (`App\Actions\Enrollments\WithdrawEnrollment`):
- la inscripción queda en estado `baja`, con `ended_on`, `withdrawal_reason` y `withdrawn_by`;
- se anulan las cuotas futuras sin pagos;
- se cierra el aviso de baja, si había;
- si se eligió, sale el aviso a la familia;
- todo queda en el registro de actividad (`academic`).

"Estado" ya no ofrece "Baja". El formulario de editar la inscripción en la ficha sigue ofreciéndola (fecha de hoy, sin
motivo): no se tocó porque lo cambia F2.

## 2. Quién la marca

- **Da la baja** quien puede **editar inscripciones** (`Update:Enrollment`: admin; secretario y prosecretario por
  defecto). En la app es el permiso `withdraw_students`.
- **Avisos de baja** (no dan la baja; quien decide elige "Dar de baja" o "Sigue viniendo"):
  - **El técnico**, desde Mis grupos: "Avisar que dejó de venir", con una nota opcional.
  - **El tutor**, desde la ficha del hijo: "Avisar que deja el club", con un mensaje opcional. Marca todas las
    inscripciones vigentes del hijo y manda un solo aviso.

  Cada uno puede deshacer su aviso. Quedan en la inscripción: `dropout_reported_at`, `dropout_reported_by`,
  `dropout_note` y `dropout_source` (`instructor` o `guardian`).

  Llegan por push y correo (`DropoutReported`, a `/bajas`) a quienes pueden dar de baja. En el panel se ven en
  Inscripciones, con el filtro "Con aviso de baja" y un contador en el menú; en la app, en `/bajas`.

## 3. Qué pasa con la deuda

- **Queda pendiente como histórica. Nunca se borra ni se anula sola**: siguen las cuotas impagas anteriores y **la del
  período en curso**. Solo se anulan solas las **futuras** sin pagos.
- Sigue en el estado de cuenta, en Saldos y en Morosos, con la marca de baja.
- Sale solo si se paga, se **anula** (error de carga) o se **condona**.

## 4. Condonar y deshacer

- **Dónde:**
  - Panel: "Condonar" en Cargos y en la ficha → Cuenta, por fila o en bloque.
  - App: en la ficha `/alumnos/:id`, "Condonar" por cuota y "Condonar todo lo pendiente".
- **Permiso** `Waive:Charge` "Condonar deudas":
  - por defecto lo tienen el **admin**, el **tesorero** y el **presidente** (`DefaultPermissions`);
  - a los roles existentes se los agrega la migración `2026_10_09_100002`;
  - el admin lo agrega o quita por rol en Roles (Shield);
  - en la app es `waive_charges`.
- **Qué hace:** condona **lo que falta pagar**, con motivo; lo ya pagado sigue siendo ingreso. Queda:
  - en el cargo, `voided_at`, `voided_by`, `void_reason` y `waived_amount`, con estado "Condonado" (`condonado`);
  - en `charge_condonations`, una fila por condonación (monto, motivo, quién, cuándo);
  - en el historial del cargo, "Condonada ₲ X: motivo".
- **Cómo cuenta:** es una anulación marcada, así que sale de saldos e informes como una anulada (también de las
  consultas SQL que excluyen `voided_at`). No libera la clave del período y los descuentos por clases suspendidas
  quedan usados.
- **Deshacer** ("Deshacer condonación" en el panel; "Deshacer" en la app), con el mismo permiso y motivo obligatorio:
  - la cuota vuelve a quedar pendiente por lo condonado;
  - la condonación guarda `undone_at`, `undone_by` y `undo_reason`;
  - el historial muestra "Condonación deshecha: motivo";
  - se puede volver a condonar (otra fila).
- No se condona una cuota anulada, pagada o ya condonada. No se usa `ChargeWaiver` (descuento por clase suspendida).

## 5. Si el alumno vuelve

- **"Reactivar"** (panel), como Activo o Becado. La deuda sigue para pagarse.
- Las cuotas se emiten **desde el período en curso**: los meses que estuvo afuera no se cobran. Antes se emitían desde
  la inscripción.
- Las futuras anuladas se vuelven a emitir. Igual al volver de una suspensión.

## 6. Cómo se ve

| Dónde | Qué cambia |
|---|---|
| **Morosos** (app, panel, PDF y Excel) | `withdrawn` por familia: "Matías: baja el 03/06/2026". Filtro Todos / Siguen / Dados de baja (`withdrawn=exclude\|only`). En la app, con permiso, la marca abre la ficha del alumno. |
| **Saldos** | La misma marca. |
| **Inscripciones** (panel) | Baja con fecha y motivo; aviso (técnico o familia) con quién y cuándo; filtro y contador. |
| **Cargos y Cuenta** (panel) | Estado "Condonado", "Condonar", "Deshacer condonación"; historial con cada paso. |
| **App, inicio** | Tarjeta "Avisos de baja" para `withdraw_students`. |
| **App, `/alumnos/:id`** | Inscripciones ("Dar de baja", "Sigue viniendo") y, con `waive_charges`, la cuenta con "Condonar" y "Deshacer". |
| **App, ficha del hijo** (tutor) | "Avisar que deja el club" / "Ya no deja el club" y la marca "Avisaste que deja el club el …". |
| **App, Mis grupos** | "Avisar que dejó de venir" / "Sigue viniendo"; con permiso, "Ver ficha". |
| **Estado de cuenta del tutor** | La condonada desaparece como una anulada; si se deshace, vuelve. |

Un alumno está **dado de baja** cuando no tiene ninguna inscripción sin baja en temporadas vigentes o próximas y tiene
al menos una baja (`App\Support\Enrollments\Withdrawals`). Si sigue en otra disciplina, no lo está.

## 7. Avisos

| Aviso | A quién | Canal |
|---|---|---|
| `DropoutReported` (técnico o tutor) | quienes pueden dar de baja, menos quien avisó | push (`/bajas`) + correo ("Ver en Tuku" y "Ver en el panel") |
| `StudentWithdrawn` (si quien da la baja lo elige) | tutores con la app y alumno adulto con cuenta | push + correo, sin mascota |

No se usa la API de WhatsApp: los avisos van por los canales que ya existen.

## 8. Contrato de API

Ver "Bajas y condonación" en `docs/API_V1.md`:
- `GET organization`: `withdraw_students` y `waive_charges`.
- Técnico: `POST`/`DELETE groups/{id}/students/{student}/dropout`.
- Tutor: `POST`/`DELETE students/{id}/leaving` y `leaving_reported_on` en `GET students`.
- Quien da de baja o condona:
  - `GET dropout-reports`
  - `GET staff/students/{id}`
  - `POST enrollments/{id}/withdraw`
  - `DELETE enrollments/{id}/dropout`
  - `POST charges/waive`
  - `POST charges/{id}/unwaive`
- Informes: `withdrawn` por familia y `?withdrawn=only|exclude`.

## 9. Fuera de alcance

- Reactivar desde la app (por ahora solo el panel).
- Baja con fecha futura; motivos tipificados con un informe de bajas del período.
- "Condonado en el mes" en el Balance.
- Marca de baja en la tarjeta Morosos del Escritorio: la reescribe la rama de informes.
- Aviso a la familia al condonar.
- Avisar a tutores sin cuenta en la app (solo correo o WhatsApp): hoy se sugiere avisarles a mano.

## 10. Posibles conflictos

- **Rama de informes** (`informes-etapa-0`): reescribe `DelinquentsReport`, `FamilyBalancesReport`, `Reports.php` y su
  vista, `ChargeResource` y `Metrics`. Acá los cambios son de pocas líneas: `withdrawn` por fila, el filtro y las
  acciones de Cargos.
- **F2** (inscripción por tutores): toca el hook `updated` de `Enrollment` y `StudentResource`, donde acá se agrega
  `leaving_reported_on`. `EnrollmentForm` no se tocó.
- **F1** (cobros): `ChargeStatus` (app y API) suma `condonado`.
- **Migraciones:** `2026_10_09_100001` y `2026_10_09_100002`.
