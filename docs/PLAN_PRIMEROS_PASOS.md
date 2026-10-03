# Plan — Primeros pasos: alta autoservicio y guía del administrador

> Plan de una funcionalidad. Entra en la hoja de ruta de `PLAN.md` (§3.1 y §7, sprints 5d a 5f). Al implementarse,
> las reglas pasan a `business-logic.md` y los endpoints a `API_V1.md` (contrato primero, app primero).

**Pedido (03/10/2026):** una guía para el administrador del club que se abre sola cuando inicia su cuenta y lo deja
con todo configurado: crea su cuenta, su organización, sus disciplinas, sus temporadas, habilita las inscripciones
(un link para que padres o alumnos se inscriban), crea a sus profesores, y así sucesivamente. Cuidar la usabilidad.

---

## 1. Idea

Hoy un club existe solo si un super admin lo crea en `/plataforma` e invita a su primer administrador. Después el
admin tiene que descubrir el panel solo: las disciplinas se crean desde el formulario de Categoría, la temporada tiene
su asistente, los profesores entran por Invitaciones y recién cuando aceptan se los puede asignar a una categoría.

Con esta funcionalidad cualquier club se da de alta solo y una guía lo lleva hasta tener el link de inscripción:

```
Crear cuenta (celular o correo) → código por WhatsApp (o correo) → "Tu club"
→ GUÍA "Primeros pasos" (se abre sola)
   1 Disciplinas → 2 Categorías y horarios → 3 Temporada y cuotas → 4 Profesores → 5 Inscripciones
   (opcionales: 6 Alumnos que ya tenés · 7 Comisión · 8 Cuentas para cobrar)
→ "¡Tu club está listo!" con el link para compartir
Familia: abre el link → elige actividad → datos del hijo (o "me inscribo yo") → crea su cuenta → "Solicitud enviada"
Club: aviso → aprueba (confirma la categoría) → alumno + inscripción + cuotas → push a la familia
```

## 2. Decisiones del usuario (03/10/2026)

- ✅ La guía va **completa en el panel y en la app**.
- ✅ **Alta autoservicio inmediata**, con verificación de email. La plataforma ve las altas y puede suspender.
  Adelanta "Alta autoservicio" de la Fase 4, sin planes ni suscripciones.
- ✅ El link de inscripción crea una **solicitud que el club aprueba** (etapa B de `PLAN_ACTIVIDADES.md`, abierta a familias nuevas).
- ✅ El padre (o el alumno adulto) **crea su cuenta al inscribirse**: hay registro abierto para tutores
  (resuelve el ⏳ de `PLAN_ACTIVIDADES.md` §8).
- ✅ **Cuenta con el celular (WhatsApp)**: se crea y se verifica con el número; el correo es la alternativa para quien
  no tiene WhatsApp. Se entra con cualquiera de los dos. La WhatsApp Cloud API de Meta solo manda códigos (crear cuenta
  y "Olvidé mi contraseña"); los avisos siguen por push. Con protección contra bots y tope de gasto (`CodeGuard`).
  Las invitaciones van a un correo o a un celular (link `wa.me` al número). También en el panel.

## 3. Etapas

| Etapa | Qué incluye | Sprint |
|---|---|---|
| **1. Alta y guía** | Crear cuenta + código, "Tu club", checklist, pasos 1–4 (disciplinas, categorías y horarios, temporada, profesores), apertura automática; app y panel | 5d |
| **2. Inscripciones por link** | Paso 5: actividades, link público, registro del padre, solicitudes con aprobación, QR, afiche y WhatsApp, "¡Tu club está listo!" | 5e |
| **3. Opcionales y plataforma** | Pasos 6–8 (alumnos que ya tenés, comisión, cuentas para cobrar), progreso de la configuración en `/plataforma` | 5f |

Cada etapa: contrato → app → API y panel → probado de punta a punta. Se frena para revisar entre etapas.

## 4. Experiencia

### Principios (valen para panel y app)

- **Una pregunta por pantalla, con respuesta sugerida.** Títulos como pregunta ("¿Qué enseñan?"), una línea de
  "para qué sirve", valores por defecto cargados; lo avanzado, plegado. Meta: link listo en **10 minutos o menos**.
- **Plantillas en vez de página en blanco:** disciplinas frecuentes como chips, categorías generadas desde un rango
  de edades o desde niveles, un horario "igual para todas" que después se ajusta.
- **El progreso sale de los datos, no de un "visto":** un paso está hecho si existe lo que pide, aunque se haya hecho
  fuera de la guía, desde otro dispositivo o por otro admin. El estado es de la organización, no del usuario.
- **Se guarda en cada paso:** salir a la mitad no pierde nada; la guía retoma en el primer paso pendiente.
- **Pasos bloqueados visibles:** "Primero creá las categorías", con el botón que lleva ahí.
- **"Lo hago después"** en los pasos que se pueden omitir. La guía se puede cerrar: "La retomás desde el inicio".
- **Vocabulario del club en todo** (`term()` en la app, `Terms::label` en el panel).
- **Ver como familia:** vista previa de lo que verá el padre antes de publicar el link.
- **Compartir donde está la gente:** WhatsApp (`wa.me/?text=` con el texto armado), copiar el link, QR y afiche PDF.
- **Celular primero:** una columna, botones grandes, teclado numérico en montos.
- Tiempo estimado por paso ("1 min") y barra de progreso ("2 de 5").

### Cuándo se abre la guía

- Al crear el club entra directo a la guía.
- Mientras esté incompleta y no la haya cerrado, se abre sola al entrar al club (en la app, una vez por sesión; en el
  panel, el Escritorio lleva a "Primeros pasos").
- Cerrada: tarjeta "Configurá tu club · 3 de 5" arriba en el inicio de la app y aviso en el Escritorio del panel,
  hasta completarla. Siempre se llega desde "Mi cuenta → Configurar el club" y desde el menú del panel.
- Completa: pantalla "¡Tu club está listo!" una vez, con el link para compartir.
- Las organizaciones que ya existen (Jakare) quedan con la guía cerrada para que no se abra sola.

### Pasos

| # | Pantalla | Qué crea | Hecho cuando | |
|---|---|---|---|---|
| — | **Crear cuenta** + código | Usuario verificado, términos aceptados | — | obligatorio |
| — | **Tu club**: nombre, tipo (Club / Academia / Escuela / Comisión de padres), "¿Cómo les dicen?" | Organización (roles base, conceptos, Caja), membresía y rol `admin` del creador, vocabulario según el tipo | — | obligatorio |
| 1 | **¿Qué enseñan?** chips con disciplinas frecuentes + "Otra" | Disciplinas con criterio sugerido (deporte de equipo → año de nacimiento; el resto → nivel) | al menos una | obligatorio |
| 2 | **Categorías y horarios** por disciplina: generador (edades de 4 a 16, de a 1 o 2 años → Sub-6, Sub-8…; o niveles Inicial / Intermedio / Avanzado), cupo opcional; días y horario "igual para todas"; "¿Dónde entrenan?" | Categorías, horarios y sede | al menos una categoría activa con horario | obligatorio |
| 3 | **Temporada y cuotas**: el asistente de temporada de siempre (duración, fechas, frecuencia, cuota, inscripción, vencimiento, cuándo se crean) con "Primeras cuotas" | Temporada y tarifas | al menos una temporada vigente o próxima ("Sin plan de cobro" se avisa) | obligatorio |
| 4 | **Profesores**: "Yo también doy clases" + nombre, celular o correo y categorías de cada uno; cada invitación con "Compartir por WhatsApp" | Invitación con rol de instructor **y sus categorías** (quedan asignadas al aceptar); al admin, el rol y las categorías | al menos un profesor (aceptado, invitado o el admin) | se puede omitir |
| 5 | **Abrí las inscripciones**: temporada, categorías abiertas, hasta cuándo, logo, WhatsApp y descripción; "Ver como familia"; Publicar | Actividad publicada | al menos una actividad publicada | obligatorio |
| 6 | **¿Ya tenés alumnos?** Mandar el link a las familias, importar Excel (panel) o cargar uno | — | al menos un alumno | opcional |
| 7 | **Invitá a tu comisión** (cargos con mandato) | Invitaciones | al menos un cargo | opcional |
| 8 | **Cuentas para cobrar** (banco, billetera; la Caja ya existe) | Cuentas | más de una cuenta | opcional |

### Link de inscripción (lado de la familia, etapa 2)

- `{APP_FRONTEND_URL}/inscripcion/{slug}`: público, como `/invitacion`. Abre la app web y, cuando haya dominio, la app.
- Pantalla del club: logo, nombre, descripción, WhatsApp; actividades abiertas con edades, días y hora, sede, costo
  en palabras ("Inscripción ₲ 100.000 + ₲ 150.000 por mes") y "Últimos lugares" / "Completo: quedás en espera".
- "Quiero inscribir a mi hijo/a" o "Me inscribo yo" → datos (nombre, apellido, fecha de nacimiento, documento
  opcional; uno o varios hijos) → la API sugiere la categoría por edad (`Group::suggestFor`) → crear cuenta con el
  celular y el código por WhatsApp (o "Ya tengo cuenta"), parentesco y consentimiento → "Solicitud enviada. Te avisamos cuando el club la apruebe."
- Sin organización todavía, la pantalla de organizaciones muestra sus solicitudes pendientes.
- Al aprobar: alumno (se reutiliza por documento o por nombre + nacimiento), tutor vinculado al usuario sin
  invitación, membresía activa, inscripción y cuotas según el plan, push y email. Rechazo con motivo.

## 5. Conceptos y reglas (propuestas)

1. **Alta de cuenta:** nombre, celular (o correo, si no tiene WhatsApp), contraseña (8+) y aceptación de términos
   (versión y fecha). Código de 6 dígitos por WhatsApp (o correo): vence a los 15 minutos, 5 intentos, envíos
   limitados por `CodeGuard`. Sin verificar no se puede crear un club. Una cuenta sin verificar no ocupa el número.
2. **Alta de club** (`CreateOrganization`, el mismo núcleo que usa `/plataforma`): nombre, tipo y vocabulario; slug
   sugerido desde el nombre, único y fuera de la lista de reservados (`admin`, `plataforma`, `api`, `inscripcion`…).
   El creador queda con el rol `admin` (`RoleAssigner`). País, moneda y zona horaria: Paraguay, ₲ y Asunción.
   Los módulos siguen siendo de la plataforma. La organización queda marcada como autoservicio y se avisa a los super admins.
3. **Checklist** (`App\Support\Onboarding\Checklist`): calcula cada paso desde los datos (`done`, `pending`,
   `locked` con el paso que lo bloquea, `skipped`). Omitir y cerrar la guía son de la organización.
   La guía está completa cuando todos los obligatorios están hechos; se guarda la fecha la primera vez.
4. **Plantillas** (`App\Support\Onboarding\Templates`): disciplinas sugeridas con criterio, generador de categorías
   por edades o por niveles y vocabulario por tipo. Las usan el panel y la app por igual (`GET setup/templates`).
5. **Profesor invitado con categorías:** la invitación guarda las categorías y `AcceptInvitation` las asigna. Si el
   admin también da clases, recibe el rol de instructor y las categorías sin invitación.
6. **Una sola lógica:** todo lo que decide (checklist, plantillas, fechas y montos de temporada, sugerencia de
   categoría) está en la API; panel y app solo dibujan. La temporada se crea con la misma acción desde los dos.

## 6. App (primero)

- **Rutas:** públicas `/crear-cuenta` (¿registrar mi club o inscribirme?), `/registro` y `/inscripcion/:slug`;
  y `/recuperar` ("Olvidé mi contraseña"); con sesión `/registro/codigo` (la fuerza `sessionRedirect` si la cuenta no
  está verificada), `/registro/club`,
  `/configurar` (checklist), `/configurar/:paso` y `/solicitudes`.
- **Login:** "Crear cuenta" junto a "Tengo una invitación". **Organizaciones vacías:** "Registrar mi club" y
  "Tengo un link", más las solicitudes pendientes.
- **`lib/features/onboarding/`:** repositorio, `OnboardingController` (checklist), una pantalla por paso con su
  controller (como `LessonProfileController`), `SetupCard` en el inicio y "¡Tu club está listo!".
- **`lib/features/enrollment/`** (etapa 2): página pública del club, solicitud, "Solicitud enviada", `/solicitudes`
  para el admin y tarjeta en el inicio.
- **Tests:** redirecciones nuevas, repositorios con `fakeDio`, validaciones de cada formulario, checklist con pasos
  bloqueados y omitidos, apertura automática una vez por sesión.

## 7. API y panel (después)

- **Contrato:** `API_V1.md`, secciones "Sprint 5d" y "Sprint 5e".
- **Panel:** registro propio con el paso del código (`->registration()`), "Tu club" como registro de organización
  (`->tenantRegistration()`; Filament ya lleva ahí a quien no tiene organizaciones), página "Primeros pasos" con el
  checklist y un slide-over por paso que reutiliza `GroupForm::programFields`, `SeasonPlanSteps` y `RoleFields`;
  aviso en el Escritorio. Etapa 2: "Actividades" y "Solicitudes" en Académico. `/plataforma`: autoservicio y progreso.
- **Tests (Pest):** registro y código (vence, intentos, límite), slug reservado y único, el creador queda admin,
  checklist desde los datos, la temporada igual desde panel y API, invitación con categorías, aislamiento entre
  organizaciones; etapa 2: solicitud pública, aprobación idempotente, cupo y espera, consentimiento.

## 8. Qué cuidar

- **Registro abierto:** cambia "Alta de usuarios solo por invitación" (`business-logic.md` §2). Las invitaciones
  siguen siendo el camino para los roles (comisión, profesores); el registro abierto da una cuenta sin organización.
- **Un solo camino de alta de alumno:** la aprobación de solicitudes usa `RegisterStudent`.
- **La app no supone organización activa** en el registro, el código, "Tu club" ni el link de inscripción.
- **Datos personales:** términos y consentimiento con versión y fecha desde ya (Ley 7593/2025 rige desde nov-2027).

## 9. Pendientes y riesgos

- ⏳ Texto de los términos y de la política de datos.
- ⏳ Dominio y deep links: el link funciona en la web desde el primer día; abrir la app instalada necesita dominio.
- La app web pesa en la primera carga con datos móviles; si molesta, una página liviana del link en Laravel.
- Un profesor particular solo no puede activarse `private_lessons` desde la guía (los módulos son de la plataforma).
- Abuso del alta abierta: límites, verificación, slugs reservados y suspensión desde `/plataforma`.
