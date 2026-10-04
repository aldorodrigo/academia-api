# API v1 — contrato

Base: `/api/v1`. Todas las respuestas en JSON y en español. Los errores de validación usan el formato estándar de Laravel (`422` con `message` y `errors`).

Headers:
- `Authorization: Bearer {token}`: en los endpoints autenticados.
- `X-Organization: {slug}`: en los endpoints que dependen de la organización activa.

Este documento se reemplaza por la especificación OpenAPI en el Sprint 5.

## Sprint 0 (implementado)

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| POST | `auth/token` | — | `{login, password, device_name}` → `201 {token}` (`login`: celular o correo; desde el Sprint 5d) |
| DELETE | `auth/token` | token | Revoca el token actual → `204` |
| GET | `me` | token | Usuario y organizaciones activas |
| GET | `organization` | token + org | Datos, vocabulario y módulos de la organización activa |

## Sprint 1 (implementado)

### `GET invitations/{token}` (público, con throttle)

Muestra la invitación antes de aceptarla.

```json
{
  "data": {
    "organization": { "slug": "jakare", "name": "Club Jakare" },
    "email": "ana@example.com",
    "roles": [ { "name": "tutor", "label": "Tutor" } ],
    "user_exists": false,
    "expires_at": "2026-10-10T03:00:00Z"
  }
}
```

- Si la invitación no existe, ya fue usada o venció, responde `404 {message: "La invitación no es válida o ya venció."}`.
- `user_exists` indica si ya hay una cuenta con ese email. Si es `true`, la app pide solo la contraseña.

### `POST invitations/{token}/accept` (público, con throttle)

| Campo | Cuándo |
|---|---|
| `name` | Obligatorio si `user_exists = false` |
| `password` | Siempre. Si la cuenta es nueva, mínimo 8 caracteres; si ya existe, la contraseña actual |
| `password_confirmation` | Obligatorio si `user_exists = false` |
| `device_name` | Siempre |

Respuesta `201 {token, organization: "jakare"}`.

Al aceptar:
- se crea el usuario si no existía;
- se activa la membresía en la organización y se asignan los roles de la invitación;
- se marca la invitación como usada;
- se emite un token Sanctum.

Si la contraseña de una cuenta existente no coincide, responde `422` sobre `password`.

### `GET organization` (se amplía)

Agrega `membership` con los roles del usuario en la organización activa:

```json
"membership": {
  "roles": [
    { "name": "tutor", "label": "Tutor", "starts_on": null, "ends_on": null },
    { "name": "tesorero", "label": "Tesorero", "starts_on": "2026-01-01", "ends_on": "2027-12-31" }
  ]
}
```

- Solo incluye los roles vigentes. Un cargo con mandato vencido no aparece.
- `features` es la lista de módulos activos y `terminology` el vocabulario ya combinado con los valores por defecto.

### Links de invitación

El link que se envía por email o se muestra como QR es `{APP_URL_WEB}/invitacion/{token}`. En el celular abre la app (deep link, cuando haya dominio) y en la web abre la app web. Además, la app permite pegar el link o el código a mano.

## Sprint 2 (implementado)

Todos requieren token + organización. Solo se incluyen las inscripciones de la temporada actual.
Estados de inscripción: `pendiente`, `activo`, `becado`, `suspendido`, `baja` (con `status_label` para mostrar).

### `GET students`

Alumnos **a cargo del usuario** en la organización activa: los hijos vinculados a él como tutor y él mismo si es alumno adulto (`is_self: true`).

```json
{
  "data": [
    {
      "id": 12,
      "first_name": "Mateo",
      "last_name": "Benítez",
      "full_name": "Mateo Benítez",
      "birth_date": "2016-03-14",
      "photo_url": null,
      "is_self": false,
      "enrollments": [
        {
          "id": 40,
          "status": "activo",
          "status_label": "Activo",
          "season": { "id": 1, "name": "2026" },
          "group": { "id": 3, "name": "Sub-10", "program": { "id": 1, "name": "Fútbol" } }
        }
      ]
    }
  ]
}
```

### `GET students/{id}`

Ficha del alumno. Responde `404` si no existe **o no está a cargo del usuario** (no revela si existe).

```json
{
  "data": {
    "id": 12,
    "first_name": "Mateo",
    "last_name": "Benítez",
    "full_name": "Mateo Benítez",
    "birth_date": "2016-03-14",
    "photo_url": null,
    "is_self": false,
    "document": "6123456",
    "shirt_size": "12",
    "position": "Arquero",
    "guardians": [
      { "name": "Ana Benítez", "relationship": "Madre", "is_me": true }
    ],
    "enrollments": [
      {
        "id": 40,
        "status": "activo",
        "status_label": "Activo",
        "season": { "id": 1, "name": "2026" },
        "group": {
          "id": 3,
          "name": "Sub-10",
          "program": { "id": 1, "name": "Fútbol" },
          "schedules": [
            { "weekday": 1, "starts_at": "17:00", "ends_at": "18:30", "venue": { "name": "Cancha 1" } }
          ],
          "instructors": [ { "name": "Carlos Gómez" } ]
        }
      }
    ],
    "medical": {
      "blood_type": "O+",
      "allergies": "Penicilina",
      "conditions": null,
      "medications": null,
      "emergency_contact": { "name": "Ana Benítez", "phone": "0981 123 456" },
      "fit_until": "2027-03-01"
    },
    "permissions": { "view_medical": true }
  }
}
```

- `weekday`: ISO (1 = lunes … 7 = domingo). `venue` puede ser `null`.
- `medical` es `null` si el usuario no puede ver la ficha médica, o si el alumno todavía no tiene ficha (`permissions.view_medical` distingue los dos casos). La ven: el tutor del alumno, el alumno adulto, el instructor de un grupo del alumno en la temporada actual y los roles con el permiso `ViewMedical:Student`.
- Los horarios van dentro del grupo; no hay endpoint aparte.

### Invitaciones de tutores

Una invitación creada desde un tutor (panel o importación) queda vinculada a él. Al aceptarla, el tutor se asocia a la cuenta y desde ese momento `GET students` devuelve sus hijos.

## Sprint 3 (implementado)

Estado de cuenta. Requieren token + organización. Montos en guaraníes enteros.

### `GET account`

Consolidado de la familia: los alumnos a cargo del usuario (sus hijos, o él mismo si es alumno adulto).

```json
{
  "data": {
    "balance": 270000,
    "overdue": 150000,
    "students": [
      { "id": 12, "full_name": "Mateo Benítez", "balance": 150000, "overdue": 150000 },
      { "id": 13, "full_name": "Sofía Benítez", "balance": 120000, "overdue": 0 }
    ],
    "charges": [
      {
        "id": 501,
        "student": { "id": 13, "first_name": "Sofía" },
        "concept": "Cuota mensual",
        "description": "Cuota septiembre 2026",
        "period": "2026-09",
        "group": "Sub-8",
        "issued_on": "2026-09-01",
        "due_on": "2026-09-10",
        "status": "pendiente",
        "status_label": "Pendiente",
        "base_amount": 150000,
        "final_amount": 60000,
        "adjustments": [
          { "type": "beca", "label": "Beca 50 %", "amount": -75000 },
          { "type": "hermanos", "label": "Hermanos (2º hijo) −20 %", "amount": -15000 }
        ]
      }
    ]
  }
}
```

### `GET students/{id}/account`

Misma forma con un solo alumno en `students`. Responde `404` si el alumno no está a cargo del usuario.

- `status`: `pendiente`, `vencido` (pasó el vencimiento más los días de gracia), `pagado` (desde el Sprint 4) o `anulado`.
- `balance`: suma de `final_amount` de los cargos no anulados, menos los pagos (desde el Sprint 4). `overdue`: la parte vencida.
- `charges`: los de la temporada actual y cualquier cargo impago anterior; sin los anulados. Ordenados por vencimiento, del más nuevo al más viejo.
- `period` es `null` en los cargos que no son mensuales (inscripción, torneo…). `group` puede ser `null` en cargos manuales.
- `adjustments[].amount` lleva signo: negativo para descuentos y becas, positivo para recargos (desde el Sprint 4).

## Sprint 4a (implementado)

Pagos en el estado de cuenta. `GET account` y `GET students/{id}/account` **se amplían** (los campos anteriores no cambian).

### En cada cargo

- `paid_amount`: lo cubierto por pagos no anulados (incluye el descuento por pronto pago).
- `pending_amount`: lo que falta pagar. `status` es `pagado` cuando llega a 0.
- Si al pagarlo se aplicó pronto pago, aparece en `adjustments`: `{ "type": "pronto_pago", "label": "Pronto pago −10 %", "amount": -15000 }`.

### En la cuenta

- `credit`: saldo a favor de la familia (lo pagado de más que todavía no se aplicó). Se aplica solo al próximo cargo.
- `balance`: suma de `pending_amount` menos `credit` (nunca negativo). `overdue`: lo vencido pendiente.
- `payments`: últimos pagos de la familia, del más nuevo al más viejo.

```json
{
  "id": 90,
  "receipt_number": "000123",
  "received_on": "2026-09-20",
  "amount": 300000,
  "method": "transferencia",
  "method_label": "Transferencia",
  "voided": false,
  "receipt_url": "https://api.example.com/recibos/90?expires=…&signature=…",
  "allocations": [
    { "charge_id": 501, "description": "Cuota septiembre 2026", "student_first_name": "Sofía", "amount": 60000 }
  ],
  "credit_generated": 90000
}
```

- `method`: `efectivo`, `transferencia` o `billetera`.
- `receipt_url`: link firmado y temporal (30 minutos) al recibo en PDF; se abre sin token. Vencido o alterado → `403`.
- `credit_generated`: lo que el pago dejó como saldo a favor.
- Un pago anulado viene con `voided: true`, no cuenta para los cargos y su recibo sale con la marca "ANULADO".

## Sprint 4b (implementado)

Informes para quien tiene el permiso "Ver informes" (comisión). Requieren token + organización; sin el permiso responden `403`.

### `GET organization` (se amplía)

`membership.permissions`: lista de permisos del usuario que usa la app. Por ahora: `"view_reports"`.

```json
"membership": { "roles": [ … ], "permissions": ["view_reports"] }
```

### Descargas

Cada informe trae `pdf_url` y `xlsx_url`: links firmados y temporales (30 minutos) que se abren sin token (vencidos o alterados → `403`).

### `GET reports/balance?from=2026-09-01&to=2026-09-30`

Ingresos y gastos del período (por defecto, el mes actual en la fecha local).

```json
{
  "data": {
    "from": "2026-09-01",
    "to": "2026-09-30",
    "opening_balance": 1200000,
    "closing_balance": 1850000,
    "income": {
      "total": 2100000,
      "lines": [
        { "label": "Cuota mensual", "amount": 1800000 },
        { "label": "Inscripción", "amount": 200000 },
        { "label": "Saldo a favor", "amount": 100000 }
      ]
    },
    "expenses": {
      "total": 1450000,
      "lines": [
        { "label": "Alquiler de cancha", "amount": 1200000 },
        { "label": "Árbitros", "amount": 250000 }
      ]
    },
    "accounts": [
      { "name": "Caja", "balance": 350000 },
      { "name": "Banco Itaú", "balance": 1500000 }
    ],
    "pending_expenses": 1200000,
    "pdf_url": "https://…",
    "xlsx_url": "https://…"
  }
}
```

- `opening_balance` / `closing_balance`: suma de todas las cuentas al inicio y al final del período. `accounts[].balance`: saldo al final del período.
- `other`: saldos iniciales y ajustes del período (ni ingreso ni gasto). Siempre se cumple: inicial + ingresos − gastos + `other` = final.
- Un pago o gasto anulado en un período posterior resta en ese período.
- Ingresos por concepto (según a qué se imputó cada pago; lo no imputado va como "Saldo a favor"). Gastos pagados por categoría. Transferencias entre cuentas no cuentan; anulados tampoco.
- `pending_expenses`: gastos pendientes de pago que vencen en el período.

### `GET reports/balances`

Saldos por familia (con algo pendiente o saldo a favor), de la que más debe a la que menos.

```json
{
  "data": {
    "totals": { "pending": 3250000, "overdue": 1800000, "credit": 90300 },
    "families": [
      { "family": "Familia Benítez", "students": ["Mateo", "Sofía"], "pending": 270000, "overdue": 150000, "credit": 0 }
    ],
    "pdf_url": "https://…",
    "xlsx_url": "https://…"
  }
}
```

### `GET reports/delinquents?min_months=1`

Morosos: familias con cuotas vencidas hace al menos `min_months` meses (por defecto 1), de mayor a menor deuda vencida.

```json
{
  "data": {
    "total": 1800000,
    "families": [
      {
        "family": "Familia Ortiz",
        "students": ["Diego"],
        "overdue": 450000,
        "oldest_due_on": "2026-07-10",
        "months_overdue": 3,
        "contact": { "name": "Rosa Ortiz", "phone": "0981 222 333" }
      }
    ],
    "pdf_url": "https://…",
    "xlsx_url": "https://…"
  }
}
```

- `contact` puede ser `null` (familia sin tutor con teléfono).

## Sprint 4c (implementado)

Temporadas por disciplina, vigentes por fechas (puede haber varias a la vez: la anual de fútbol y una colonia),
con plan de cobro propio (mensual, quincenal, semanal o por día). Los campos anteriores no cambian.

### Inscripciones (`GET students`, `GET students/{id}`)

- Se incluyen las inscripciones de temporadas **vigentes o próximas** (antes: solo la temporada actual).
- `season` se amplía:

```json
"season": {
  "id": 3,
  "name": "Colonia de verano 2027",
  "starts_on": "2027-01-04",
  "ends_on": "2027-01-17",
  "programs": [ { "id": 1, "name": "Fútbol" }, { "id": 2, "name": "Pádel" } ]
}
```

Una temporada es próxima si `starts_on` es posterior a hoy.

### En cada cargo (`GET account`, `GET students/{id}/account`)

```json
{
  "season": { "id": 3, "name": "Colonia de verano 2027" },
  "period": "2027-01",
  "period_start": "2027-01-04",
  "period_end": "2027-01-10",
  "quantity": 5,
  "unit_amount": 20000,
  "is_upcoming": false
}
```

- `season`: `null` en cargos manuales sin temporada.
- `period_start` / `period_end`: período que cubre la cuota (mes, quincena, semana o día); `null` en cargos que no son cuotas
  (inscripción, torneo…). `period` (`AAAA-MM` de `period_start`) se mantiene por compatibilidad.
- `quantity` / `unit_amount`: solo en el cobro por día agrupado ("5 entrenamientos × ₲ 20.000"); si no, `null`.
- `is_upcoming`: `true` si falta pagar algo y el período todavía no empezó (cuotas creadas por adelantado).

### En la cuenta

- `due_now`: lo que hay que pagar ahora: pendiente de los cargos que no son próximos, menos el saldo a favor (nunca negativo).
- `upcoming`: pendiente de las cuotas próximas.
- `balance` sigue siendo el total (`due_now` + `upcoming`). Cada elemento de `students` suma también `due_now` y `upcoming`.

### `GET reports/balances` (se amplía)

- `pending` ya no incluye las cuotas próximas; van aparte en `upcoming` (en `totals` y en cada familia).

## Sprint 5 (implementado)

Asistencia desde la app del técnico, "hoy hay clase, ¿lo llevás?" para el tutor y registro del dispositivo para push.
Requieren token + organización.

### Clases

Una **clase** es un día concreto de un horario del grupo (ej. Sub-10 el lunes 28/09 de 17:00 a 18:30). La API las crea
sola al consultarlas, a partir de los horarios, y solo dentro de las fechas de la temporada. Todas las fechas y horas
son locales de la organización.

```json
{
  "id": 81,
  "date": "2026-09-28",
  "starts_at": "17:00",
  "ends_at": "18:30",
  "venue": { "name": "Cancha 1" },
  "group": { "id": 3, "name": "Sub-10", "program": { "id": 1, "name": "Fútbol" } },
  "status": "programada",
  "suspension_reason": null,
  "attendance_taken": false,
  "counts": {
    "enrolled": 21, "going": 15, "not_going": 2, "no_answer": 4,
    "present": 0, "absent": 0, "justified": 0
  }
}
```

- `status`: `programada` o `suspendida` (con `suspension_reason`). `venue` puede ser `null`.
- `counts`: alumnos de la clase (inscripción activa o becada), respuestas de los tutores y, si ya se tomó, asistencia.

### Técnico (permiso `take_attendance` en `GET organization`)

El técnico ve las clases de sus grupos; quien tiene "Tomar asistencia" en cualquier grupo (coordinador) ve todas.

#### `GET classes?date=2026-09-28`

Clases del día (por defecto, hoy), por hora de inicio.

#### `GET classes/{id}`

La clase con `editable` y sus alumnos, por apellido:

```json
{
  "editable": true,
  "students": [
    {
      "id": 12, "full_name": "Mateo Benítez", "photo_url": null,
      "status": null, "guardian_response": "no_va", "note": null
    }
  ]
}
```

- `status`: `presente`, `ausente`, `justificado` o `null` (todavía no se tomó).
- `guardian_response`: `va`, `no_va` o `null` (el tutor no respondió).
- `editable`: el día de la clase y hasta 3 días después; después solo se corrige desde el panel.

#### `PUT classes/{id}/attendance`

```json
{ "marks": [ { "student_id": 12, "status": "justificado", "note": "Avisó que no va" } ] }
```

Guarda todas las marcas de una vez (idempotente) y devuelve la clase como `GET classes/{id}`. `422` si no es
editable, si está suspendida o si un alumno no es de la clase.

#### `POST classes/{id}/suspension` · `DELETE classes/{id}/suspension`

`{ "reason": "Lluvia" }`. Suspende la clase y avisa por push a los tutores del grupo (a todos, no depende de los
avisos de días de clase). `DELETE` la vuelve a programar. Devuelven la clase.

#### `GET groups`

Grupos del técnico: `[{ "id", "name", "program", "schedules", "students_count" }]`.

#### `GET groups/{id}?month=2026-09`

```json
{
  "data": {
    "id": 3, "name": "Sub-10", "program": { "id": 1, "name": "Fútbol" }, "schedules": [ … ],
    "month": "2026-09",
    "classes": [ { …clase… } ],
    "students": [
      { "id": 12, "full_name": "Mateo Benítez", "photo_url": null, "present": 7, "absent": 1, "justified": 1, "rate": 78 }
    ]
  }
}
```

- `classes`: las del mes hasta hoy, de la más reciente a la más vieja.
- `rate`: % de presentes sobre las clases tomadas; `null` si no hay ninguna.

### Tutor

#### `GET agenda`

La próxima clase de cada alumno a cargo (hoy o en los próximos 7 días; la de hoy se muestra hasta que termina).

```json
{
  "data": [
    {
      "student": { "id": 12, "first_name": "Mateo", "full_name": "Mateo Benítez", "photo_url": null },
      "class": { "id": 81, "date": "2026-09-28", "starts_at": "17:00", "ends_at": "18:30", "venue": { "name": "Cancha 1" },
                 "group": { … }, "status": "programada", "suspension_reason": null },
      "response": null,
      "can_respond": true,
      "class_reminders": null
    }
  ]
}
```

- `response`: `va`, `no_va` o `null`. `can_respond`: hasta que empieza la clase y si no está suspendida.
- `class_reminders`: si el usuario pidió aviso los días de clase de ese alumno; `null` = nunca respondió.

#### `PUT classes/{id}/students/{student}/response`

`{ "going": false }`. Devuelve el elemento de la agenda. `422` si la clase ya empezó o está suspendida.
"No va" deja al alumno **justificado** al tomar asistencia (el técnico lo puede cambiar).

#### `GET students/{id}/attendance?month=2026-09`

```json
{
  "data": {
    "month": "2026-09", "present": 7, "absent": 1, "justified": 1, "rate": 78,
    "classes": [
      { "id": 81, "date": "2026-09-28", "starts_at": "17:00", "group": { … }, "status": "programada", "attendance": "presente" }
    ]
  }
}
```

`attendance`: `presente`, `ausente`, `justificado` o `null` (no se tomó). Solo clases hasta hoy, de la más reciente a la más vieja.

#### `PUT students/{id}/reminders`

`{ "enabled": true }` → `{ "data": { "class_reminders": true } }`. `GET students/{id}` agrega `class_reminders`
(`true`, `false` o `null`).

Con el aviso activo, se manda un push por clase unas horas antes (el club lo configura; por defecto 3): "Hoy Mateo tiene
Fútbol a las 17:00 (Cancha 1). ¿Lo llevás?". Si esa hora cae entre las 22:00 y las 7:00, sale a las 20:00 del día
anterior ("Mañana …").

### Dispositivos

- `POST devices` `{ "token": "…", "platform": "android" }` (`android`, `ios` o `web`) → `204`. Si el token ya existe, se
  asocia al usuario actual.
- `DELETE devices/{token}` → `204` (al cerrar sesión).

Los push traen en `data` el tipo y la ruta de la app que abre: `{ "type": "class_reminder", "route": "/inicio" }`,
`{ "type": "class_suspended", "route": "/inicio" }`.

### `GET organization` (se amplía)

`membership.permissions` suma `"take_attendance"` (técnico con grupos o permiso "Tomar asistencia").

## Sprint 5a+ (implementado)

Clases suspendidas sin cobrar, reprogramación, avisos configurables, aviso al técnico y respuesta desde la
notificación. Los campos anteriores no cambian. La asistencia sin conexión es solo de la app (sin cambios de API).

### En cada clase (`GET classes`, `GET classes/{id}`, agenda, grupos)

```json
{
  "status": "reprogramada",
  "charge_waived": false,
  "is_makeup": false,
  "rescheduled_to": { "id": 95, "date": "2026-10-03", "starts_at": "09:00", "ends_at": "10:30", "venue": { "name": "Cancha 2" } },
  "rescheduled_from": null
}
```

- `status` suma `reprogramada`: la clase se pasó a otro día u horario (`rescheduled_to`). La clase nueva es una
  **recuperación** (`is_makeup: true`, `rescheduled_from` con la original) y funciona como cualquier otra.
- `charge_waived`: la clase suspendida no se cobra (solo temporadas por día de entrenamiento).
- `GET classes/{id}` suma `can_waive_charge`: el grupo tiene una temporada vigente que cobra por día de entrenamiento.

### `POST classes/{id}/suspension` (se amplía)

`{ "reason": "Lluvia", "waive_charge": true }`. Con `waive_charge` (y `can_waive_charge`), la clase no se cobra:

Un cargo emitido nunca se modifica:

- Si la cuota del período todavía no se emitió, sale con un día menos.
- Si se emitió y está impaga, se anula ("Se vuelve a emitir sin la clase suspendida 28/09.") y sale otra con un día
  menos.
- Si ya tiene pagos, queda un descuento pendiente que entra como ajuste "Clase suspendida 28/09" (tipo
  `clase_suspendida`) en la próxima cuota del alumno: al emitirla o, si ya está emitida e impaga, reemitiéndola.

`DELETE classes/{id}/suspension` (o reprogramar) reemite las cuotas que siguen impagas con ese día y borra los
descuentos pendientes. En temporadas con cuota fija (mensual, quincenal, semanal), por clase asistida y por clase
dictada, suspender nunca cambia las cuotas.

**Por clase dictada** (`daily_basis: dictado`, se elige en el panel): como por clase asistida, la cuota se crea al
terminar el período (y los días para corregir la asistencia), con las clases que se dieron: los días con horario sin
las suspendidas ni las reprogramadas, más las recuperaciones. Ahí no hay casilla "No cobrar": lo suspendido nunca se
cobra.

### `POST classes/{id}/reschedule`

`{ "date": "2026-10-03", "starts_at": "09:00", "ends_at": "10:30", "venue_id": 2, "reason": "Lluvia" }`

Crea la recuperación y deja la original `reprogramada`; avisa por push a los tutores del grupo (a todos) y a los otros
técnicos. `venue_id` por defecto el de la original; `reason` opcional. Devuelve la original (como `GET classes/{id}`).
`422` si la original ya empezó o pasó, si la nueva fecha/hora ya pasó, si `ends_at` no es posterior a `starts_at` o
si se superpone con otra clase del grupo ese día. No cambia las cuotas.

### `DELETE classes/{id}/reschedule`

Cancela la reprogramación (antes de que empiece la recuperación y si no tiene asistencia): borra la recuperación y la
original vuelve a `suspendida` (si tenía motivo) o `programada`. Avisa a los tutores.

### `GET venues`

`[{ "id": 1, "name": "Cancha 1" }]` — canchas del club (para reprogramar).

### Avisos configurables

#### `GET me/notification-settings`

```json
{
  "data": {
    "instructor": { "enabled": true, "offsets": [120] },
    "guardian": {
      "offsets": ["eve", 180],
      "students": [ { "id": 12, "first_name": "Mateo", "enabled": true } ]
    },
    "options": [
      { "value": "eve", "label": "El día anterior a las 20:00" },
      { "value": 360, "label": "6 h antes" }, { "value": 180, "label": "3 h antes" },
      { "value": 120, "label": "2 h antes" }, { "value": 60, "label": "1 h antes" },
      { "value": 30, "label": "30 min antes" }
    ],
    "max": 3
  }
}
```

- `offsets`: minutos antes de la clase, o `"eve"` (el día anterior a las 20:00). Sin elegir, los del club
  (tutor: `class_reminder_hours`; técnico: 2 h).
- `instructor` es `null` si el usuario no dirige grupos; `guardian` es `null` si no tiene alumnos a cargo.
- Un aviso que caería entre las 22:00 y las 7:00 sale a las 20:00 del día anterior; dos avisos en el mismo momento
  salen una sola vez. Al tutor, los avisos que siguen al primero solo le llegan si todavía no respondió.

#### `PUT me/notification-settings`

`{ "instructor": { "enabled": true, "offsets": [120, 30] }, "guardian": { "offsets": ["eve", 180] } }` (las dos
partes son opcionales) → como el `GET`. `422` con más de 3 avisos, sin ninguno o con valores fuera de `options`.
El aviso por hijo sigue en `PUT students/{id}/reminders`.

### Push

- Técnico: "Hoy tenés clase con Sub-10 a las 17:00 (Cancha 1) · 15 van, 2 no van, 4 sin responder" →
  `{ "type": "class_today", "route": "/clases/81" }`.
- Reprogramación: `{ "type": "class_rescheduled", "route": "/inicio" }`.
- Aviso de día de clase al tutor: `{ "type": "class_reminder", "route": "/inicio", "class_id": "81",
  "student_ids": "12,13", "going_url": "https://…", "not_going_url": "https://…" }` (valores como texto, como exige FCM).
  En Android llega solo con `data` (la app dibuja la notificación con los botones "Sí, va" y "No va"); en iOS, con la
  categoría `CLASS_REMINDER`.

### `POST class-responses/{class}/{user}?students=12,13&going=1&expires=…&signature=…`

Link firmado del push (sin token), válido hasta que empieza la clase. Responde por los alumnos indicados →
`{ "data": { "message": "Listo: avisaste que Mateo no va." } }`. Alterado o vencido → `403`; clase empezada o
suspendida → `422` con `message`.

## Sprint 5c (implementado)

Clases particulares: el profesor publica su disponibilidad, su precio por clase suelta y sus paquetes; el alumno adulto
o el tutor reserva día y hora. Requieren token + organización con el módulo `private_lessons`. Todas las fechas y horas
son locales de la organización.

- **Clase suelta:** el cargo "Clase particular 06/10" se emite cuando el profesor marca **Vino** (vence ese día). Si el
  alumno pagó antes, el pago quedó como saldo a favor y se aplica solo. "No vino" no se cobra.
- **Paquete:** al comprarlo se emite el cargo "Paquete 4 clases" y el paquete queda `pendiente_pago`; se activa cuando
  el cargo queda pagado (también con saldo a favor). Cada "Vino" descuenta una clase; "No vino" no descuenta.
  Vence a los `valid_days` de activarse (`null` = sin vencimiento); las clases sin usar se pierden, salvo que el
  profesor lo extienda.
- **Forma de pago de una reserva:** se elige sola al reservar. Si el alumno tiene un paquete activo con el profesor,
  con clases libres (`available`: las que quedan menos las ya reservadas) y la clase es hasta el vencimiento, usa el
  paquete; si no, es suelta.

### Objetos

**Reserva**

```json
{
  "id": 40,
  "date": "2026-10-06", "starts_at": "16:00", "ends_at": "17:00",
  "status": "confirmada",
  "payment": "paquete",
  "price": 35000,
  "class_pack_id": 5,
  "teacher": { "id": 7, "name": "Carlos Gómez" },
  "student": { "id": 12, "first_name": "Mateo", "full_name": "Mateo Benítez" },
  "charge": null,
  "can_cancel": true
}
```

- `status`: `confirmada`, `asistio`, `ausente`, `cancelada_alumno` o `cancelada_profesor`.
- La vista del profesor (`teacher/*`) suma `pack` (el paquete que usa) y `credit` (saldo a favor de la familia).
- `payment`: `paquete` o `suelta`. `price`: el de la clase suelta (solo en `suelta`).
- `charge`: el cargo de la clase suelta, cuando se emitió: `{ "id": 301, "amount": 35000, "pending": 0 }`.
- `can_cancel`: confirmada y todavía no empezó.

**Paquete comprado**

```json
{
  "id": 5, "teacher_id": 7, "student_id": 12,
  "classes": 4, "used": 1, "reserved": 1, "available": 2,
  "price": 100000, "valid_days": 60,
  "status": "activo",
  "activated_on": "2026-10-03", "expires_on": "2026-12-01",
  "charge": { "id": 300, "amount": 100000, "pending": 0 }
}
```

- `status`: `pendiente_pago`, `activo`, `terminado` (usó todas) o `vencido`.
- `reserved`: reservas confirmadas que usan el paquete; `available = classes - used - reserved`.
- `activated_on` y `expires_on` son `null` mientras está pendiente; `expires_on` es `null` sin vencimiento.

### Alumno o tutor

#### `GET lessons/teachers`

```json
{
  "data": [
    {
      "id": 7, "name": "Carlos Gómez", "photo_url": null,
      "duration_minutes": 60, "single_price": 35000,
      "packs": [ { "id": 2, "classes": 4, "price": 100000, "valid_days": 60 } ],
      "students": [
        { "student": { "id": 12, "first_name": "Mateo", "full_name": "Mateo Benítez" },
          "pack": { …paquete comprado… }, "next_booking": { …reserva… } }
      ]
    }
  ]
}
```

- Profesores con clases particulares activas. `students`: los alumnos a cargo del usuario (él mismo si es adulto).
- `pack`: el activo o pendiente; si no hay, el último vencido o terminado de los últimos 30 días (para mostrar "Tu
  paquete venció"); `null` si nunca compró.

#### `GET lessons/teachers/{id}/slots?from=2026-10-03&to=2026-10-17`

```json
{ "data": { "duration_minutes": 60, "days": [ { "date": "2026-10-05", "times": ["15:00", "16:00", "18:00"] } ] } }
```

Horas libres: la disponibilidad del profesor partida por la duración de la clase, sin las reservadas ni las que
empiezan antes de la anticipación mínima. Solo días con alguna hora libre. Por defecto, de hoy a `days_ahead` días.

#### `POST bookings`

`{ "student_id": 12, "teacher_id": 7, "date": "2026-10-06", "starts_at": "16:00" }` → `201` con la reserva. Avisa
al profesor. `422` si el alumno no está a cargo, si la hora no está libre (`errors.starts_at`: "Ese horario ya se
reservó. Elegí otro.") o si ya pasó.

#### `GET bookings`

`{ "data": { "upcoming": [ …reservas… ], "past": [ …reservas (últimos 60 días, de la más nueva a la más vieja)… ] } }`
de los alumnos a cargo; `?student_id=12` filtra. Las canceladas no aparecen en `upcoming`.

#### `DELETE bookings/{id}`

Cancela (hasta que empieza) → la reserva con `cancelada_alumno`. Avisa al profesor. No tiene costo.

#### `POST lessons/packs/{pack}/buy`

`{ "student_id": 12 }` → `201` con el paquete comprado (`pendiente_pago`, o `activo` si el saldo a favor lo cubrió).
`422` si ya tiene un paquete pendiente de pago con ese profesor.

### Profesor

`GET organization` suma el permiso `teach_lessons` cuando el profesor tiene las clases particulares activas.
`me/lesson-profile` lo puede usar cualquier usuario con el perfil instructor (para activarlas).

#### `GET me/lesson-profile`

```json
{
  "data": {
    "enabled": true,
    "duration_minutes": 60,
    "single_price": 35000,
    "min_notice_minutes": 120,
    "days_ahead": 30,
    "money_account_id": 1,
    "money_accounts": [ { "id": 1, "name": "Caja" } ],
    "packs": [ { "id": 2, "classes": 4, "price": 100000, "valid_days": 60 } ],
    "availability": [ { "weekday": 1, "starts_at": "15:00", "ends_at": "20:00" } ]
  }
}
```

`weekday`: 1 = lunes … 7 = domingo. Sin perfil, `enabled: false` con valores por defecto.

#### `PUT me/lesson-profile`

El mismo objeto (sin `money_accounts`). Los paquetes sin `id` se crean; los que no vienen dejan de ofrecerse (los ya
vendidos siguen igual). La disponibilidad se reemplaza entera. `422` si un precio no es mayor a 0, si `duration_minutes`
no está entre 30 y 180, si `valid_days` no es `null` o de 1 a 365, si una franja termina antes de empezar o se
superpone con otra del mismo día, o si está activo sin precio o sin disponibilidad.

#### `GET teacher/bookings?from=2026-10-03&to=2026-10-09`

Reservas confirmadas o marcadas del profesor (por defecto, hoy), por fecha y hora. Cada una suma `pack` (el paquete
que usa) y `credit` (saldo a favor de la familia del alumno).

#### `PUT teacher/bookings/{id}/attendance`

`{ "attended": true }` → la reserva. Desde el día de la clase hasta 3 días después; se puede corregir.

- Vino con paquete: descuenta una clase. Vino suelta: emite el cargo (una sola vez) y aplica el saldo a favor.
- Corregir a "No vino": devuelve la clase al paquete o anula el cargo de la suelta. Si ese cargo ya tiene pagos,
  `422` ("Ya está cobrada: anulá el pago desde el panel").

#### `DELETE teacher/bookings/{id}`

`{ "reason": "Estoy enfermo" }` (opcional) → la reserva con `cancelada_profesor`. Avisa al alumno o tutor.

#### `POST teacher/payments`

`{ "student_id": 12, "amount": 35000, "method": "efectivo", "booking_id": 40 }` (`method`: `efectivo` o
`transferencia`; `booking_id` o `class_pack_id`, opcionales) → `201`:

```json
{ "data": { "receipt_number": "000123", "amount": 35000, "applied": 35000, "credit": 0,
            "message": "Cobrado ₲ 35.000.", "booking": { … }, "pack": null } }
```

Entra en la cuenta del perfil. Se imputa primero al cargo de la reserva o del paquete; si no hay cargo todavía (cobro
antes de la clase), queda como saldo a favor. Solo para alumnos con reservas o paquetes con el profesor.

#### `GET teacher/students`

`[{ "student": { … }, "pack": { … } | null, "debt": 35000, "credit": 0, "last_booking": "2026-10-06" }]`, por nombre.
`debt`: lo pendiente de sus cargos de clases particulares.

#### `POST teacher/students/{student}/packs`

`{ "lesson_pack_id": 2 }` → `201` con el paquete comprado (el profesor lo vende y después lo cobra).

#### `POST teacher/packs/{id}/extend`

`{ "expires_on": "2026-12-31" }` → el paquete. Para paquetes activos o vencidos con clases sin usar; si estaba vencido
vuelve a `activo`. Queda en la auditoría.

### Avisos

Usan los momentos de `me/notification-settings` (alumno o tutor: `guardian.offsets`; profesor: `instructor.offsets`;
`instructor` deja de ser `null` si el usuario da clases particulares).

- Al profesor: nueva reserva `{ "type": "lesson_booked", "route": "/particulares/agenda" }`, cancelación
  `lesson_cancelled`, y "Hoy tenés 3 clases: 16:00 Mateo, …" `{ "type": "lesson_today", "route": "/particulares/agenda" }`.
- Al alumno o tutor: "Mañana tenés clase con Carlos a las 16:00" `{ "type": "lesson_reminder", "route": "/reservas" }`,
  cancelación del profesor `lesson_cancelled` (`/reservas`), "Te queda 1 clase del paquete" `pack_low` (`/inicio`) y
  "Tu paquete con Carlos vence el viernes 15/11 y te quedan 2 clases" 7 días y 1 día antes `pack_expiring`
  (`/particulares/7/reservar`).

## Sprint 5d (implementado)

Alta autoservicio y guía "Primeros pasos" del administrador (`PLAN_PRIMEROS_PASOS.md`, etapa 1). El panel usa las
mismas acciones; todo lo que decide (pasos hechos, plantillas, fechas y montos de la temporada) lo calcula la API.

### Cuenta

La cuenta se identifica con el **celular (WhatsApp)** o, si la persona no tiene WhatsApp, con el **correo**. Se
ingresa con cualquiera de los dos y la contraseña. El código de 6 dígitos llega por WhatsApp (plantilla de
autenticación de Meta) o por correo; solo se usa al crear la cuenta y para recuperar la contraseña.

Los teléfonos se guardan y se devuelven en formato internacional (`+595981123456`). La API acepta cualquier forma
habitual (`0981 123 456`, `981123456`, `+595 981 123456`); tiene que ser un **celular** de un país permitido
(Paraguay, Argentina, Brasil, Uruguay, Bolivia por defecto). Si no, `422` sobre `phone`/`login`: "Ingresá un número
de celular válido." o "Ese país no está habilitado. Creá la cuenta con tu correo.".

**Protección contra bots.** Los endpoints que mandan códigos (`auth/register`, `auth/verify/resend`,
`auth/password/forgot`) reciben `captcha_token` (Cloudflare Turnstile) cuando la plataforma lo tiene configurado;
sin token válido, `422` sobre `captcha_token` ("Confirmá que no sos un robot."). También aceptan un campo trampa
`website` que tiene que llegar vacío. Además hay límites por destino (1 por minuto, 3 por hora, 5 por día), por IP y
por cuenta, y un tope diario de la plataforma: al superarlos, `429 {message: "Esperá un momento antes de pedir otro
código."}` sin decir cuál se superó. Si WhatsApp está pausado por la plataforma, el código va por correo cuando la
cuenta lo tiene; si no, `429 {message: "No pudimos mandar el código. Probá de nuevo más tarde."}`.

#### `POST auth/token` (se amplía)

`{ "login": "0981 123 456", "password": "…", "device_name": "app" }` → `201 {token}`. `login` es el celular o el
correo (con `@`). Se sigue aceptando `email` en lugar de `login`. Error `422` sobre `login`: "Estas credenciales no
coinciden con nuestros registros." Después de 10 contraseñas incorrectas, la cuenta queda bloqueada 15 minutos
(`429`, "Demasiados intentos. Probá de nuevo en unos minutos.").

#### `POST auth/register` (público, con throttle)

`{ "name": "Laura Gómez", "phone": "0981 123 456", "email": null, "password": "…", "password_confirmation": "…",
"device_name": "app", "terms": true, "captcha_token": "…" }` → `201 {token}`.

- Se manda `phone` o `email` ("Ingresá tu celular o tu correo."). Con `phone`, el `email` es **opcional**: le llega
  una copia por correo de lo que va por WhatsApp (ver "Copias por correo" abajo). El celular se guarda en formato
  internacional.
- Crea el usuario sin organizaciones y le manda el **código de 6 dígitos** (vence a los 15 minutos) por WhatsApp si
  hay `phone` (y la copia, con otro código, al `email`), si no por correo.
- `422` si el número o el correo ya están **verificados** en otra cuenta ("Ya hay una cuenta con ese número. Ingresá
  con tu contraseña." / "… con ese correo …"). Un celular o un correo **sin verificar** no ocupa el dato: el registro
  nuevo lo libera (borra la cuenta pendiente que lo tenía o se lo saca a la otra cuenta). Las cuentas sin nada
  verificado se borran a las 24 horas.
- `422` si la contraseña tiene menos de 8 caracteres o no coincide, o sin `terms` ("Tenés que aceptar los términos.").
- Guarda la versión y la fecha de los términos aceptados.

#### `POST auth/verify`

`{ "code": "123456" }` → `204`. Sirve el código de WhatsApp o el de su copia por correo, y verifica el canal de ese
código (el de WhatsApp, el celular; el del correo, el correo). Los 5 intentos son entre los dos. `422` sobre
`code`: "El código no es correcto.", "El código venció. Pedí uno nuevo." o, después de 5 intentos fallidos,
"Demasiados intentos. Pedí un código nuevo.".

#### `POST auth/verify/resend` (con throttle)

`{ "channel": "whatsapp" | "mail", "captcha_token": "…" }` → `204`. Manda un código nuevo (el anterior deja de valer).
`channel` es opcional: por defecto WhatsApp si la cuenta tiene celular. `mail` solo si la cuenta tiene correo
(`422` sobre `channel`: "Tu cuenta no tiene correo."). `429` según los límites de arriba.

#### `POST auth/password/forgot` (público, con throttle)

`{ "login": "0981 123 456", "captcha_token": "…" }` → `204` siempre, haya o no una cuenta ("Si hay una cuenta con ese
número, te mandamos un código."). El código va por el canal de `login` (WhatsApp si es un número, correo si es un
correo) y vence a los 15 minutos. Por WhatsApp, la copia va solo a un correo **verificado**. Con un correo sin
verificar de una cuenta con celular no se manda nada (puede ser de otra persona): esa cuenta lo cambia por WhatsApp.

#### `POST auth/password/reset` (público, con throttle)

`{ "login": "0981 123 456", "code": "123456", "password": "…", "password_confirmation": "…", "device_name": "app" }`
→ `201 {token}`. Cambia la contraseña, marca ese canal como verificado, cierra las otras sesiones (borra los demás
tokens) y devuelve uno nuevo. `422` sobre `code` con los mismos mensajes que `auth/verify` (también si no hay cuenta:
"El código no es correcto."), o sobre `password`.

#### `GET me` (se amplía)

Suma `"phone": "+595981123456"` (o `null`), `email` pasa a poder ser `null`, y `"verified": true` (celular o correo
verificado). Con `false`, la app solo deja ingresar el código (o cerrar sesión). Las cuentas creadas por invitación ya
están verificadas.

### Alta del club (con sesión, sin `X-Organization`)

#### `GET onboarding/templates`

Lo que la app y el panel ofrecen como sugerencia:

```json
{
  "data": {
    "organization_types": [
      { "value": "club", "label": "Club", "description": "Club o asociación deportiva",
        "terminology": { "program": "Disciplina", "group": "Categoría", "student": "Jugador", "instructor": "Técnico", "guardian": "Tutor", "space": "Cancha" } },
      { "value": "academy", "label": "Academia", "description": "Academia de deporte, danza, música o idiomas",
        "terminology": { "program": "Disciplina", "group": "Grupo", "student": "Alumno", "instructor": "Profesor", "guardian": "Tutor", "space": "Sala" } }
    ],
    "terminology_options": {
      "student": ["Jugador", "Alumno", "Alumna", "Atleta"],
      "instructor": ["Técnico", "Profesor", "Profesora", "Instructor", "Entrenador"],
      "group": ["Categoría", "Grupo", "Nivel", "Clase"],
      "space": ["Cancha", "Sala", "Aula", "Espacio", "Pileta"],
      "program": ["Disciplina", "Actividad", "Deporte", "Taller"],
      "guardian": ["Tutor", "Responsable", "Encargado"]
    },
    "programs": [
      { "name": "Fútbol", "group_criterion": "birth_year" },
      { "name": "Danza", "group_criterion": "level" }
    ],
    "levels": ["Inicial", "Intermedio", "Avanzado"],
    "ages": { "from": 5, "to": 16, "span": 2 }
  }
}
```

- Tipos: `club`, `academy`, `school` (Escuela) y `parents_association` (Comisión de padres).
- `group_criterion`: `birth_year` (por edad: Sub-8, Sub-10…) o `level` (Inicial, Intermedio…).

#### `GET organizations/slug?value=Club%20Jakare`

`{ "slug": "club-jakare", "available": true, "suggestion": null }`. Pasa `value` a minúsculas y guiones; si no está
libre (o es una palabra reservada), `available: false` y `suggestion` con una alternativa libre (`club-jakare-2`).

#### `POST organizations`

`{ "name": "Club Jakare", "type": "club", "slug": "club-jakare",
"terminology": { "student": "Jugador", "instructor": "Técnico", "group": "Categoría" } }` →
`201 { "data": { "slug": "club-jakare", "name": "Club Jakare", "type": "club" } }`.

- Paraguay, ₲ y `America/Asuncion`. Crea los roles, los conceptos de cobro y la Caja, y deja al usuario como `admin`.
- `terminology` es opcional (por defecto, la del tipo); `program` y `guardian` también se pueden mandar.
- `403` si la cuenta no está verificada ("Verificá tu cuenta para crear un club."). `422` si el slug no está libre,
  es reservado o no tiene solo letras minúsculas, números y guiones (3 a 40 caracteres).
- Después, `GET me` incluye la organización nueva.

### Guía (con `X-Organization`, permiso `configure_organization`)

`GET organization` suma `configure_organization` en `membership.permissions` para el administrador (y el super admin).
Sin ese permiso, los endpoints de esta sección responden `403`.

#### `GET onboarding`

```json
{
  "data": {
    "steps": [
      { "key": "programs", "title": "¿Qué enseñan?", "description": "Las disciplinas del club.",
        "status": "done", "required": true, "skippable": false, "blocked_by": null,
        "summary": "Fútbol y Básquet", "minutes": 1 },
      { "key": "groups", "title": "Categorías y horarios", "description": "Las familias eligen la categoría al inscribirse.",
        "status": "pending", "required": true, "skippable": false, "blocked_by": null, "summary": null, "minutes": 3 },
      { "key": "season", "title": "Temporada y cuotas", "description": "Cuándo empieza, cuánto dura y cuánto se cobra.",
        "status": "pending", "required": true, "skippable": false, "blocked_by": null, "summary": null, "minutes": 3 },
      { "key": "instructors", "title": "Técnicos", "description": "Invitalos para que tomen asistencia desde la app.",
        "status": "locked", "required": false, "skippable": true, "blocked_by": "groups", "summary": null, "minutes": 2 }
    ],
    "done": 1, "total": 4,
    "next": "groups",
    "completed": false,
    "dismissed": false
  }
}
```

- `status`: `done` (existe lo que pide, aunque se haya hecho fuera de la guía), `pending`, `locked` (con `blocked_by`)
  o `skipped`. `done` y `total` cuentan todos los pasos; un paso omitido cuenta como hecho.
- Los títulos usan el vocabulario del club. La app dibuja los pasos que conoce por `key`, en este orden.
- Hechos: `programs` con al menos una disciplina; `groups` con al menos una categoría activa con horario (bloqueado
  sin disciplinas); `season` con una temporada vigente o próxima (bloqueado sin disciplinas); `instructors` con al
  menos un técnico (con el rol vigente, invitado o el propio admin; bloqueado sin categorías).
- `completed`: todos los pasos hechos u omitidos. `next`: el primer paso pendiente (`null` si está completa).
- `dismissed`: la guía se cerró; no se abre sola, pero sigue la tarjeta del inicio hasta completarla.
- La descripción de `programs` dice "… que ofrece el club / la academia / la escuela / la comisión" según el tipo.
- `terminology_suggestion` (vocabulario por deporte, ver `PLAN_VOCABULARIO.md`): `null` o
  `{ "programs": ["Fútbol"], "current": { "student": "Alumno", "instructor": "Profesor", "group": "Grupo", "space": "Sala" },
  "suggested": { "student": "Jugador", "instructor": "Técnico", "group": "Categoría", "space": "Cancha" } }`.
  Cada deporte con lo suyo: de equipo (Fútbol, Futsal, Básquet, Vóley, Handball, Hockey, Rugby) Jugador, Técnico,
  Categoría y Cancha; Natación Alumno, Profesor, Nivel y Pileta; Tenis y Pádel Alumno, Profesor, Nivel y Cancha (se
  compara sin tildes y por la primera palabra: "Fútbol 7"). Manda la primera disciplina elegida que tenga propuesta
  (`programs` la trae). Solo las palabras que siguen como vinieron con el tipo y son distintas, y mientras el
  vocabulario no esté confirmado. La app la muestra al guardar el paso 1 y, si se cierra sin contestar, la recuerda
  en la tarjeta de la guía ("Elegí cómo les dicen", también con la guía completa) hasta que responda con
  `PUT organization/terminology`.

#### `PUT onboarding`

`{ "dismissed": true }` → el mismo objeto. `false` la vuelve a abrir.

#### `PUT onboarding/steps/{key}`

`{ "skipped": true }` → el mismo objeto. `422` si el paso no se puede omitir.

#### `PUT organization/terminology`

"Cómo les dicen" (la propuesta de deporte y "Mi cuenta" → "Cómo les dicen"):
`{ "terminology": { "group": "Categoría", "instructor": "Técnico", "space": "Cancha" } }` →
`{ "data": { "terminology": { "program": "Disciplina", "group": "Categoría", "student": "Alumno", "instructor": "Técnico", "guardian": "Tutor", "space": "Cancha" } } }`.

- Claves `program`, `group`, `student`, `instructor`, `guardian`, `space` (en singular, hasta 30 caracteres; se guarda
  con mayúscula inicial). Las que no se mandan quedan igual; vacía o `null` = la del tipo.
- `{ "terminology": {} }` = "Dejar como estaba": no cambia nada.
- Siempre deja el vocabulario confirmado: `terminology_suggestion` no vuelve a aparecer. También se confirma al
  cambiar el vocabulario en Configuración del panel y al crear la organización con palabras distintas de las del tipo.
- Después, `GET organization` trae el vocabulario nuevo.

### Paso 1: disciplinas

#### `GET setup/programs`

`[{ "id": 1, "name": "Fútbol", "group_criterion": "birth_year", "groups_count": 4 }]`, por nombre.

#### `POST setup/programs`

`{ "programs": [ { "name": "Fútbol", "group_criterion": "birth_year" }, { "name": "Danza", "group_criterion": "level" } ] }`
→ `201` con la lista completa. Las que ya existen con ese nombre (sin importar mayúsculas) no se duplican.

#### `PUT setup/programs/{id}` · `DELETE setup/programs/{id}`

`{ "name", "group_criterion" }` → la disciplina. Borrar responde `422` si tiene categorías ("Tiene categorías: borralas primero.").

### Paso 2: categorías y horarios

**Categoría**

```json
{
  "id": 3, "program": { "id": 1, "name": "Fútbol" },
  "name": "Sub-10", "min_age": 9, "max_age": 10, "level": null, "capacity": 20, "is_active": true,
  "schedules": [ { "weekday": 2, "starts_at": "17:00", "ends_at": "18:30", "venue": { "id": 1, "name": "Cancha 1" } } ],
  "instructors": [ { "id": 7, "name": "Carlos Gómez" } ],
  "enrollments_count": 0
}
```

`weekday`: 1 = lunes … 7 = domingo. Las edades son las que se cumplen en el año de la temporada.

#### `GET setup/groups`

Las categorías, por disciplina y nombre (incluye las inactivas).

#### `POST setup/groups/suggestions`

Genera nombres para revisar antes de crear:
`{ "program_id": 1, "ages": { "from": 5, "to": 16, "span": 2 } }` →
`[{ "name": "Sub-6", "min_age": 5, "max_age": 6, "level": null }, …, { "name": "Sub-16", "min_age": 15, "max_age": 16, "level": null }]`.
Con criterio por nivel: `{ "program_id": 2, "levels": ["Inicial", "Avanzado"] }` → `[{ "name": "Inicial", "level": "Inicial", … }]`.
Omite los nombres que ya existen en esa disciplina.

#### `POST setup/groups`

```json
{
  "program_id": 1,
  "groups": [
    { "name": "Sub-10", "min_age": 9, "max_age": 10, "level": null, "capacity": 20,
      "schedules": [ { "weekday": 2, "starts_at": "17:00", "ends_at": "18:30" } ] }
  ],
  "venue": { "id": 1 }
}
```

→ `201` con la lista de categorías creadas. `venue` es opcional: `{ "id": 1 }` o `{ "name": "Polideportivo", "address": "…" }`
(se crea) y va en todos los horarios del pedido. `422` si un nombre se repite en la disciplina, si un horario termina
antes de empezar o si la edad "hasta" es menor que "desde".

#### `PUT setup/groups/{id}`

El mismo cuerpo de una categoría (`name`, edades o nivel, `capacity`, `is_active`, `schedules` con `venue_id`
opcional) → la categoría. Los horarios se reemplazan enteros. En `POST setup/groups` cada horario también puede
traer su `venue_id` (la cancha); `venue` (todos los horarios) queda para compatibilidad.

#### `DELETE setup/groups/{id}`

`204`. `422` si tiene inscripciones ("Tiene inscripciones: desactivala en vez de borrarla.").

#### Lugares y canchas

Un **lugar** (Polideportivo, con su dirección) tiene una o varias **canchas** (salas, aulas: la palabra es
`term('space')`, por defecto según el tipo: Cancha, Sala, Aula, Espacio). Los horarios y las clases apuntan a la
cancha (`venue_id`). La cancha se muestra "Polideportivo · Cancha 2"; si se llama como el lugar (un lugar con una
sola), solo "Polideportivo". `GET venues` (y `venue.name` en clases, horarios y avisos) ya trae ese nombre.

- `GET setup/sites` → `[{ "id": 1, "name": "Polideportivo", "address": "Av. España 123", "spaces": [ { "id": 11, "name": "Cancha 1", "label": "Polideportivo · Cancha 1" } ] }]`
- `POST setup/sites` `{ "name": "Polideportivo", "address": "…", "spaces": ["Cancha 1", "Cancha 2"] }` → `201` con el lugar.
  Sin `spaces`, una cancha con el nombre del lugar. `422` si el lugar ya existe o hay nombres repetidos.
- `POST setup/sites/{id}/spaces` `{ "name": "Cancha 3" }` → `201` con el lugar. `422` si ya existe en ese lugar.

#### `POST setup/schedules/conflicts`

Choques de horarios que se están cargando, contra los guardados (de categorías activas) y entre sí: misma cancha,
mismo día y horas que se superponen (17:00–18:30 y 18:30–20:00 no chocan). Solo avisa: se puede guardar igual.

`{ "schedules": [ { "key": "0-0", "group_id": null, "group_name": "Sub-8", "weekday": 2, "starts_at": "17:30", "ends_at": "18:30", "venue_id": 11 } ] }`
→ `{ "data": { "0-0": ["Choca con Sub-10 el martes de 17:00 a 18:30 en Polideportivo · Cancha 1."] } }`.
Con `group_id` (editando una categoría) no cuenta sus propios horarios guardados.

**Técnicos con dos categorías a la vez:** `PUT setup/instructors/me`, `PUT setup/instructors/{id}` y
`POST setup/instructors` (si ya era técnico) suman `"warnings": ["Laura Gómez tiene Sub-10 y Sub-12 el martes a las 18:00."]`.

**Reprogramar** (`POST classes/{id}/reschedule`) suma `"warnings": ["Ese día Sub-10 usa Polideportivo · Cancha 1 de 17:00 a 18:30."]`
si otra categoría usa la cancha a esa hora (la clase se reprograma igual).

#### `GET venues` (ya existe) · `POST setup/venues`

`{ "name": "Polideportivo", "address": "Av. España 123" }` → `201 { "id": 2, "name": "Polideportivo" }`.

### Paso 3: temporada y cuotas

Los mismos campos y cálculos que el asistente "Nueva temporada" del panel.

**Estado del asistente**

```json
{
  "program_ids": [1], "kind": "anual", "starts_on": "2027-01-01", "ends_on": "2027-12-31", "name": "2027",
  "fee_frequency": "mensual", "daily_basis": "entrenamiento", "daily_grouping": "mes",
  "fee_amount": 150000, "enrollment_fee_amount": 100000,
  "group_amounts": [ { "group_id": 3, "amount": 180000 } ],
  "due_days": 9, "issue_upfront": false, "mid_period": "completo"
}
```

- `kind`: `anual`, `semestral`, `mensual` o `quincenal`. `fee_frequency`: `mensual`, `quincenal`, `semanal`, `diaria`
  o `null` (sin plan de cobro). Con `diaria`: `daily_basis` (`entrenamiento`, `asistencia` o `dictado`) y
  `daily_grouping` (`dia`, `semana` o `mes`). `mid_period`: `proporcional`, `completo` o `proximo`.
- `program_ids` hace falta solo si hay más de una disciplina (con una, se asigna sola).

#### `GET setup/seasons`

`[{ "id": 4, "name": "2027", "starts_on": "2027-01-01", "ends_on": "2027-12-31", "status": "proxima", "has_fee_plan": true }]`
(`status`: `vigente`, `proxima` o `terminada`), por fecha de inicio descendente.

#### `GET setup/seasons/new`

El estado inicial sugerido (temporada anual desde el próximo 1 de enero, o desde el mes que viene en el primer
semestre; cuota mensual que vence a los días de la configuración de cobros).

#### `POST setup/seasons/preview`

El estado (aunque esté incompleto) →

```json
{
  "data": {
    "dates": { "ends_on": "2027-12-31", "name": "2027" },
    "plan": { "fee_frequency": "mensual", "due_days": 9,
              "due_days_by_frequency": { "mensual": 9, "quincenal": 3, "semanal": 3, "diaria": 5 } },
    "kinds": [ { "value": "anual", "label": "Anual", "example": "1 ene – 31 dic" } ],
    "summary": "2027 de Fútbol, del 01/01/2027 al 31/12/2027. Cuota mensual de ₲ 150.000, que vence el día 10 de cada mes. Inscripción ₲ 100.000. Cada cuota se crea al empezar cada mes.",
    "examples": [ { "period": "enero 2027", "due_on": "10/01/2027", "amount": "₲ 150.000" } ],
    "due_example": "Por ejemplo, «Cuota enero 2027» vence el 10/01/2027.",
    "periods_count": 12
  }
}
```

- `dates`: fin y nombre sugeridos para `kind` y `starts_on` (la app los aplica al cambiar la duración o el inicio).
- `plan`: frecuencia y vencimiento sugeridos para `kind` (la app los aplica al cambiar la duración) y el vencimiento
  sugerido para cada frecuencia (lo aplica al cambiar la frecuencia).
- `kinds`: cada duración con su ejemplo desde `starts_on`.
- `terms` (null sin plan de cobro): las palabras del plan elegido, ya armadas, para no decir "período". La unidad es
  mes, quincena, semana o día (en "por día", la de la agrupación):

```json
"terms": {
  "unit": "semana",
  "issue_now": "Al empezar cada semana",
  "issue_now_help": "La familia ve solo la cuota de la semana en curso.",
  "issue_upfront_help": "La familia ve las 52 cuotas: la de la semana en curso para pagar y el resto como próximas.",
  "issue_after": "Al terminar cada semana",
  "basis_after": "La cuota se crea al terminar cada semana.",
  "midway": "Si alguien se inscribe a mitad de semana, se cobra",
  "mid_period_options": [ { "value": "completo", "label": "La semana completa" },
                          { "value": "proporcional", "label": "Lo que falta de la semana (proporcional)" },
                          { "value": "proximo", "label": "Desde la semana que viene" } ],
  "due_question": "¿Qué día de la semana vence?",
  "due_options": [ { "value": 0, "label": "Lunes" }, { "value": 3, "label": "Jueves" } ],
  "due_text": "el jueves de cada semana"
}
```

  `midway` es `null` cuando no hay "mitad de…" (por día agrupado por día). `due_options` traduce `due_days`: mes →
  "Día 1" a "Día 28"; semana → lunes a domingo; quincena → "A los 3 días (el 4 y el 19)"; día → "El mismo día",
  "Al día siguiente"…; un valor guardado fuera de esos rangos se agrega como "N días después de empezar…".

#### `POST setup/seasons`

El estado → `201` con la temporada (como en `GET setup/seasons`). `422` como el panel: "Tiene que terminar después
de empezar.", "Elegí al menos una disciplina.", monto mayor a 0 con plan de cobro, `due_days` de 0 a 60.

### Paso 4: técnicos

#### `GET setup/instructors`

```json
{
  "data": {
    "me": { "teaches": false, "group_ids": [] },
    "instructors": [
      { "user_id": 7, "invitation_id": null, "name": "Carlos Gómez", "email": "carlos@example.com",
        "status": "activo", "groups": [ { "id": 3, "name": "Sub-10" } ] },
      { "user_id": null, "invitation_id": 12, "name": "Marta Ríos", "email": "marta@example.com",
        "status": "invitado", "groups": [ { "id": 4, "name": "Sub-12" } ] }
    ]
  }
}
```

- `status`: `activo` (tiene el rol vigente), `invitado` (invitación pendiente) o `vencida`. No incluye al usuario actual (va en `me`).

#### `POST setup/instructors`

`{ "name": "Marta Ríos", "phone": "0981 555 444", "email": null, "group_ids": [4] }` → `201` con el técnico y
`"link": "https://app…/invitacion/abc…"` (la única vez que se ve, para compartir por WhatsApp). Uno de `phone` o
`email` es obligatorio ("Ingresá el celular o el correo."). Con correo, también manda la invitación por email; con
celular, el técnico trae `"whatsapp_url": "https://wa.me/595981555444?text=…"` para mandársela a ese número. El rol es
técnico; al aceptarla queda asignado a esas categorías. Si ya es técnico del club (mismo celular o correo), actualiza
sus categorías sin invitar. `422` si el celular o el correo son los propios ("Para vos, usá «Yo también doy clases».").

En `GET setup/instructors`, cada técnico suma `"phone"` (o `null`) y `email` puede ser `null`.

#### `PUT setup/instructors/me`

`{ "teaches": true, "group_ids": [3, 4] }` → el objeto de `GET setup/instructors`. Asigna (o termina) el rol de técnico
del usuario actual y sus categorías.

#### `PUT setup/instructors/{user_id}`

`{ "group_ids": [3] }` → el técnico. Cambia sus categorías.

#### `POST setup/invitations/{id}/resend` · `DELETE setup/invitations/{id}`

Reenviar → `{ "link": "…", "whatsapp_url": "…" }` (token nuevo, 14 días más; `whatsapp_url` solo si la invitación es a un celular). Borrar → `204` (revoca la invitación pendiente).

### Invitaciones (se amplía)

`GET invitations/{token}` suma `"name"` (el que cargó el admin, o `null`) para completar "Nombre y apellido", y
`"phone"`: una invitación va a un correo **o** a un celular (`email` y `phone` pueden ser `null`, nunca los dos).
`user_exists` busca la cuenta por cualquiera de los dos. Al aceptar una invitación por celular, la cuenta nueva queda
con ese celular verificado (el link llegó a ese WhatsApp), igual que con el correo.

## Comprobantes de transferencia (implementado)

El tutor informa un pago por transferencia con el comprobante (foto o PDF); queda **pendiente** hasta que alguien con
el permiso "Validar comprobantes" (administrador y tesorero por defecto) lo aprueba o lo rechaza, en el panel o en la
app. Recién al aprobarlo se registra el pago (con su recibo) y impacta en la cuenta (`business-logic.md` regla 13).

### Objeto comprobante

```json
{
  "id": 31,
  "amount": 210000,
  "paid_on": "2026-10-02",
  "reference": "Transf. 99812",
  "notes": null,
  "status": "pendiente",
  "status_label": "En revisión",
  "rejection_reason": null,
  "money_account": { "id": 2, "name": "Banco Itaú" },
  "charges": [
    { "id": 501, "description": "Cuota octubre 2026", "student_first_name": "Sofía", "pending_amount": 60000 }
  ],
  "proof_url": "https://api.example.com/comprobantes-de-pago/31?expires=…&signature=…",
  "proof_name": "comprobante.jpg",
  "created_at": "2026-10-02T21:14:00-03:00",
  "reviewed_at": null,
  "receipt_number": null,
  "receipt_url": null
}
```

- `status`: `pendiente` ("En revisión"), `aprobado` ("Aprobado") o `rechazado` ("Rechazado").
- `money_account`: la cuenta a la que dice haber transferido (o `null`). `charges`: las cuotas que eligió pagar
  (`pending_amount` es lo que falta hoy; vacío = pago a cuenta).
- `proof_url`: link firmado y temporal (30 minutos) al archivo; se abre sin token.
- Aprobado: `receipt_number` y `receipt_url` del pago que se registró. Rechazado: `rejection_reason`.

### Tutor

#### `GET account` (se amplía)

- `transfer_accounts`: cuentas bancarias o billeteras activas con datos para transferir, para mostrarlos antes de
  informar el pago: `[{ "id": 2, "name": "Banco Itaú", "details": "Cuenta corriente 123456\nTitular: Club Jakare\nRUC 80012345-6" }]`.
- `payment_reports`: últimos comprobantes de la familia (máx. 20, del más nuevo al más viejo), con el objeto de arriba.
- `pending_reports_amount`: suma de los comprobantes en revisión (para mostrar "₲ 210.000 en revisión").

En `GET students/{id}/account` vienen los comprobantes que incluyen cuotas de ese hijo.

#### `POST payment-reports` (multipart)

| Campo | |
|---|---|
| `amount` | entero, obligatorio, > 0 |
| `paid_on` | fecha, obligatoria, no futura |
| `proof` | archivo obligatorio: jpg, png, webp, heic o pdf, hasta 5 MB |
| `charge_ids[]` | opcional: cuotas pendientes de sus hijos (todas de la misma familia) |
| `money_account_id` | opcional: una de `transfer_accounts` |
| `reference` | opcional, hasta 100 |
| `notes` | opcional, hasta 500 |

→ `201` con el comprobante. La familia sale de las cuotas elegidas; sin cuotas, de sus hijos (si tiene hijos en más
de una familia → `422` "Elegí qué cuotas pagás."). Una cuota que ya está en otro comprobante en revisión → `422`
"Ya informaste un pago para «Cuota octubre 2026»; esperá a que lo revisen.". Avisa por push a quienes validan.

#### `DELETE payment-reports/{id}`

Retira un comprobante propio en revisión → `204`. Ya revisado → `422`.

### Quien valida (permiso `review_payment_reports` en `GET organization`)

#### `GET payment-reports?status=pendiente`

`status` opcional (`pendiente` por defecto; `todos` para los últimos 50). Cada comprobante suma:

```json
{
  "family": { "id": 7, "name": "Familia Benítez", "students": ["Sofía", "Mateo"] },
  "reported_by": "Ana Benítez",
  "pending_balance": 270000,
  "money_accounts": [{ "id": 1, "name": "Caja" }, { "id": 2, "name": "Banco Itaú" }]
}
```

- `pending_balance`: lo que la familia debe hoy (todas sus cuotas pendientes).
- `money_accounts`: cuentas activas donde puede entrar el pago.

#### `POST payment-reports/{id}/approve`

`{ "money_account_id": 2, "received_on": "2026-10-02", "amount": 210000 }` (todo opcional: por defecto la cuenta
informada o la primera bancaria, la fecha y el monto del comprobante) → el comprobante aprobado. Registra el pago por
transferencia (referencia y comprobante incluidos) imputado a las cuotas elegidas que sigan pendientes, del
vencimiento más viejo al más nuevo; lo que sobra queda como saldo a favor. Ya revisado → `422`. Push al tutor:
"Aprobamos tu pago de ₲ 210.000. Recibo N° 000124.".

#### `POST payment-reports/{id}/reject`

`{ "reason": "El comprobante no se lee." }` (obligatorio) → el comprobante rechazado. Ya revisado → `422`. Push al
tutor: "No pudimos aprobar tu pago de ₲ 210.000: El comprobante no se lee.".

### Push

Con copia por correo a quien tenga un correo para copias (ver «Copias por correo y marca»).
`data`: `{ "type": "payment_report", "route": "/comprobantes" }` para quien valida y
`{ "type": "payment_report_reviewed", "route": "/estado-de-cuenta" }` para el tutor.

`"phone"`: una invitación va a un correo, a un celular o a los dos (`email` y `phone` pueden ser `null`, nunca los
dos). Con correo, le llega por email; con celular, quien invita la comparte por WhatsApp (`whatsapp_url`). `user_exists`
busca la cuenta por el celular o el correo **verificados**. Al aceptar, la cuenta nueva queda con el celular verificado
(el link llegó a ese WhatsApp) o, sin celular, con el correo verificado. Con los dos, el correo queda sin verificar y
le llega "Confirmá tu correo" (link para empezar a recibir las copias).

## Copias por correo y marca (2026-10-03)

Todo lo que sale por WhatsApp sale también por correo, y los correos y los mensajes de WhatsApp llevan la marca Tuku.
Para la app no cambia ningún endpoint salvo lo de arriba (`auth/register` con correo opcional, `auth/verify` con el
código de la copia, invitaciones a celular y correo).

- **Correo para copias** de una cuenta: el verificado o, si la cuenta no tiene celular, el correo con el que se creó.
  Un correo opcional sin verificar recibe solo la copia del código para confirmar la cuenta, con el botón
  "Confirmar mi correo" (link firmado de 7 días a `GET /correo/confirmar/{user}/{hash}`; vencido, manda uno nuevo).
- **Códigos:** la copia por correo trae su propio código (el de WhatsApp verifica el celular; el del correo, el correo).
- **Invitaciones:** con celular y correo, le llega por correo y quien invita la comparte por WhatsApp (texto con la
  marca: "Hola Ana, te invito a sumarte a *Club Jakare* en *Tuku*, la app de cuotas, asistencia y avisos de clase.
  Creá tu cuenta desde este link: … Vence el 17/10 y sirve una sola vez.").
- **Avisos:** cada push (día de clase, clase suspendida o reprogramada, clases particulares, paquetes, aviso al técnico)
  va también por correo, con el título, el texto y un botón que abre la app web en la `route` del aviso. El aviso de
  día de clase trae "Sí, va" / "No va": abren una página (`GET /clases/{class}/respuesta/{user}?students=…&going=…`,
  firmada, vence al empezar la clase) y la respuesta se guarda con el botón de la página (un `POST` al mismo link),
  porque los antivirus de correo abren los links solos.
- **Vista previa de los links:** la app web tiene las etiquetas `og:` de Tuku (título, descripción y la tarjeta
  `https://tukuha.app/brand/tuku-tarjeta-redes.png`), que WhatsApp muestra al compartir una invitación.
