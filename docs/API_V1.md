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

## Sprint 4b (contrato)

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
