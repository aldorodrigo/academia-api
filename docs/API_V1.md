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
