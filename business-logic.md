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
  grupos, temporadas y miembros. Vocal: informes. Síndico: ver lo financiero e informes. Técnico, tesorero y
  protesorero: cobrar en efectivo desde la app. Admin, técnico y tutor: nada más (el admin pasa por todo; técnico y
  tutor usan sus grupos e hijos). "Condonar deudas" nace con el tesorero y el presidente (y se les agregó a los roles
  que ya existían); el admin lo agrega o quita por rol.
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

### Bajas *(2026-10-04, `docs/PLAN_BAJAS.md`)*
- **Se da de baja en el panel** ("Dar de baja" en Inscripciones, por fila o en bloque, y en la ficha del jugador) **o en
  la app** (ficha del alumno para quien tiene el permiso), con **fecha** (hoy por defecto; entre la inscripción y hoy) y
  **motivo** obligatorio; queda quién la dio y el registro de actividad. La da quien puede **editar inscripciones**
  (admin; secretario y prosecretario por defecto). "Estado" ya no ofrece "Baja".
- **Aviso a la familia:** al dar de baja se pregunta si se le avisa (prendido si hay tutores con la app). El mensaje
  viene prellenado, amable y con las puertas abiertas ("…Las puertas siempre van a estar abiertas: cuando quieran
  volver, escribinos y los esperamos"), sin hablar de plata, y se puede cambiar en el momento. Les llega a los tutores
  con cuenta (y al alumno adulto con cuenta): siempre en la bandeja **"Avisos"** de la app, y además por push si tienen
  la app instalada con notificaciones y por correo si tienen uno para copias. **El formulario dice la verdad:** antes de
  mandarlo muestra a quién le llega y por dónde ("A Laura Benítez le llega en la app") y, a los tutores sin cuenta con
  celular, ofrece "Mandar por WhatsApp" (link `wa.me` con el mensaje ya escrito, se manda a mano; no hay API de
  WhatsApp). El registro de actividad guarda los canales reales de cada uno. *(2026-10-05)*
- **La deuda queda como histórica:** las cuotas impagas, **también la del período en curso** si ya empezó, siguen
  pendientes hasta que se pagan, se anulan o se condonan (§5). Nunca se borran ni se anulan solas; solo se anulan
  solas las **futuras** sin pagos.
- **Avisos de baja:** el técnico avisa "Dejó de venir" (sus grupos, nota opcional) y el tutor avisa "Deja el club"
  (ficha del hijo, mensaje opcional; marca todas sus inscripciones vigentes). Cada uno lo puede deshacer. No dan la
  baja: la inscripción queda marcada y les llega un aviso (push y correo) a quienes pueden darla, que deciden en la app
  ("Avisos de baja") o en el panel: "Dar de baja" (con la nota como motivo) o "Sigue viniendo". Dar la baja cierra el
  aviso.
- **Si vuelve:** "Reactivar" (activo o becado). La deuda sigue para pagarse; las cuotas se emiten **desde el período
  en curso** (los meses que estuvo afuera no se cobran) y se reemiten las futuras anuladas. Igual al volver de una
  suspensión. De pendiente a activo se sigue cobrando desde la fecha de inscripción.
- **Dado de baja** = ninguna inscripción sin baja en temporadas vigentes o próximas y al menos una baja (si sigue en
  otra disciplina, no). Saldos y Morosos (app, panel, PDF y Excel) lo marcan con la fecha ("Matías: baja el
  03/06/2026") y Morosos se filtra: todos, solo los que siguen o solo los dados de baja.
- **Familia:** agrupa alumnos y tutores. Un tutor puede tener varios hijos; un hijo varios tutores.
- Alumno adulto sin tutor: es su propio responsable.
- Ficha médica: visible solo para roles autorizados.
- **Nada se borra (soft delete):** alumnos, inscripciones y asistencias se **archivan** (incluido "Borrar" en el panel)
  y dejan de aparecer en listas, cuentas, informes y el Escritorio, pero queda el historial. El documento sigue siendo
  único por organización contando los archivados: cargar de nuevo ese documento (app, panel o importación)
  **restaura al mismo alumno** con sus asistencias en vez de crear otro, y una inscripción archivada vuelve como nueva
  (desde ese día, con sus cargos). Lo mismo con los **tutores**: "Eliminar" en Tutores lo archiva (deja de verse en la
  ficha de sus hijos y no recibe avisos) y, si se lo vuelve a cargar con el mismo usuario, correo, celular o
  documento, se restaura. Los descuentos de una clase suspendida que se vuelve a dar también se archivan.

### Inscripción desde la app *(2026-10-04, `docs/PLAN_INSCRIPCION_TUTOR.md`)*
- **"Entra ya, se confirma después".** Un **miembro activo** (normalmente un tutor) pide la inscripción de un hijo:
  nombre, apellido, fecha de nacimiento, **documento** (obligatorio), parentesco, disciplina, temporada vigente o
  próxima y categoría activa (la API sugiere la que corresponde por año de nacimiento) y, si quiere, la ficha médica.
  El chico queda dado de alta (`RegisterStudent`) con la inscripción **`pendiente`**: aparece en "Mis hijos" y en las
  clases del técnico como "Nuevo, por confirmar" y se le toma asistencia, pero **no se cobra** (ni inscripción ni cuotas).
- Una sola solicitud por confirmar por documento. Si ya es su hijo y tiene inscripción en esa disciplina y temporada, o
  el chico ya está en esa categoría, no se puede pedir. Si se había dado de baja de esa categoría, vuelve desde hoy (los
  meses que estuvo afuera no se cobran). Si el documento es de un alumno de otra familia, va a clases ya pero el tutor
  se le vincula (y ve sus datos) recién al confirmar; sus datos no se cambian.
- **Confirman** quien tiene "Confirmar inscripciones de la app (todas las categorías)" (secretario y prosecretario por
  defecto; el admin siempre) y quien tiene "Confirmar inscripciones de la app en sus categorías" (el técnico por
  defecto, en las suyas); los dos se editan por rol. Con un toque desde la planilla, desde la lista de la app o desde
  el panel. Puede cambiar la categoría (de la misma disciplina, entre las que confirma) y qué se cobra del período en
  curso (`MidPeriod`, por defecto el del plan). Si la categoría está completa (ocupan las inscripciones activas,
  becadas o pendientes, sin contar el lugar de la propia solicitud), tiene que confirmarlo.
- **Al confirmar**, la inscripción pasa a `activo`: se emiten el cargo de inscripción y las cuotas desde el día en que
  empezó a ir. El tutor queda vinculado a su cuenta sin invitación (su ficha de tutor si ya tenía, sin pisarla), la
  ficha médica pasa a la del alumno si no tenía y, si no tenía el rol `tutor`, se le asigna.
- **Rechazar** pide motivo y saca al chico de la lista (también de la lista del mes del grupo) sin borrar nada: se
  archiva la inscripción pendiente (o vuelve a la baja que tenía) y, si el alumno lo creó o restauró la solicitud y no
  tiene nada más, el alumno y sus asistencias. El tutor también puede cancelarla mientras está por confirmar (mismo
  efecto, sin aviso). Confirmar y rechazar avisan al tutor (push y correo); una solicitud nueva avisa a
  quienes pueden confirmar en esa categoría.
- Si quien pide puede confirmar en esa categoría, **se confirma sola**. Siempre queda registrado quién confirmó o rechazó.
- La ficha médica de la solicitud se guarda cifrada, no la ve quien confirma y se borra al confirmar (ya está en la
  ficha), rechazar o cancelar.
- **Cargar alumno desde la app:** quien puede crear alumnos (el admin, el secretario) da de alta directo, como "Nuevo
  jugador" del panel: datos del chico (documento obligatorio), categoría sugerida y su tutor (nombre, celular, correo
  opcional). Si el tutor no usa la app, se crea su invitación y la app la manda por WhatsApp (link `wa.me`).
- "Nuevo jugador" del panel también exige el documento (los alumnos que ya estaban sin documento se siguen editando).
- Pendiente para el Sprint 5e: el link público del club (familias que todavía no están), actividades y lista de espera.

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
  - **Baja o suspensión:** se anulan solas las cuotas futuras sin pagar (las ya empezadas quedan pendientes); si se
    reactiva, se emiten desde el período en curso y se reemiten las futuras (§3, Bajas).
  - **Condonar** (permiso "Condonar deudas", `Waive:Charge`; por defecto el admin, el tesorero y el presidente, y se
    agrega o quita por rol; panel y app): perdona **lo que falta pagar** de una o varias cuotas, con motivo; queda
    quién, cuándo, por qué y cuánto (una fila por condonación), con estado "Condonado". Sale de la cuenta, de Saldos y
    de Morosos como una anulada; lo ya pagado sigue siendo ingreso. Es distinto de "Anular" (cargo mal emitido).
  - **Deshacer una condonación** (mismo permiso, con motivo): la cuota vuelve a quedar pendiente por lo condonado;
    queda quién, cuándo y por qué, y el historial muestra los dos pasos. Se puede volver a condonar.
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
- **Cobro en efectivo desde la app** (`PLAN_COBRO_EFECTIVO.md`): quien tiene el permiso "Cobrar en efectivo desde la
  app" (técnico, tesorero y protesorero por defecto; el admin siempre) cobra a los alumnos de sus grupos (o a todos, si
  ve todos los alumnos) las cuotas pendientes de toda la familia. Es un pago normal: efectivo, fecha de hoy, imputación,
  pronto pago, saldo a favor y recibo de siempre. La familia recibe un aviso con el recibo.
- **Caja del técnico:** lo cobrado entra en la **caja personal** de quien cobra ("Caja de Juan Pérez", una cuenta del
  club con titular, creada con el primer cobro): es plata del club en su poder. Con la caja cerrada (cuenta inactiva)
  no puede cobrar.
- **Depósito:** quien cobra informa que dejó la plata en una cuenta del club (Caja, banco o billetera); queda **por
  confirmar** y la plata sigue en su caja. Quien valida comprobantes lo confirma (transferencia de su caja a esa
  cuenta, con la fecha del depósito) o lo rechaza con motivo; en los dos casos se le avisa. No se deposita más de lo
  disponible (saldo menos lo que ya está por confirmar). Anular un pago cobrado así saca la plata de su caja.
- **Transferencia que la familia le mandó al club** (la captura de WhatsApp): quien cobra en efectivo también la
  registra desde la app como un comprobante de transferencia en nombre de la familia, con la imagen o el PDF y quién lo
  registró. Si quien la registra valida comprobantes, queda aprobada al instante con su recibo; si es el técnico, queda
  en revisión. La familia la ve en su estado de cuenta.
- **Cobra directo a la Caja** *(2026-10-05, `PLAN_COBRO_EFECTIVO.md` §9)*: se elige por persona (sí/no en su
  membresía). Por defecto **sí** solo para quien creó la organización (el dueño: el usuario del alta autoservicio o el
  primer administrador que acepta la invitación de la plataforma; en las que ya existían, la membresía más vieja con rol
  de administrador); **no** para todos los demás, también un segundo admin. Lo cambia quien administra los miembros y
  queda registrado quién y cuándo. Si cobra directo, el efectivo entra en la Caja del club (o en otra cuenta del club
  que elija), sin caja personal ni depósito; si no, a su caja personal como siempre. Cada pago guarda **quién lo
  cobró** (lista de pagos, recibo y movimientos de la Caja). Si pasa a cobrar directo con plata en su caja, esa plata
  sigue ahí hasta que la deposite.
- Las cajas personales no aparecen al elegir dónde entra un pago (registrar pago, aprobar comprobantes); sí en
  Cuentas, Transferencias y Gastos. Excepción: en "Registrar pago" del panel, quien registra ve **su** caja, que es la
  cuenta por defecto con método Efectivo (el efectivo queda en su poder hasta depositarlo, como en la app); si cobra
  directo a la Caja, la cuenta por defecto es la Caja del club.
- En el estado de cuenta, un comprobante rechazado se ve mientras alguna de sus cuotas siga pendiente (sin cuotas,
  30 días); después queda como historial.

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
- **Bandeja "Avisos"** *(2026-10-05)*: cada aviso (todo `PushNotification`) queda guardado para la cuenta, en la
  organización en la que se mandó, con leído / no leído. Es el único canal seguro: le llega aunque use la app web sin
  notificaciones y no tenga un correo verificado. **No entran los recordatorios de día de clase** ("¿Lo llevás?" del
  tutor y "Tomar asistencia" del técnico): se repiten y vencen al empezar la clase, así que solo van por push y correo.
  Cada aviso declara si entra (`PushNotification::inInbox()`, sí por defecto).
- Envíos masivos por lotes en la cola `notifications` (Horizon).
- Una **resolución** publicada a un grupo notifica a sus tutores y puede generar un cargo. *(Fase 2)*

## 11. Fechas y horarios

- Se guardan en **UTC**; se muestran en la zona horaria de la organización
  (por defecto `America/Asuncion`).

## 12. Auditoría y datos sensibles

- Todo lo financiero y los cambios de roles quedan en el registro de actividad.
- Datos de menores: acceso mínimo por rol; ficha médica solo para roles autorizados.
