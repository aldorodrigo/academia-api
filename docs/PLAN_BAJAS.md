# Plan — Bajas y condonación de deudas

> Nace del hallazgo **F4** de la prueba integral (2026-10-04): la baja era solo cambiar el estado de la inscripción a
> "Baja" en el panel. Una baja a principio de mes (Matías Zárate, 3/6) dejaba la cuota del mes ya emitida (1/6) y las
> anteriores impagas, y en "Morosos" (app, panel y PDF/Excel) no se veía que el alumno ya se había ido.
>
> Decisiones del usuario: **la deuda queda pendiente como histórica** y se **condona según el administrador o quien
> tenga el permiso**; la cuota del mes en curso **queda pendiente** hasta que el administrador la anule, el chico
> vuelva y la pague, o se condone.
>
> Reglas implementadas: `business-logic.md` §3 (Bajas) y §5/§7. Contrato: `docs/API_V1.md` → "Bajas y condonación".

## 1. Cómo se da la baja

**En el panel**, con la acción **"Dar de baja"**:

- en **Inscripciones** (por fila y masiva, "Dar de baja" en la barra de selección), y
- en la **ficha del jugador → pestaña Inscripciones**.

El formulario pide:

| Campo | Regla |
|---|---|
| **Fecha de baja** | Hoy por defecto. Entre la fecha de inscripción y hoy (no hay bajas a futuro en el MVP). |
| **Motivo** | Obligatorio, texto libre ("Se mudó", "Dejó de venir", "Cambió de club"…). Si el técnico avisó, viene precargado con su nota. |

Antes de confirmar, el modal muestra el efecto en dinero:

- "Quedan pendientes N cuotas por ₲ X (la de junio y anteriores): siguen en su cuenta. Para no cobrarlas, condonalas
  desde la pestaña Cuenta."
- "Se anulan N cuotas futuras sin pagar." (lo que ya hacía `VoidFutureCharges`).

Al confirmar (`App\Actions\Enrollments\WithdrawEnrollment`): estado `baja`, `ended_on` = la fecha elegida,
`withdrawal_reason`, `withdrawn_by`; se anulan las cuotas cuyo período **todavía no empezó** y no tienen pagos; se borra
el aviso del técnico, si había; queda en el registro de actividad (`academic`).

- La acción "Estado" (fila y masiva) **ya no ofrece "Baja"**: toda baja lleva fecha y motivo. (El formulario de editar
  la inscripción en la ficha todavía permite elegir "Baja"; queda con fecha de hoy y sin motivo. No se tocó porque lo
  cambia la sesión de inscripción por tutores, F2.)
- **En la app no se da la baja** en el MVP: es una decisión administrativa que mueve dinero y el panel ya tiene la
  ficha, la cuenta y el historial a mano. La app sí lleva el **aviso del técnico** (punto 2).

## 2. Quién la marca

- **Da la baja** quien puede **editar inscripciones** (`Update:Enrollment`: el admin, y el secretario y prosecretario
  por defecto). Es el mismo permiso que ya cambiaba el estado: no se agrega uno nuevo.
- **El técnico avisa "Dejó de venir"** desde la app (Mis grupos → grupo → alumno → "Avisar que dejó de venir", con una
  nota opcional). No da la baja: la inscripción queda marcada (`dropout_reported_at/by`, `dropout_note`) y llega un
  aviso (push + correo) a quienes pueden dar de baja. En el panel, Inscripciones muestra el aviso, tiene el filtro
  "Avisó el técnico" y un contador en el menú; el admin decide: **"Dar de baja"** (con la nota como motivo) o
  **"Sigue viniendo"** (descarta el aviso). El técnico puede deshacer su aviso.

  *Por qué es lo mínimo útil:* el F4 nace de una baja que se registra tarde, y el técnico es el primero que ve que el
  chico no viene; con un toque le avisa a quien decide. No se le da la baja al técnico porque mueve dinero (anula
  cuotas) y no es su rol. El tutor que avisa que su hijo deja queda para después (punto 9).

## 3. Qué pasa con la deuda

- **Queda pendiente como histórica. Nunca se borra ni se anula sola**: siguen las cuotas impagas de los meses
  anteriores y **la del período en curso** (si ya empezó cuando se da la baja, aunque la fecha de baja sea anterior).
- Lo único automático es lo de siempre: las cuotas **futuras** (período que todavía no empezó) sin pagos se anulan.
- La deuda sigue sumando en el estado de cuenta de la familia, en Saldos y en Morosos, ahora **con la marca de baja**.
- Sale de la cuenta solo si alguien con permiso la **anula** (error de carga) o la **condona** (punto 4), o si se paga.

## 4. Condonar

**Acción nueva "Condonar"** (no se reutiliza `ChargeWaiver`, que es el descuento por clase suspendida que se aplica en
la próxima cuota, ni la anulación, que es para cargos mal emitidos):

- En **Cargos** (por fila y masiva) y en la **ficha del jugador → Cuenta** (por fila y masiva: "Condonar lo
  seleccionado").
- **Permiso nuevo `Waive:Charge` "Condonar deudas"** (Shield → Roles). Por defecto **solo el admin** (Gate::before);
  el admin se lo puede dar al tesorero, al presidente o a quien decida la comisión.
- Pide **motivo** (obligatorio). Condona **lo que falta pagar** de cada cuota: lo ya pagado sigue siendo ingreso.
- Queda registrado: `waived_amount` (lo condonado), `voided_at` (cuándo), `voided_by` (quién) y `void_reason`
  (por qué), en el historial del cargo ("Condonada ₲ X: motivo") y en el registro de actividad (`billing`).
- Técnicamente es una anulación marcada como condonación (`App\Actions\Billing\WaiveCharges`): sale de todos los
  saldos e informes igual que una anulada (también de las consultas SQL del escritorio y de los informes nuevos,
  que ya excluyen `voided_at`), con estado propio **"Condonado"** (`ChargeStatus::Waived`, `condonado`).
- No se puede condonar una cuota anulada, pagada o ya condonada. Una condonación no se deshace en el MVP (si fue un
  error, se carga un cargo manual).

## 5. Si el alumno vuelve

- Acción **"Reactivar"** en Inscripciones y en la ficha (elige Activo o Becado), para quien puede editar inscripciones.
- **La deuda sigue ahí** para pagarse (lo que no se condonó ni se anuló).
- Se emite la cuota **desde el período en curso**: los meses que estuvo afuera **no se cobran** (antes, al reactivar
  se emitían desde la fecha de inscripción, también los meses de la baja). Las futuras que se anularon con la baja se
  vuelven a emitir según el plan (todas juntas o al empezar cada período). Lo mismo al volver de una suspensión.
- Se limpian `ended_on`, motivo y quién; el historial queda en el registro de actividad.

## 6. Cómo se ve

| Dónde | Qué cambia |
|---|---|
| **Morosos** (app, panel, PDF y Excel) | Cada familia trae `withdrawn` (sus hijos dados de baja con la fecha): "Matías: baja el 03/06/2026". **Filtro** "Todos / Siguen / Dados de baja" (`withdrawn=exclude|only`) en la app (chips) y en el panel (select). |
| **Saldos** (app, panel, PDF y Excel) | La misma marca por familia. |
| **Inscripciones** (panel) | La baja muestra fecha y motivo; el aviso del técnico, quién y cuándo; filtro "Avisó el técnico"; contador en el menú. |
| **Ficha → Inscripciones** | Columna "Baja" con la fecha y el motivo. |
| **Cargos y ficha → Cuenta** | Estado "Condonado" y "Condonar". |
| **Estado de cuenta del tutor** (app) | Sin cambios: la deuda sigue hasta que se paga o se condona; la condonada desaparece como una anulada. |
| **Mis grupos** (técnico, app) | El alumno avisado muestra "Avisaste que dejó de venir el 03/06" y "Sigue viniendo" para deshacer. Un alumno dado de baja ya no aparece en las clases (como antes). |

Un alumno está **dado de baja** cuando todas sus inscripciones de temporadas vigentes o próximas están en `baja` (o no
tiene ninguna y la última es una baja): `App\Support\Enrollments\Withdrawals`. Si sigue en otra disciplina, no.

## 7. Avisos

- **Aviso del técnico** → push + correo (`DropoutReported`) a los miembros activos que pueden dar de baja
  (`Update:Enrollment` o admin). Sin mascota (no es una buena noticia).
- No se avisa al tutor de la baja ni de la condonación en el MVP (pregunta abierta).

## 8. Contrato de API

Sección "Bajas y condonación" de `docs/API_V1.md`:

- `GET groups/{id}`: cada alumno trae `dropout_reported_on` (o `null`).
- `POST groups/{id}/students/{student}/dropout` (`{ "note": "…" }`) · `DELETE groups/{id}/students/{student}/dropout`.
- `GET reports/delinquents?withdrawn=only|exclude` y `GET reports/balances`: `withdrawn` por familia.
- `status: "condonado"` en los cargos (hoy no llega al tutor, que no ve las anuladas).

## 9. Fuera del MVP

- El tutor avisa desde la app que su hijo deja el club.
- Dar la baja o condonar desde la app (comisión).
- Baja con fecha futura ("se va a fin de mes") y motivos tipificados con un informe de bajas del período.
- Deshacer una condonación; "Condonado en el mes" en el Balance.
- Marca de baja en la tarjeta **Morosos del Escritorio** (`Metrics`, la reescribe la sesión de informes): se agrega
  cuando se integre esa rama.
- Aviso al tutor de la baja o de la condonación.

## 10. Implementación

1. **App** (contra el contrato, con fakes): marca y filtro en Morosos y Saldos; aviso del técnico en el grupo.
2. **API**: migración (`enrollments`: motivo, quién, aviso; `charges.waived_amount`), `WithdrawEnrollment`,
   `ReactivateEnrollment`, `ReportDropout`, `WaiveCharges`, `Withdrawals`, `DropoutReported`, endpoints, informes.
3. **Panel**: acciones en Inscripciones, ficha (Inscripciones y Cuenta), Cargos e Informes; permiso en Shield.
4. Tests en los dos lados; `business-logic.md` y `API_V1.md`.

**Posibles conflictos** con la rama de informes (`informes-etapa-0`): reescribe `DelinquentsReport`,
`FamilyBalancesReport` (→ `BalancesReport`), `Reports.php`, su vista, `ChargeResource` y `Metrics`. Los cambios de esta
rama en esos archivos son de pocas líneas (una clave `withdrawn` por fila, el filtro y una acción). Con F2 (inscripción
por tutores): el hook `updated` de `Enrollment` (reactivar desde baja/suspensión emite desde hoy; de pendiente a activo
sigue desde la inscripción) y `EnrollmentForm` (no se tocó).
