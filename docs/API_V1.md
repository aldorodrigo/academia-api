# API v1 — contrato

Base: `/api/v1`. Todas las respuestas en JSON y en español. Los errores de validación usan el formato estándar de Laravel (`422` con `message` y `errors`).

Headers:
- `Authorization: Bearer {token}`: en los endpoints autenticados.
- `X-Organization: {slug}`: en los endpoints que dependen de la organización activa.

Este documento se reemplaza por la especificación OpenAPI en el Sprint 5.

## Sprint 0 (implementado)

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| POST | `auth/token` | — | `{email, password, device_name}` → `201 {token}` |
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

- Si la cuota del período todavía no se emitió, sale con un día menos.
- Si se emitió y está impaga, se le agrega el ajuste "Clase suspendida 28/09" (monto negativo, tipo `clase_suspendida`).
- Si ya tiene pagos, el ajuste va a la próxima cuota de la inscripción (cuando se emita). Si no hay más cuotas, queda
  como saldo a favor de la familia.

`DELETE classes/{id}/suspension` quita esos ajustes de las cuotas que siguen impagas. En temporadas con cuota fija
(mensual, quincenal, semanal) y por clase asistida, suspender nunca cambia las cuotas.

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
