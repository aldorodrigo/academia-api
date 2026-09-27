# Lógica de negocio

Documento maestro. Toda regla de negocio implementada debe estar acá; si el código y este
documento difieren, se corrige uno de los dos en el mismo cambio.

> Idioma del producto: **solo español**. Moneda del piloto: **guaraníes (PYG)**.

---

## 1. Organizaciones (tenancy)

- Una **organización** es un cliente del SaaS: club, academia, escuela o comisión de padres (ACE).
- Tipos: `club`, `academy`, `school`, `parents_association`.
- Cada organización tiene: nombre, slug único, país, moneda, zona horaria, **vocabulario** y **módulos activos**.
- **Aislamiento total:** ningún usuario ve datos de una organización a la que no pertenece.
- **Vocabulario configurable** (etiquetas, no traducciones). Valores por defecto:

  | Clave | Por defecto | Ej. academia de danza |
  |---|---|---|
  | `program` | Disciplina | Estilo |
  | `group` | Categoría | Nivel |
  | `student` | Jugador | Alumna/o |
  | `instructor` | Técnico | Profesor/a |
  | `guardian` | Tutor | Tutor |

- **Módulos opcionales** (`features`): `board` (comisión, actas, resoluciones), `fundraising` (rifas),
  `apparel` (indumentaria), `tournaments`, `evaluations`, `electronic_invoicing` (SIFEN).
  Los **habilita la plataforma** (super admin): son lo que la organización contrata. El admin de la
  organización los ve pero no los cambia; sí edita el vocabulario.
- **Alta:** la hace un super admin desde `/plataforma` (datos, módulos y el email del **primer
  administrador**, que recibe una invitación con rol `admin`). Los roles base se crean solos.
  El slug no se cambia después del alta (es la URL del panel y el header `X-Organization`).
- **Estado:** `activa` o `suspendida` (con motivo). Una organización suspendida:
  - no aparece para sus miembros (ni en la app ni en el panel) y la API responde 403
    "La organización está suspendida.";
  - sus invitaciones no se pueden ver ni aceptar;
  - conserva todos sus datos y sus tareas programadas; el super admin sigue entrando (soporte).
  - Suspender y reactivar queda en el registro de actividad. No hay borrado desde el panel.

## 2. Usuarios, membresías y roles

- Un **usuario** puede pertenecer a **varias organizaciones** (membresía `active` / `inactive`)
  con roles distintos en cada una.
- Solo entra a una organización con membresía **activa**.
- Una persona puede tener **varios roles** a la vez (ej. tutor + tesorero); la app muestra todos sus perfiles.

### Super admin y admin
- **Super admin** de la plataforma (`users.is_super_admin`): acceso total a todas las organizaciones
  (alta de organizaciones, soporte, Horizon). No es un rol de organización: se otorga o quita solo
  desde `/plataforma` → Usuarios o por consola (`users:super-admin {email} [--revoke]`), nunca por
  invitación, y queda auditado. Nadie puede quitarse su propio acceso y siempre queda al menos uno.
- **Admin** de la organización (rol `admin`): acceso total **solo dentro de su organización**
  (configuración, vocabulario, módulos, miembros, invitaciones, roles y permisos).

### Roles base
Cada organización nace con estos roles (`App\Enums\OrganizationRole`). Los permisos finos se
editan en Shield; † = funcionalidad todavía no construida, el alcance del rol ya queda definido.

| Rol | Tipo | Qué puede hacer |
|---|---|---|
| `admin` | Organización | Todo dentro de su organización |
| `presidente` | Cargo | Ve todo; aprueba resoluciones†, becas† y gastos sobre el umbral† (con el tesorero); firma actas† |
| `vicepresidente` | Cargo | Ve todo; reemplaza al presidente |
| `secretario` | Cargo | Reuniones, actas y resoluciones†; avisos y comunicados†; ve miembros y alumnos |
| `prosecretario` | Cargo | Asiste y suple al secretario |
| `tesorero` | Cargo | Cuentas, tarifas, cuotas, becas, mora†; pagos y recibos†; gastos†, comprobantes†, aprueba gastos sobre el umbral† (con el presidente); informes† |
| `protesorero` | Cargo | Suple al tesorero, salvo aprobar gastos sobre el umbral |
| `vocal` | Cargo | Lee actas, resoluciones e informes†; vota en reuniones† |
| `sindico` | Cargo | Lee todo lo financiero y el registro de actividad†; no modifica nada |
| `instructor` | Organización | Sus grupos: alumnos, asistencia†, convocatorias†, avisos al grupo†; ficha médica de sus alumnos (lectura)† |
| `tutor` | Organización | Sus hijos: ficha, inscripciones†, estado de cuenta†, recibos†, avisos†; confirma asistencia† y sube comprobantes† |

`instructor` y `tutor` se muestran con el vocabulario de la organización (ej. "Técnico", "Profesor/a").

### Asignaciones y mandatos
- **`role_assignments` es la fuente de verdad** de quién tiene qué rol y desde/hasta cuándo;
  `model_has_roles` (spatie) se sincroniza desde ahí. Terminar una asignación no la borra:
  queda como **historial** (comisiones anteriores).
- Los **cargos de comisión** exigen fecha de fin del mandato; el fin no puede ser anterior al inicio.
- **Vencimiento automático:** `roles:expire` corre todos los días (00:05, Asunción) y termina los
  mandatos cuyo fin ya pasó **según la fecha local de cada organización**; también activa los que
  empiezan ese día. Todo queda en el registro de actividad.

### Invitaciones
- Alta de usuarios **solo por invitación**; no hay registro abierto.
- La invitación tiene email, roles (con mandato para los cargos), quién invitó y vencimiento a los
  **14 días**. Sirve **una sola vez**.
- El link `{APP_FRONTEND_URL}/invitacion/{token}` llega por email (con QR) y se muestra en el panel
  **una sola vez**: el token se guarda solo hasheado (sha256). "Reenviar" genera un token nuevo.
- Una invitación nueva para el mismo email **revoca** la pendiente anterior. Se puede revocar a mano.
- Al aceptar: si no existe cuenta con ese email se crea (nombre + contraseña de 8+ caracteres);
  si existe, se pide su contraseña actual. Se activa la membresía, se asignan los roles (sin
  duplicar los que ya tiene) y se devuelve el token de la app.

## 3. Estructura académica *(Sprint 2)*

```
Organización → Programa (fútbol, pádel…) → Grupo (Sub-10, Inicial…) → Horarios
```

- **Temporada:** período (ej. 2026). Una sola temporada `actual` por organización.
- **Inscripción** = alumno + grupo + temporada. Un alumno puede tener varias (ej. fútbol y pádel).
- Estados de inscripción: `pendiente`, `activo`, `becado`, `suspendido`, `baja`.
- Una inscripción de una temporada que ya no es la actual está **finalizada** (sin estado propio):
  no genera cuotas ni se muestra en la app, y queda como historial. Solo generan cuota las
  inscripciones `activo` o `becado` de la temporada actual (`Enrollment::billable()`).
- **Pase de temporada:** se reinscribe en bloque a los jugadores `activo`, `becado` o `pendiente`
  de la temporada anterior, con la categoría que corresponde por edad; los que no siguen quedan como están.
- Criterio de grupo por programa: año de nacimiento (fútbol) o nivel (pádel, danza).
- **Familia:** agrupa alumnos y tutores. Un tutor puede tener varios hijos; un hijo varios tutores.
- Alumno adulto sin tutor: es su propio responsable.
- Ficha médica: visible solo para roles autorizados.

## 4. Dinero — reglas generales

- Montos en **enteros** (guaraníes sin decimales). Porcentajes se redondean al guaraní entero.
- **Libro mayor inmutable:** un movimiento no se edita ni se borra; se **anula** con un
  contra-movimiento, con motivo y auditoría.
- **Saldo de una cuenta = suma de sus movimientos.** Nunca se edita a mano.
- Cuentas: banco, caja (efectivo), billetera digital.

## 5. Tarifas y cuotas *(Sprint 3)*

- **Tarifa** = concepto (inscripción, cuota mensual, torneo…) + grupo + temporada + monto + vigencia.
- Cambiar una tarifa **no altera** cargos ya emitidos; rige desde su fecha de vigencia.
- **Cuota mensual automática** para inscripciones `activo` o `becado` de la temporada actual (la beca total no genera cuota; la parcial se aplica como ajuste; `baja` no se cobra).
  - La generación es **idempotente**: lock en Redis + índice único en BD
    (inscripción + concepto + período). Nunca se cobra dos veces el mismo mes.
- Todo **cargo** pertenece a un alumno (y a su inscripción) y guarda: monto base,
  ajustes aplicados (descuentos, becas, recargos) y monto final.

## 6. Descuentos y becas *(Sprint 3)*

- Regla de descuento: tipo (`hermanos`, `beca`, `convenio`, `pronto_pago`, `otro`),
  porcentaje **o** monto fijo, conceptos a los que aplica, vigencia.
- **Hermanos:** según la posición del hijo entre las inscripciones activas de la familia
  (ej. 2º −20 %, 3º −50 %). Configurable.
- **Beca:** parcial (%) o total, con motivo, vigencia y **aprobación** de quien tenga el permiso "Aprobar becas". Se aplica a la cuota mensual desde su vigencia.
- Orden de aplicación configurable. Un cargo **nunca** queda negativo.

## 7. Mora *(Sprint 3)*

- Configurable por organización (y opcionalmente por concepto): día de vencimiento,
  días de gracia, recargo fijo o %, frecuencia (una vez / por mes), tope.
- Se puede desactivar o **exonerar** un cargo puntual (con motivo y auditoría).
- Recordatorios: antes del vencimiento, el día del vencimiento y cada N días de atraso.

## 8. Cobros *(Sprint 4)*

- La **cuenta corriente** es por alumno; la **familia** ve el consolidado de sus hijos.
- Un **pago** se **imputa** a uno o varios cargos, de uno o varios hijos
  (por defecto los más antiguos primero; el tesorero puede elegir).
- Pago de más → **saldo a favor** de la familia, que se aplica solo al próximo cargo (el recibo
  original no cambia: muestra lo imputado ese día y el saldo a favor que dejó).
- **Pronto pago:** si un pago salda la cuota hasta el día configurado de su mes, se descuenta al
  imputarlo (el cargo no se modifica).
- Anular un pago: los cargos vuelven a pendientes y se registra el contra-movimiento en la cuenta.
- Recargos por mora: configurados, todavía no se generan.
- Cada pago genera un **recibo PDF**.
- **Comprobante subido por el padre** (transferencia) queda `pendiente` hasta que el
  tesorero lo valida; recién ahí impacta en la cuenta. *(Fase 2)*

## 9. Gastos *(Sprint 4)*

- Cada gasto: cuenta de salida, categoría, proveedor, comprobante adjunto.
- Gastos por encima de un **umbral configurable** requieren **doble aprobación**
  (ej. tesorero + presidente).
- Transferencias entre cuentas (ej. caja → banco) son dos movimientos enlazados.

## 10. Comunicación *(Sprint 5)*

- Avisos segmentados: toda la organización, programa, grupo, familia.
- Push (Firebase) + email; confirmación de lectura.
- Envíos masivos por lotes en la cola `notifications` (Horizon).
- Una **resolución** publicada a un grupo notifica a sus tutores y puede generar un cargo. *(Fase 2)*

## 11. Fechas y horarios

- Se guardan en **UTC**; se muestran en la zona horaria de la organización
  (por defecto `America/Asuncion`).

## 12. Auditoría y datos sensibles

- Todo lo financiero y los cambios de roles quedan en el registro de actividad.
- Datos de menores: acceso mínimo por rol; ficha médica solo para roles autorizados.
