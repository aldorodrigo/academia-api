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
- **Alta:** de dos formas. Los roles base, los conceptos de cobro y la Caja se crean solos.
  El slug no se cambia después del alta (es la URL del panel, el header `X-Organization` y el link
  de inscripción); tiene 3 a 40 caracteres (minúsculas, números y guiones) y hay palabras reservadas
  que chocan con rutas (`admin`, `api`, `new`, `inscripcion`…: `App\Support\Organizations\Slug`).
  - **Desde `/plataforma`:** un super admin carga los datos, los módulos y el email del **primer
    administrador**, que recibe una invitación con rol `admin`.
  - **Autoservicio** (Sprint 5d, app y panel): cualquier cuenta con el **email verificado** crea su
    club ("Tu club": nombre, tipo y vocabulario) y queda como `admin`. Paraguay, ₲ y Asunción;
    sin módulos (los sigue habilitando la plataforma). Queda marcada `self_service` y en el
    registro de actividad.
- **Guía "Primeros pasos"** (Sprint 5d, `App\Support\Onboarding\Checklist`, la misma en la app y en
  el panel, solo para el admin): disciplinas → categorías con horario → temporada vigente o próxima
  → técnicos (se puede omitir). Cada paso está **hecho si existe lo que pide**, aunque se haya hecho
  fuera de la guía; uno omitido cuenta como hecho. Se abre sola (app: una vez por sesión; panel:
  el Escritorio lleva a la guía) mientras esté incompleta y no se haya cerrado; las organizaciones
  que ya existían quedaron con la guía cerrada. La primera vez que se completa queda la fecha.
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
- La cuenta se identifica con el **celular (WhatsApp)** o con el **correo** (o los dos); los dos son únicos y se
  entra con cualquiera de ellos y la contraseña (app y panel). Los teléfonos se guardan en formato
  internacional (`+595981123456`) y se muestran como `0981 123 456`.
- Cada dato se verifica por separado (`phone_verified_at`, `email_verified_at`). Un celular o un correo **sin
  verificar no ocupa el dato**: no identifica a la cuenta (invitaciones, registro) y una cuenta nueva con ese dato
  se lo saca a la otra.

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

### Permisos por defecto y Escritorio
- Cada rol base **nace con los permisos de su función** (`App\Support\Roles\DefaultPermissions`); el admin los
  cambia en Roles. Presidente y vicepresidente: ver todo e informes (el presidente, también aprobar becas). Tesorero y
  protesorero: cobros, cuotas, gastos, cuentas, tarifas, becas y descuentos, ver alumnos y grupos, informes (el
  tesorero, también aprobar becas). Secretario y prosecretario: alumnos, tutores, inscripciones e invitaciones; ver
  grupos, temporadas y miembros. Vocal: informes. Síndico: ver lo financiero e informes. Admin, técnico y tutor: nada
  extra (el admin pasa por todo; técnico y tutor usan sus grupos e hijos).
- Se aplican al crear el rol; `organizations:sync-roles` los completa en los roles base que no tienen **ningún**
  permiso (no pisa lo que cambió el admin).
- **Quien no es admin solo invita o asigna tutores y técnicos** (no cargos ni administrador).
- **Escritorio del panel:** arriba la guía (hasta completarla), botones a lo principal y tarjetas que se muestran por
  **permiso** (sirven para roles creados a mano): Hoy (clases del día y asistencia), Cobranza del mes (cobrado, falta
  del mes, vencido con días de gracia, vence esta semana), Morosos (5 familias con más deuda, con WhatsApp), Alumnos
  (activos, altas y bajas del mes, cupo por grupo), Caja, Para hacer (cada cosa para quien la puede resolver),
  Cumpleaños y Balance del mes. **App:** la guía en el inicio y botones según permisos (Mis grupos, Estado de cuenta,
  Mis reservas, Agenda, Informes).

### Asignaciones y mandatos
- **`role_assignments` es la fuente de verdad** de quién tiene qué rol y desde/hasta cuándo;
  `model_has_roles` (spatie) se sincroniza desde ahí. Terminar una asignación no la borra:
  queda como **historial** (comisiones anteriores).
- Los **cargos de comisión** exigen fecha de fin del mandato; el fin no puede ser anterior al inicio.
- **Vencimiento automático:** `roles:expire` corre todos los días (00:05, Asunción) y termina los
  mandatos cuyo fin ya pasó **según la fecha local de cada organización**; también activa los que
  empiezan ese día. Todo queda en el registro de actividad.

### Registro abierto *(Sprint 5d)*
- Cualquiera puede **crear una cuenta** (nombre, **celular** o, si no tiene WhatsApp, **correo**, contraseña de
  8+ y aceptación de los términos, con versión y fecha). Con el celular, el **correo es opcional**: recibe una copia
  de lo que va por WhatsApp. La cuenta nace **sin organizaciones** y sin verificar.
- Se verifica con un **código de 6 dígitos** que llega **por WhatsApp** (WhatsApp Cloud API de Meta, plantilla de
  autenticación; solo para códigos, los avisos van por push y por correo) o por correo. Si va por WhatsApp y la cuenta
  tiene correo, sale también una **copia por correo con su propio código**: el de WhatsApp verifica el celular y el
  del correo, el correo. Vence a los 15 minutos, 5 intentos entre los dos;
  pedir otro reemplaza el anterior. Sin verificar, la app y el panel solo piden el código; la API no deja crear
  un club.
- Una cuenta **sin verificar no ocupa** el celular ni el correo: un registro nuevo con el mismo dato la reemplaza
  y `accounts:prune-unverified` (cada hora) borra las de más de 24 horas.
- **"Olvidé mi contraseña"** (app y panel): código por WhatsApp si se ingresa el celular (copia solo a un correo
  verificado), por correo si se ingresa el correo (solo si está verificado o la cuenta no tiene celular: un correo
  opcional sin confirmar puede ser de otra persona). No revela si hay una cuenta. Al cambiarla se cierran las otras
  sesiones.
- Con la cuenta verificada y sin organizaciones, la app y el panel llevan a "Tu club".
- **Protección del envío de códigos** (`CodeGuard`, igual para WhatsApp y correo):
  - Cloudflare Turnstile en crear cuenta, reenviar y "Olvidé mi contraseña" (si está configurado), campo trampa
    y, en el panel, un tiempo mínimo de llenado.
  - Solo celulares válidos de países permitidos (`WHATSAPP_ALLOWED_COUNTRIES`, por defecto PY, AR, BR, UY, BO).
  - Límites por destino (1 por minuto, 3 por hora, 5 por día), por IP (10 por hora, 30 por día) y por cuenta
    (5 por día); 3 códigos agotados en el día bloquean ese destino. El login bloquea la cuenta 15 minutos
    después de 10 contraseñas incorrectas.
  - Tope diario de WhatsApp (`WHATSAPP_DAILY_LIMIT`, 300) y **corte automático** si en la última hora se mandaron
    más de 20 códigos y se usó menos del 30 %. En pausa, el código sale por correo a quien lo tiene; se avisa a
    los super admin y se reanuda desde el panel de plataforma ("Códigos de verificación").
- Los **roles** (admin de otro club, cargos, técnicos, tutores de alumnos cargados por el club)
  se siguen dando **por invitación**.

### Copias por correo y marca *(2026-10-03)*
- **Todo lo que sale por WhatsApp sale también por correo**: códigos (copia con su propio código) e invitaciones.
  Los **avisos** (push) van también por correo: día de clase, clase suspendida o reprogramada, clases particulares,
  paquetes y el aviso al técnico.
- El correo de las copias es el **verificado** o, si la cuenta no tiene celular, el correo con el que se creó. Un
  correo opcional se confirma con su código o con el botón "Confirmar mi correo" (link de 7 días; vencido, se manda
  otro). Así un correo mal escrito no recibe datos de los chicos.
- El aviso de día de clase por correo trae "Sí, va" / "No va": abren una página que guarda la respuesta con su botón
  (no al abrir el link, porque los antivirus de correo lo abren solos). Vence al empezar la clase.
- **Marca:** los correos llevan el logo, los colores, las tipografías y la mascota de Tuku (`hola` en bienvenidas e
  invitaciones, `salta` en reservas, `descansa` en clases suspendidas; nunca junto a deudas). El número de WhatsApp
  tiene el perfil de Tuku (nombre, foto, presentación, sitio) y la plantilla de autenticación en español con
  "Copiar código" (`php artisan whatsapp:brand`, ver `docs/WHATSAPP.md`).

### Invitaciones
- Alta de roles **por invitación** (el registro abierto da una cuenta sin organización).
- La invitación va a un **correo, a un celular o a los dos**, con roles (con mandato para los cargos), quién invitó
  y vencimiento a los **14 días**. Sirve **una sola vez**.
- El link `{APP_FRONTEND_URL}/invitacion/{token}` llega por email (con QR) y, si hay celular, quien invita lo
  manda por WhatsApp (`wa.me` a ese número, sin costo, con el texto de la marca). Un tutor con celular y correo la
  recibe por los dos lados. Se muestra en el panel **una sola vez**: el token se guarda solo hasheado (sha256).
  "Reenviar" genera un token nuevo.
- Una invitación nueva para el mismo correo o celular **revoca** la pendiente anterior. Se puede revocar a mano.
- Un tutor con solo celular se invita uno por uno (el panel muestra el link para WhatsApp); la importación y la
  invitación masiva mandan por correo a los que lo tienen.
- Al aceptar: si no existe cuenta con ese correo o celular verificados se crea (nombre + contraseña de 8+
  caracteres) y queda verificado el celular (o, sin celular, el correo); con los dos, al correo le llega
  "Confirmá tu correo";
  si existe, se pide su contraseña actual. Se activa la membresía, se asignan los roles (sin
  duplicar los que ya tiene) y se devuelve el token de la app.
- La invitación de un **técnico** desde la guía guarda su nombre (completa "Nombre y apellido" al
  aceptar) y sus **categorías**: al aceptar queda asignado a las que sigan existiendo. Si el celular o el
  correo ya es de un técnico del club, se le asignan las categorías sin invitar. El admin que también da
  clases ("Yo también doy clases") recibe el rol `instructor` y sus categorías sin invitación.

## 3. Estructura académica *(Sprint 2)*

```
Organización → Programa (fútbol, pádel…) → Grupo (Sub-10, Inicial…) → Horarios
```

- **Temporada:** período de una o varias disciplinas (ej. "2026" de fútbol, "Colonia de verano" de
  fútbol y pádel), con duración anual, semestral, mensual o quincenal. Está **vigente según sus fechas**:
  puede haber varias a la vez y un jugador puede estar en la anual y en una colonia. Sin disciplinas, vale para todas.
- **Inscripción** = alumno + grupo + temporada. Un alumno puede tener varias (ej. fútbol y pádel).
  Solo se inscribe en temporadas vigentes o próximas de la disciplina de la categoría.
- Estados de inscripción: `pendiente`, `activo`, `becado`, `suspendido`, `baja`.
- Una inscripción de una temporada que ya terminó está **finalizada** (sin estado propio):
  no genera cuotas ni se muestra en la app, y queda como historial. Solo generan cuota las
  inscripciones `activo` o `becado` de temporadas vigentes (`Enrollment::billable()`).
- **Pase de temporada:** se reinscribe en bloque a los jugadores `activo`, `becado` o `pendiente`
  de la temporada anterior (de las disciplinas de la nueva), con la categoría que corresponde por edad;
  los que no siguen quedan como están. Se ofrece al crear la temporada.
- Criterio de grupo por programa: año de nacimiento (fútbol) o nivel (pádel, danza).
- **Familia:** agrupa alumnos y tutores. Un tutor puede tener varios hijos; un hijo varios tutores.
- Alumno adulto sin tutor: es su propio responsable.
- Ficha médica: visible solo para roles autorizados.

### Lugares, canchas y choques *(Sprint 5d)*
- Un **lugar** (nombre y dirección) tiene una o varias **canchas** (salas, aulas, pileta: la palabra sale del
  vocabulario, `space`, según el tipo: Cancha, Sala, Aula, Espacio). Cada horario y cada clase usa una cancha.
  Se muestra "Polideportivo · Cancha 2", o solo el lugar si tiene una sola con su mismo nombre. "Cancha 1" se puede
  repetir en lugares distintos. Un lugar con horarios no se borra.
- **Choques** (solo avisos; se guarda igual, por ejemplo si comparten la cancha):
  - dos categorías en la misma cancha, el mismo día y con horas que se superponen (al cargar horarios en la guía o
    en la categoría, comparando con lo guardado y entre sí);
  - un técnico con dos categorías a la misma hora (al asignarle categorías o cargar horarios de su categoría);
  - una clase reprogramada a una hora en que otra categoría usa esa cancha ese día.

## 4. Dinero — reglas generales

- Montos en **enteros** (guaraníes sin decimales). Porcentajes se redondean al guaraní entero.
- **Libro mayor inmutable:** un movimiento no se edita ni se borra; se **anula** con un
  contra-movimiento, con motivo y auditoría.
- **Saldo de una cuenta = suma de sus movimientos.** Nunca se edita a mano.
- Cuentas: banco, caja (efectivo), billetera digital.

## 5. Tarifas y cuotas *(Sprint 3)*

- **Tarifa** = concepto (inscripción, cuota, torneo…) + grupo + temporada + monto + vigencia.
  Los montos se cargan al crear la temporada (general y por categoría); después, "Cambiar monto".
- Cambiar una tarifa **no altera** cargos ya emitidos; rige desde su fecha de vigencia.
- **Plan de cobro de la temporada** *(Sprint 4c)*: frecuencia de la cuota
  - mensual (meses calendario), quincenal (1–15 y 16–fin de mes), semanal (lunes a domingo) o
  - por día: por día de entrenamiento (según el horario de la categoría) o por clase asistida
    (cuando exista Asistencia; hasta entonces no genera), agrupado en una cuota por día, semana o mes
    ("octubre: 12 entrenamientos × ₲ 20.000").
  - Vence N días después de empezar cada período. Una temporada sin plan no genera cuotas.
  - En pantalla no se dice "período": cada cuota se nombra por lo que cubre (mes, quincena, semana o, en "por día",
    la agrupación), con su género ("este mes", "esta semana", "la quincena completa"), y el vencimiento se pregunta
    como se piensa ("¿Qué día del mes vence?", "¿Qué día de la semana?"; se guarda en días desde que empieza).
    Fuente única: `App\Enums\BillingUnit` (panel, resumen y `terms` de la API). Agrupado por día no hay
    "a mitad de…".
- **Cuota automática** para inscripciones `activo` o `becado` de temporadas vigentes (la beca total no genera cuota; la parcial se aplica como ajuste; `baja` no se cobra).
  - **Al empezar cada período** (por defecto): el generador corre todos los días y emite los períodos ya empezados.
  - **Todas juntas al inscribir** (opcional, con permiso de cuotas): se crean todas las cuotas de la temporada;
    las que todavía no empezaron son **próximas** y la familia las ve aparte de lo que tiene que pagar ahora.
  - Desde el período de la fecha de inscripción. **A mitad de período** se cobra proporcional, completo o desde
    el próximo (lo elige quien inscribe; el plan trae el valor por defecto).
  - **Baja o suspensión:** se anulan solas las cuotas futuras sin pagar; si se reactiva, se reemiten.
  - Becas, descuentos y montos nuevos **no rehacen** cuotas ya emitidas: se anula la cuota con
    "Volver a emitirla" y se rehace con lo de hoy.
  - La generación es **idempotente**: lock en Redis + clave única en BD
    (inscripción + concepto + inicio del período). Nunca se cobra dos veces el mismo período.
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
  imputarlo (el cargo no se modifica). Solo en cuotas mensuales.
- Anular un pago: los cargos vuelven a pendientes y se registra el contra-movimiento en la cuenta.
- Recargos por mora: configurados, todavía no se generan.
- Cada pago genera un **recibo PDF**.
- **Comprobante subido por el padre** (transferencia) queda `pendiente` hasta que el
  tesorero lo valida; recién ahí impacta en la cuenta. *(Fase 2)*

## 9. Gastos *(Sprint 4b)*

- Cada gasto: cuenta de salida, categoría, proveedor, comprobante adjunto. Lo registra y paga
  quien tiene el permiso (sin doble aprobación). Se anula con motivo (contra-movimiento).
- **Gastos recurrentes** (ej. alquiler de cancha): cada mes generan un gasto pendiente que se
  confirma al pagarlo; recién ahí sale de la cuenta.
- Transferencias entre cuentas (ej. caja → banco) son dos movimientos enlazados; no son ingreso ni gasto.
- **Balance:** saldo inicial + ingresos (por concepto) − gastos (por categoría) + otros movimientos
  (saldos iniciales, ajustes) = saldo final. Una anulación resta en el período en que se hace.

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
