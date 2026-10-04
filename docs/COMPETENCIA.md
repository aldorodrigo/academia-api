# Competencia — productos parecidos

> Relevamiento del 27/09/2026 con datos públicos:
> - sitios web y tiendas de apps;
> - prensa;
> - copias de archive.org;
> - lo que cada sitio carga en el navegador.
>
> Las cifras de clientes y usuarios las declara cada empresa. **[sin verificar]** marca lo que no se pudo confirmar.
> Son hallazgos y recomendaciones, **no decisiones**: las decisiones van en `PLAN.md` y `PLAN_ACTIVIDADES.md`.

---

## 1. Resumen

- **Afuera hay muchos productos parecidos, pero ninguno junta lo que tiene este**, y menos en Paraguay:
  - app para las familias;
  - cuenta corriente con temporadas y cobro por clase asistida;
  - asistencia del técnico sin conexión;
  - comisión con mandatos;
  - pagos locales y SIFEN.
- **En Paraguay hay tres competidores a mirar:**
  - **MiCancha:** el más directo. Es nuevo (mayo de 2026), rápido y tiene un panel de academias completo, pero no
    tiene app para padres.
  - **Reva:** establecida desde 2018, con inversión. Se dedica a canchas y torneos (pádel) y no entró en academias.
  - **Eventifica:** uruguaya, para colegios privados. Solo compite de costado en las ACE.
- **Lo que nos diferencia:**
  - app para padres (estado de cuenta, recibos, avisos, "¿Lo llevás?");
  - comisión (mandatos, actas, resoluciones) y rifas;
  - temporadas con plan de cobro y cobro por clase asistida;
  - asistencia sin conexión;
  - un padre con hijos en varias organizaciones.
- **Lo mínimo esperado que falta:**
  - comprobante subido por el padre;
  - pago online;
  - links por WhatsApp;
  - SIFEN (MiCancha ya lo emite).
- **Actividades para tus hijos:** los catálogos puros de actividades infantiles fracasaron. Funcionaron los que
  primero tuvieron el software de las organizaciones. Eso respalda empezar por A+B (§8).
- **Precio:**
  - En la región, una academia chica paga US$20–50 por mes.
  - En Paraguay las comisiones de pago tienen tope (3% crédito, 2% débito, 1,5% QR), así que cobrar un % de las
    cuotas deja poco margen (§9).

---

## 2. Paraguay: competidores directos

### 2.1 MiCancha — [micancha.com.py](https://micancha.com.py/)

**Qué es.** Sitio web de Asunción con cuatro módulos:

| Módulo | Qué hace |
|---|---|
| Canchas | Reservas online y panel del complejo: agenda, precios de día y de noche, promociones y reportes |
| Torneos | Fixture, tablas, sanciones y validación de identidad del jugador con la cámara del celular |
| Academias | Ver la tabla de funciones más abajo |
| Cantina | Caja, comandas, stock por turno y menú QR; la presenta como "para padres y encargados" y "Mininegocio de padres" |

**Datos generales:**
- **Stack:**
  - web en Next.js 16 (React 19, íconos lucide, gráficos Recharts), servida por ellos detrás de Cloudflare;
  - API en Python con FastAPI, con 465 rutas y un título heredado de otro proyecto ("API Sistema de Gestión de
    Vehículos");
  - imágenes guardadas en su propia API;
  - login propio y con Google;
  - WhatsApp con Evolution API.
- No se encontró app en Google Play ni en App Store: los botones de descarga no tienen enlace.
- No dice quién está detrás.

**Cuándo salió y actividad**

| Fecha | Evidencia |
|---|---|
| 17–18/05/2026 | Primeros certificados del dominio y de su API ([crt.sh](https://crt.sh/?q=%25.micancha.com.py)). No hay copias en archive.org |
| 01/07/2026 | Manual de torneos "V2 — Actualizado 2026-07-01" |
| 05/07/2026 → 16/09/2026 | Carga de deportes del sistema; el último se agregó el 16/09 |
| jul–sep 2026 | Torneos publicados |

Se actualiza activamente, pero tiene ~4 meses.

**Cifras: portada vs. datos públicos**

| La portada dice | Lo que muestran sus propios datos públicos |
|---|---|
| "150+ clubes felices", "480+ torneos", "280.000+ horas reservadas" | La misma portada: "12+ complejos, 45+ canchas" |
| — | Directorio: 42 complejos (Ciudad del Este 7, Encarnación 5, Asunción 4, San Lorenzo 4, Fernando de la Mora 4, Lambaré 3…) |
| — | **6 academias**, todas en el plan "básico". Solo 2 tienen ciudad y descripción, y solo una publica horarios y cuotas |
| — | 9 torneos abiertos, casi todos de ajedrez y artes marciales |

- La portada sigue casi frase por frase la de Reva. Por ejemplo, "Administrá tu complejo… Registrá tu negocio" y "Hay
  buenas razones para estar orgullosos", con los mismos contadores de clientes, torneos y horas.
- No tiene redes propias: los íconos de Instagram, Facebook y LinkedIn de su pie de página apuntan a `#`. La cuenta
  @MiCancha de X no está confirmada como suya.

**Panel de academias.**
- Fuentes:
  - la portada: *"Inscribí alumnos, controlá mensualidades y asistencias, gestioná profesores y organizá las clases
    y sucursales"*;
  - el ingreso de academias: *"Gestión completa de tu escuela deportiva: alumnos, cuotas, asistencias, categorías
    y más"*;
  - los textos de la interfaz del panel (`/academia-panel`), que vienen en los archivos JavaScript públicos del
    sitio.
- **No se usó cuenta ni se vio funcionando.**

| Función | Texto en el panel |
|---|---|
| Roles | "Dueño · Administrador · Tesorero · Profesor" (el profesor puede quedar limitado a una sede) |
| Alumnos y tutores | "Tutores / Padres", alergias, contacto de emergencia, foto, carnet del alumno en PDF |
| Categorías, horarios y sedes | Categorías con edad mínima y máxima, horarios de práctica por categoría, sucursales |
| Inscripciones | Individual o a varios cursos; vigencia por período; pausa por viaje o lesión que no genera cuotas |
| Cuotas | Matrícula anual y cuota mensual, "Beca 100%", descuento %, "Descuento 2º hijo", "Descuento 3º hijo y siguientes", pago anual con descuento, pago parcial |
| Cobranza | "Enviar recordatorio por WhatsApp", recordatorio masivo, "Reclamar Pago", reporte de deudores |
| WhatsApp | "Vinculación de Bot WhatsApp (Evolution API)", vinculado escaneando un QR (modo no oficial, tipo WhatsApp Web) |
| SIFEN | "Facturación SIFEN / .P12", "Imprimir Factura Electrónica (KuDE)", XML; factura a nombre del alumno o del tutor |
| Tesorería | Cuentas y caja chica, cierre de caja, gastos, proveedores, "Cuentas por Pagar (Deudas)", "Sueldos / Pago de Profesores" |
| Otros | Indumentaria (talles, stock, ventas), competencias, noticias con "Asistente de Redacción IA" |

**Página pública de cada academia** (por ejemplo, [/academia/delio-toledo](https://micancha.com.py/academia/delio-toledo)):
- muestra categorías con edades, horarios, cuotas y matrícula, indumentaria, sedes y redes;
- tiene un botón de WhatsApp de "admisiones".
- No tiene inicio de sesión, pedido de inscripción, filtro por la edad del hijo ni cupos. El buscador
  ([/academias](https://micancha.com.py/academias)) solo filtra por deporte y nombre.

**Precio:**
- Registro gratis, "0 Gs Comisión Extra" y "sin comisiones abusivas", un mensaje dirigido contra Reva.
- Tres planes (Básico, Profesional y Premium) **sin precio publicado**. El plan lo cambia a mano su administrador.

**Lo que no tiene** (según lo visible):
- app o acceso para padres;
- pago online de cuotas: se registran a mano (efectivo, transferencia, QR). Su manual de torneos menciona
  MercadoPago y Stripe, pero Stripe no opera en Paraguay;
- comisión, actas y rifas;
- temporadas con plan de cobro y cobro por clase asistida;
- un padre en varias organizaciones;
- asistencia sin conexión.

**Dato de mercado.** La Escuela de Fútbol Delio Toledo (Fernando de la Mora) publica estos precios:

| Concepto | Monto |
|---|---|
| Matrícula (pago único anual) | ₲ 200.000 |
| Cuota mensual 2020 y 2021 | ₲ 150.000 |
| Cuota mensual 2014 a 2019 | ₲ 180.000 |
| Cuota mensual 2012 y 2013 | ₲ 200.000 |
| Kit de indumentaria | ₲ 155.000 |

**Lectura.** Es el competidor más cercano en el trabajo de oficina de una academia, y ya tiene SIFEN y WhatsApp. Lo
que preocupa es su **velocidad** (canchas, torneos, academias y cantina en ~4 meses), no su tamaño. Su próximo paso
lógico es algo para padres. Nuestra diferencia está en lo que ve la familia y en la comisión.

### 2.2 Reva — [reva.la](https://reva.la/)

**Empresa:**
- Kynox S.A., de Asunción. En Google Play publica como "Reva Sports", con dirección en Delaware, EE. UU.
- Fundadores: Guillermo Arce (CEO), José Guggiari y Andrés Galacho.

| Fecha | Hito |
|---|---|
| 2016 | Nace la idea |
| 15/01/2018 | Lanza la app con 8 clubes de fútbol, pádel y tenis |
| 2019 | Inversión de US$75.000 |
| 2023 | Ronda pre-seed; declara 280 clientes y 75.000 usuarios. Anuncia "Reva Academias", que no se ve en su sitio a sep-2026 |
| oct-2024 | Inversión de US$400.000 con valuación de US$1,7M ([Forbes Paraguay](https://www.forbes.com.py/negocios/no-encontraban-donde-jugar-e-idearon-una-solucion-grupo-amigos-creo-reva-una-app-reservas-canchas-hoy-vale-casi-us-2-millones-n67885)) |
| 2025 | Declara 450+ complejos. Usuarios: Paraguay 40%, Bolivia 30%, Colombia 15%, Argentina y México 15%. Deportes: 80% pádel |
| mar-2026 | Versión 3.0 (rediseño y modo oscuro) y Tienda Reva (pelotas, raquetas, ropa) |
| jul-2026 | Según [El Prisma](https://elprisma.com.py/reva-la-startups-paraguaya-que-gestiona-con-exito-los-clubes-deportivos/): son 10 personas; clientes como Club Internacional de Tenis, Club Sajonia, Club Centenario, Comité Olímpico Paraguayo y Asociación Paraguaya de Tenis; está en Bolivia y Lima; "un promedio de 6 mil inscripciones por mes con un costo de 60 dólares" en torneos |

**Productos:**
- app para jugadores: buscar y reservar canchas, torneos, resultados, perfil y pago online;
- software y app de administración para complejos;
- torneos, sobre todo de pádel;
- apps con la marca de cada torneo o liga (Fansbury, AFAPY, Odeportiva PY, Santa Cruz Unida, La Liga Paraná…).

**Actividad** (sí está actualizada):
- Android: más de 100 mil descargas, 4,1 con 389 reseñas, actualizada el 22/09/2026.
- iPhone: versión 3.06.01 (septiembre de 2026), 4,7 con 100 calificaciones.
- En 2026 saca una versión cada 2 a 4 semanas.

**Precio:**
- [Sus tarifas publicadas](https://reva.la/refunds.html), sin fecha, cobran una **comisión por cada reserva** que hace
  el complejo a través de Reva: 10% (1–99 reservas), 9% (100–499), 6% (500–999), 4% (1.000–1.999) y 3% (2.000–3.499).
  Factura el día 2, vence el 15 y, si no le pagan, da de baja al complejo.
- La prensa de 2023 describe otro esquema:
  - suscripción de US$25 por mes y por cancha;
  - US$20 por semana por publicidad;
  - US$2.500 por seis meses por organizar torneos.
- En 2025 hablaba de "precios definidos por canchas y meses". No está claro si usa los dos modelos o si cambió.

**Fansbury.**
- Nació como empresa argentina: Fansbury S.A.S., de Buenos Aires, con sitio propio desde al menos 2018 y términos de
  2022.
- Hoy la opera Reva:
  - aparece en [reva.la/fansbury](https://reva.la/fansbury/) desde dic-2025;
  - todas sus apps (`com.fansbury.*`, por ejemplo Mundialito, con más de 10 mil descargas) las publica "Reva Sports";
  - fansbury.com ya no funciona.
- No se encontró un anuncio de compra o fusión. Por las fechas, el cambio habría sido entre ago y dic de 2025.

**Lectura.** Es la empresa establecida y crece en torneos y ligas, ahora también en Argentina. No compite en
academias ni en clubes con padres. Podría ser un socio para torneos.

### 2.3 Eventifica — [eventifica.com](https://www.eventifica.com/)

**Qué es:**
- Eventifica S.A.S., de Montevideo.
- Plataforma para **colegios privados**: una app con la marca de cada colegio, más comunicación, gestión académica y
  finanzas.
- Su formulario de contacto también acepta academias.

**Tamaño** (según su web):
- más de 200 colegios, 7.000 empleados de colegios, 80.000 familias y 50M de notificaciones;
- páginas más viejas decían 100 colegios y 17M, así que está creciendo.

**Clientes y apps:**
- Clientes visibles sobre todo en Uruguay y México, y algunos en Chile, Bolivia, Colombia, Costa Rica, Ecuador,
  Argentina y Guatemala.
- En los datos de su web dice atender Paraguay, pero no se vio ningún colegio paraguayo.
- App general con más de 10 mil descargas, actualizada el 24/08/2026, más una app por colegio.

**Funciones:**
- Comunicación:
  - avisos y confirmación de lectura;
  - segmentación, incluidos grupos de deporte y extracurriculares;
  - calendario con recordatorios, álbumes de fotos y chat por áreas con responsables;
  - menú del comedor con reserva, formularios e inscripción a actividades.
- Académico: notas, boletines y asistencia con aviso de faltas.
- Finanzas: cargos recurrentes y en lote, descuentos, estado de cuenta en la app, recordatorios, pagos en línea y
  factura electrónica. No dice con qué pasarelas ni en qué países, y no menciona SIFEN.

**Precio:**
- Suscripción mensual sin contrato, según la cantidad de alumnos; gratis para los padres. Vende con demo y ejecutivo
  de cuentas, y promete dejarlo funcionando en 24 h.
- [Tres planes](https://www.eventifica.com/precios/) (Comunicación, Gestión integral y Avanzado) sin precio publicado.
- [Capterra](https://www.capterra.com/p/209133/Eventifica/pricing/) dice "desde US$49 por mes" **[sin verificar]**:
  lo muestra "por usuario", lo que no cierra con sus 80.000 familias.

**Lectura.**
- No compite con clubes y academias.
- En las ACE de colegios privados compite solo de costado: resuelve la comunicación, pero no las finanzas de la ACE,
  la rendición de cuentas ni la comisión. Las ACE de escuelas públicas no son su mercado.
- Confirma el argumento "app en vez de WhatsApp"; ver su nota
  ["WhatsApp vs app escolar"](https://blog.eventifica.com/whatsapp-vs-app-escolar-por-que-los-colegios-estan-migrando/).

### 2.4 Otros en Paraguay

- **Apps de colegios:** SAI (libreta online) y Auleate (registros y mensajes). Colega (Qoarto) probablemente está
  inactiva.
- **Redes de cobranza:** Infonet Cobranzas y Aquí Pago. Más de 70 colegios cobran así; en 2024, el 75% de los pagos a
  colegios fue digital y el 25% en efectivo.
- **Reservas de canchas:** JugaPY.
- **Clubes grandes con portal propio:** por ejemplo Cerro Porteño, que cobra con tarjeta, Tigo Money, links, débito
  automático y cobrador a domicilio.
- **Competencia gratis:** escuelas de fútbol municipales de Asunción; y para las escuelitas, WhatsApp más Excel.

---

## 3. Comparación con nuestro producto

| Función | MiCancha | Reva | Eventifica | Nosotros |
|---|---|---|---|---|
| App para padres (estado de cuenta, recibos, avisos) | ❌ | — (es para jugadores) | ✅ | ✅ |
| Confirmación de lectura | ❌ | — | ✅ | ⏳ Sprint 5b |
| Un padre con hijos en varias organizaciones | ❌ | — | ❌ una app por colegio | ✅ |
| Temporadas con plan de cobro; cobro por clase asistida | ❌ cuota mensual | — | ❌ cargos recurrentes | ✅ |
| Becas y descuento por hermano | ✅ | — | ≈ descuentos | ✅ |
| Asistencia del técnico sin conexión, "¿Lo llevás?", suspender o reprogramar | ❌ asistencia desde el panel web | — | ❌ asistencia escolar | ✅ |
| Comisión (mandatos, actas, resoluciones) | ❌ | ❌ | ❌ | ✅ mandatos; ⏳ actas |
| Rifas | ❌ | ❌ | ❌ | ⏳ Fase 3 |
| Gastos, cuentas y balance | ✅ | — | ❌ solo facturación y cobranza | ✅ |
| SIFEN | ✅ | — | Factura electrónica sin detalle | ⏳ Fase 4 |
| WhatsApp | ✅ bot no oficial | Soporte | ❌ chat propio | ⏳ Fase 4 |
| Pago online | ❌ en academias | ✅ | ✅ | ⏳ Fase 4 |
| Indumentaria y torneos | ✅ | Torneos ✅ | ❌ | ⏳ Fases 2 y 3 |
| Buscador o catálogo público | ✅ 6 academias, 42 complejos | ✅ canchas y torneos | ❌ | ⏳ etapa C |
| App en las tiendas | ❌ | ✅ | ✅ | ⏳ Sprint 6 |

---

## 4. Panorama global (referencias)

| Producto | País | Modelo y precio | Por qué mirarlo |
|---|---|---|---|
| Spond | Noruega | Gratis; 2,5% + €0,20 por pago | Es el más parecido: una app para varias organizaciones, "Spond Discover" (actividades por edad y zona, en UK y Noruega), rifas, sistema aprobado por la confederación deportiva noruega. Dice tener 4M de usuarios por mes |
| SportsEngine (ahora de PlayMetrics) | EE. UU. | % por pago (ej. 3,25% + US$2) | Directorio de programas con botón de inscripción. PlayMetrics lo compró en may-2026 |
| LeagueApps | EE. UU. | Sin suscripción; % de lo cobrado | "Find more programs" en la app de padres, solo con organizaciones de LeagueApps (como nuestra A+B ampliada) |
| TeamSnap | EE. UU. | Gratis con planes pagos y patrocinio de marcas | Dice tener 30M de usuarios; más de US$20M pagados a organizaciones por sponsors |
| GotSport | EE. UU. | Software gratis + US$3 por inscripción, que paga la familia | Crece con federaciones (US Club Soccer renovó 5 años). Lista a CONMEBOL como socio [alcance sin verificar] |
| Jackrabbit, iClassPro, Pike13 | EE. UU. | US$49–299 por mes y sede | Academias donde el grupo es por nivel (danza, natación) |
| Twizzit, SportEasy, SportMember | Europa | Por miembro: €0,30–0,45 por mes | Referencia para cobrar por alumno |
| ClubZap, Pitchero | Irlanda, Reino Unido | Suscripción + % de pagos | Rifas y loterías como producto central de recaudación |
| Amilia | Canadá | US$99–499 + 1% | Recreación y municipios; levantó US$35M en 2025 |
| 360Player, Coachbetter, Clubee | Suecia, Suiza, Luxemburgo | Por cotización o gratis para clubes federados | Crecen por federaciones (la federación alemana de fútbol invirtió en Coachbetter en 2026) |

**Patrones:**
- **Mínimo esperado:** calendario con confirmación, avisos, familia con varios hijos, inscripción online, cuotas con
  tarjeta, asistencia, reportes y app gratis para padres.
- **Cómo crecieron:**
  - gratis para el técnico, y los padres lo contagian;
  - cobrando por los pagos en vez de suscripción;
  - convenios con federaciones;
  - sponsors que pagan la versión gratis.
- **El mercado se concentra.** PlayMetrics se fusionó con Stack Sports y compró SportsEngine; LeagueApps compra
  competidores. Los líderes locales con volumen de pagos terminan siendo comprados, lo que también es una salida
  posible.
- **Nadie tiene gobernanza de comisión.** Solo software genérico de asociaciones (easyVerein en Alemania, TidyHQ en
  Australia).

---

## 5. Actividades para chicos (referencias para `PLAN_ACTIVIDADES.md`)

| Producto | Modelo | Qué pasó |
|---|---|---|
| Sawyer (EE. UU.) | Software (US$79–399 por mes) + catálogo opcional con comisión del 15–30% | Lo compró DaySmart en 2023. En 2017: "la infraestructura va primero" |
| Playtomic (España) | Primero software de clubes (Syltek), después app de jugadores | 6.000 clubes; levantó €65M en 2025 |
| Eversports (Austria) | Software + catálogo | Cobra 25% (tope €75) **solo por los clientes nuevos que trae** |
| BuscaExtraescolares (España) | Directorio donde los padres piden plaza o información | 7.412 academias; la lista de espera es plan pago. Es lo más parecido a la etapa C |
| Homeroom (EE. UU.), MiAMPA (España) | Actividades dentro de la escuela o de la asociación de padres | Lo más parecido a A+B con ACE |
| Decathlon Activités (Francia) | Catálogo de organizadores | 10% de comisión; promueve las clases de prueba |
| KidPass (EE. UU.) | Catálogo con suscripción para padres | Se vendió en 2021; quiebra (Chapter 11) en dic-2025 |
| Hoop (Reino Unido) | Catálogo que cobraba por reserva | Cerró en 2020 con 1,5M de familias y los ingresos casi en cero; hoy es un directorio gratis |
| Classtivity → ClassPass (EE. UU.) | Buscar y reservar clases | Casi nadie reservaba; pasó a vender un pase de clases de prueba |
| Winnie (EE. UU.) | Buscador de guarderías | Dejó el cobro por contacto y pasó a suscripción con software |

**Lecciones:**
- **Huevo o gallina:** los catálogos puros fracasaron. Funcionaron los que tenían a las organizaciones usando su
  software, y con densidad suficiente por ciudad.
- **Lo que más hace inscribir es la clase de prueba** (ClassPass, KidPass, Sawyer, Decathlon).
  - Para temporadas: solicitud que la organización aprueba.
  - Para cosas cortas (colonia, clínica): reserva directa.
  - Si el cupo está lleno: lista de espera.
- **Cómo se cobró con éxito:** software más pagos. En los catálogos, un cargo solo por clientes nuevos y con tope.
  Fracasaron el cobro por contacto (Winnie) y la suscripción para padres (KidPass).
- **Ubicación:** por ciudad al empezar; la distancia y el mapa, cuando hay densidad.
- **Datos de menores:** consentimiento por organización con los campos que viajan. Paraguay aprobó la **Ley 7593/2025**
  de datos personales, que rige desde nov-2027 (según DLA Piper).

---

## 6. Región (LatAm y España)

| Producto | País | Segmento | Precio aprox. |
|---|---|---|---|
| Clupik | España | Clubes y federaciones, app con marca | Gratis hasta 50 deportistas; US$39–99 por mes; 3–5% + US$0,25 por pago |
| Sphaira | España | Clubes de base | Gratis + 3% + €0,25 por pago; o €5 por jugador al año |
| Membrix | México | Escuelas de fútbol | US$43–49 por mes; plan gratis |
| Zekito | Colombia | Academias deportivas | Gratis hasta 30; ≈ US$22 por mes hasta 150 |
| SportMaps | Colombia | Academias + mapa público | No publica |
| Potrero.app | Argentina | Escuelas de fútbol; el padre sube el comprobante | US$50–80 por mes (en beta) |
| CuotaQ | AR, MX, CO | Cobro de cuotas con QR por WhatsApp | ≈ US$30 por mes |
| Clubin, Lila Clubes | Argentina | Clubes, socios y accesos | ≈ US$45–108 por mes; o US$0,45–0,65 por socio al mes |
| PayMon | México, Ecuador | Academias: clases, cobros, asistencia y app para padres | No publica |
| Fitco, Boxmagic | Perú, Chile | Gimnasios y estudios, algunas academias | US$42–169 por mes |
| Tecnofit, Next Fit, Fensor | Brasil | Gimnasios y escuelitas de fútbol | ≈ US$0,60 por alumno al mes; Fensor R$150–399 por mes |
| ClassApp + isaac, Agenda Edu | Brasil | Comunicación y cobro en colegios | No publican |
| Rifalo | Argentina | Rifas online con Mercado Pago | — |

---

## 7. Qué nos diferencia y qué falta

**Diferenciales:**
- **Comisión con mandatos, actas y resoluciones.** Encaja con las ACE, que por la ley 4853/2012 tienen que rendir
  cuentas.
- **Cuenta familiar con temporadas, cobro por clase asistida, becas y mora.** Los estudios cobran por clase y los
  clubes por temporada; casi nadie hace las dos cosas.
- **Rifas integradas.** En la región solo hay herramientas aparte.
- **Asistencia del técnico sin conexión**, con el "No va" del tutor y suspender o reprogramar clases.
- **Un padre con hijos en varias organizaciones, y vocabulario por organización.**
- **Guaraníes, pagos locales (Bancard, upay/Pagopar) y SIFEN.** Ningún producto de afuera los puede ofrecer.

**Mínimo esperado que falta:**
- **Comprobante subido por el padre.** Lo habitual es transferencia o QR con captura por WhatsApp. Está en Fase 2 y
  conviene adelantarlo al piloto.
- **Links por WhatsApp** (pagar, ver el recibo) sin reemplazar los grupos.
- **Pago online** con cuenta propia de cada organización.
- **SIFEN,** si alguna organización lo exige: preguntarlo a las 4 del piloto.

**Ideas de competidores:**
- **Álbum de fotos por categoría:** es lo que más disfrutan los padres, según los testimonios de Eventifica.
- **Inscripción a una actividad desde un aviso:** encaja con A+B.
- **Página pública por organización con categorías, edades, horarios y cuotas (como MiCancha):** sirve para vender y
  es el primer paso de la etapa C.
- **Carnet del alumno en PDF, y pausa por viaje o lesión:** esto último ya lo cubre la suspensión.
- **Chat por áreas (Tesorería, Coordinación):** le saca al tesorero su WhatsApp personal, pero es lo más caro de
  hacer.

---

## 8. Implicancias para "Actividades para tus hijos" (§8 de `PLAN_ACTIVIDADES.md`)

| Decisión pendiente | Recomendación | Por qué |
|---|---|---|
| ¿A+B o catálogo entre organizaciones? | **A+B primero.** C por ciudad cuando haya densidad (referencia práctica: 15–20 organizaciones de 3 o más rubros) | Con pocas organizaciones el catálogo se ve vacío: el buscador de MiCancha, con 6 academias, es el ejemplo local. Medir en A+B las solicitudes aprobadas, los días hasta aprobar y los hermanos inscriptos en otra actividad |
| ¿Qué hace el botón? | Primero **"Quiero inscribir a …"** (solicitud con aprobación); segundo **"Pedir clase de prueba"**; WhatsApp como tercera opción, contando los clics; lista de espera con aviso cuando se libera un lugar | La clase de prueba es lo que más convierte. Mandar afuera (Hoop, MiCancha con WhatsApp) deja sin datos ni ingresos |
| Modelo comercial | A+B incluido en el plan. En C: publicar gratis y cobrar solo por familias **nuevas** para esa organización, con tope. Destacadas pagas, solo con tráfico | Nadie cobra por ofrecerle actividades a las familias propias (Eventifica, Fresha, BuscaExtraescolares). Evitar el cobro por contacto y la suscripción para padres |
| Ubicación | Ciudad y barrio, guardando coordenadas desde ya | Sirven para "Cómo llegar" y después para ordenar por cercanía |
| Registro abierto (C) | Necesario para C, con consentimiento por organización (qué datos viajan) y registro de versión y fecha | Ley 7593/2025 |

**Además:**
- Mostrar el **descuento por hermano en la tarjeta** de la actividad: nadie lo muestra al buscar.
- **Inicio del tutor con todos sus hijos de todas sus organizaciones**, como Spond y LeagueApps. Va con el §7 del plan
  (hijos a nivel usuario).
- Arrancar C con **registros públicos** como fichas que cada organización puede reclamar. Por ejemplo, la lista del
  MEC de 401 academias de danza reconocidas (≈72 en Asunción y ≈138 en Central).

---

## 9. Precio y modelo comercial

**Referencias regionales:**
- Una academia chica paga US$20–50 por mes; una organización grande, US$80–170.
- Por alumno se cobra US$0,12–0,66 al mes.
- Lo normal es un plan gratis hasta 30–50 alumnos.

**Competidores locales:**
- MiCancha: gratis con planes pagos sin precio publicado.
- Reva: comisión del 10% al 3% por reserva, o US$25 por cancha al mes (2023).
- Eventifica: sin precio publicado; "desde US$49" según Capterra [sin verificar].

**Pagos en Paraguay:**
- Desde el 01/07/2026, las comisiones por tarjeta tienen tope: **3% crédito y 2% débito**, incluidas pasarelas y
  subadquirentes. Los pagos QR sobre transferencias, el débito directo y el inicio de pagos tienen tope de **1,5% con
  IVA** (reglamento de jul-2026).
- Las transferencias entre personas son **gratis** (el Banco Central lo confirmó en jul-2026).
- Según Bancard (sep-2026), el 82% de los pagos de servicios ya es digital y más de 4M de personas usan QR.
- Opciones:
  - **Bancard vPOS/QR:** exige RUC y cuenta bancaria de empresa.
  - **upay** (es dueña de Pagopar; ecosistema ueno): débito y QR 2%, crédito 2,9–3%, acreditación en 24–48 h.
  - Bepsa/Dinelco y billeteras (Tigo Money, Personal, Zimple).
  - Stripe no opera en Paraguay.
- **Implicancia:**
  - cobrar un % de las cuotas deja poco margen y se siente como un peaje;
  - manejar fondos de terceros puede estar regulado por el Banco Central;
  - conviene que cada organización tenga su propia cuenta (upay o Bancard) y que nuestra tarifa vaya aparte;
  - un % sí tiene sentido en la etapa C, por familia nueva.

**SIFEN:**
- Los pequeños contribuyentes pueden usar e-Kuatia'i gratis.
- Proveedores con API: Guarani.App (₲ 253.000 por mes por punto de emisión), BillPy (desde ₲ 79.000 por mes),
  Sifende, FactPy y FactAPI.
- Integrar un proveedor es más rápido que programarlo desde cero.

**Hipótesis para probar con las 4 organizaciones:**
- gratis hasta ~30 alumnos;
- después ~₲ 5.000 por alumno activo al mes, con mínimo de ₲ 150.000 y tope para clubes grandes;
- ACE baratas o gratis, financiadas con el módulo de pagos;
- SIFEN y WhatsApp como extras.

Con cuotas de ₲ 150.000–200.000 (§2.1), la tarifa por alumno es el 2,5–3,3% de la cuota.

**Qué vender:** menos morosos, en guaraníes. Otras empresas prometen entre 40% y 76% menos, pero son cifras de
publicidad. Medir el antes y el después en Jakare y usarlo como caso.

---

## 10. Canales para crecer

- **Federaciones y ligas:**
  - Afuera: Spond con la confederación noruega, GotSport con US Club Soccer, Clubee gratis para clubes federados.
  - Acá: federaciones de escuelas de fútbol (FEPEFU, APEF; COFEFUP, AGREFUP, APEFI y FEFUDCE [estado sin verificar]).
- **Redes de escuelas de clubes grandes:**
  - la franquicia **Cicloncitos** de Cerro Porteño (desde ago-2025), en Paraguay y afuera;
  - Franjeaditos (Olimpia), la escuela de Guaraní y Barça Academy Paraguay.
- **Danza:** la lista del MEC de 401 academias reconocidas (2024) es una lista de posibles clientes ya armada.
- **ACE:** la ley 4853/2012 las obliga a rendir cuentas, y eso es justo lo que hacen nuestra comisión y los informes.
- **Padres:** el que tiene hijos en dos actividades pide la misma app en la otra, y el panel de actividades lo
  refuerza.
- **Ejemplo regional de liga como canal:** ONFI en Uruguay (60 mil chicos, 67 ligas, ~700 clubes).

---

## 11. Riesgos

- **Poca disposición a pagar:** WhatsApp más Excel es gratis, y hay escuelas municipales gratuitas.
- **MiCancha se mueve rápido** y puede sumar un portal para padres.
- **Informalidad:** muchas escuelitas no tienen RUC ni cuenta de empresa. El producto tiene que servir sin pago
  integrado, y hoy ya sirve.
- **WhatsApp no oficial** (bots vinculados por QR): WhatsApp puede bloquear el número. Si se hace, usar links `wa.me`
  o la API oficial, que se paga por conversación.
- **Rifas:** revisar permisos antes de construir (Ley 1016/97, CONAJZAR, municipios) [sin verificar].
- **Datos de menores:** Ley 7593/2025, que rige desde nov-2027.
- **Alcance:** el plan es amplio, y los que ganaron afuera empezaron con algo más acotado. Con 4 organizaciones,
  priorizar lo que las hace pagar y quedarse: cobranza, avisos y asistencia.

---

## 12. Pendiente de verificar

- Precios de los planes Profesional y Premium de MiCancha, y si SIFEN y WhatsApp entran en el plan gratis.
- Precio real de Eventifica (el de Capterra no cierra).
- Si Reva cobra hoy por comisión, por suscripción o las dos cosas; y cómo pasó Fansbury a Reva.
- Si Mercado Pago acepta comercios paraguayos.
- Obligaciones SIFEN de ACE y clubes sin fines de lucro.
- Permisos para rifas.
- Cuotas típicas de escuelitas en Paraguay (solo hay un dato actual: §2.1).

---

## 13. Fuentes

**MiCancha**
- [Portada](https://micancha.com.py/) · [buscador de academias](https://micancha.com.py/academias) · [ingreso de academias](https://micancha.com.py/academias/login)
- [Página de una academia](https://micancha.com.py/academia/delio-toledo) · [manual de torneos](https://micancha.com.py/MANUAL_USUARIO_TORNEOS.html)
- [Certificados del dominio](https://crt.sh/?q=%25.micancha.com.py)

**Reva y Fansbury**
- [Reva](https://reva.la/) · [tarifas y reembolsos](https://reva.la/refunds.html)
- [App Store](https://apps.apple.com/py/app/reva-reserva-de-canchas/id1312023245) · [Google Play](https://play.google.com/store/apps/details?id=py.com.kynox.pelotajara)
- [Forbes Paraguay (2025)](https://www.forbes.com.py/negocios/no-encontraban-donde-jugar-e-idearon-una-solucion-grupo-amigos-creo-reva-una-app-reservas-canchas-hoy-vale-casi-us-2-millones-n67885) · [Startups Latam (2023)](https://startupslatam.com/reva-la-paraguaya-que-gestiona-turnos-en-complejos-deportivos-avanza-en-ronda-pre-seed-para-llegar-a-peru/) · [El Prisma (2026)](https://elprisma.com.py/reva-la-startups-paraguaya-que-gestiona-con-exito-los-clubes-deportivos/)
- [Fansbury en Reva](https://reva.la/fansbury/) · [Mundialito en Google Play](https://play.google.com/store/apps/details?id=com.fansbury.mundialito)
- [fansbury.com en 2025](http://web.archive.org/web/20250805060912/https://www.fansbury.com/) · [términos de Fansbury de 2022](http://web.archive.org/web/20220625011605/http://www.fansbury.com/_files/ugd/65cdf7_71eef4b63cdc4d6a9ac5353997ce05f7.pdf)

**Eventifica**
- [Portada](https://www.eventifica.com/) · [planes](https://www.eventifica.com/precios/) · [comunicación](https://www.eventifica.com/comunicacion/) · [finanzas](https://www.eventifica.com/finanzas/)
- [Google Play](https://play.google.com/store/apps/details?id=com.eventifica.eventifica) · [Capterra](https://www.capterra.com/p/209133/Eventifica/pricing/)

**Global y actividades**
- [Spond: comisiones](https://help.spond.com/club/en/articles/58192-what-is-the-transaction-fee-in-spond-club) · [Spond Discover](https://help.spond.com/club/en/articles/466128-set-up-the-club-s-spond-discover-profile)
- [PlayMetrics compra SportsEngine](https://www.tvtechnology.com/production/sports-production/playmetrics-acquires-sportsengine-from-versant) · [LeagueApps: precios](https://leagueapps.com/pricing/) · [GotSport y US Club Soccer](https://usclubsoccer.org/us-club-soccer-and-gotsport-partnership-renewed/)
- [Sawyer: precios](https://www.hisawyer.com/for-business/pricing) · [DaySmart compra Sawyer](https://www.businesswire.com/news/home/20231106863203/en/DaySmart-Acquires-Sawyer) · [Sawyer en 2017](https://techcrunch.com/2017/08/01/sawyer-a-software-platform-for-kid-classes-raises-6-million-including-from-the-chan-zuckerberg-initiative/)
- [Historia de Playtomic](https://www.adslzone.net/noticias/internet/playtomic-historia-pablo-carro/) · [Eversports](https://www.eversportsmanager.com/) · [BuscaExtraescolares: precios](https://www.buscaextraescolares.com/precios/)
- [Quiebra de KidPass](https://news.bloomberglaw.com/bankruptcy-law/conscious-content-media-inc-files-for-chapter-11-in-delaware) · [Cierre de Hoop](https://asiatechdaily.com/pandemic-victims-story-3-hoop-a-business-model-that-could-not-survive-the-pandemic/) · [Classtivity → ClassPass](https://techcrunch.com/2013/09/18/classtivity-pivots-to-subscription-model-so-you-actually-work-out/)
- [MiAMPA](https://miampa.com/blog/actividades-extraescolares-online)

**Región**
- [Clupik: precios](https://clupik.com/en/pricing/) · [Membrix](https://membrix.mx/software-para-escuelas-de-futbol) · [Potrero.app](https://potrero-app.com.ar/)
- [CuotaQ: precios](https://www.cuotaq.com/precios) · [PayMon](https://www.paymon.io/academias) · [Zekito](https://zekito.com)

**Paraguay: pagos, leyes y canales**
- [Topes de comisiones por tarjeta](https://www.lanacion.com.py/negocios/2025/03/18/reduccion-de-comisiones-por-tarjetas-beneficiara-a-pequenos-comerciantes-segun-el-bcp/) · [Topes en pagos digitales (jul-2026)](https://www.revistaplus.com.py/2026/07/14/nuevo-reglamento-establece-topes-para-comisiones-en-pagos-digitales/) · [Transferencias gratuitas](https://www.lanacion.com.py/negocios/2026/07/20/bcp-ratifica-gratuidad-de-transferencias-y-aclara-a-quienes-aplican-los-nuevos-topes-de-tarifas/)
- [Pagos digitales según Bancard (sep-2026)](https://revistaplus.com.py/2026/09/10/pagos-digitales-ganan-terreno-y-ya-concentran-el-82-de-las-operaciones-de-servicios/) · [Stripe por país](https://stripe.com/global)
- [Ley de datos personales (DLA Piper)](https://www.dlapiperdataprotection.com/index.html?t=law&c=PY) · [ACE: derechos y obligaciones](https://sanlorenzopy.com/72124/aces-cuales-son-sus-derechos-y-obligaciones/)
- [Franquicia Cicloncitos](https://infonegocios.com.py/infodeportes/formacion-con-sello-azulgrana-cerro-porteno-lanza-sistema-de-franquicias-para-expandir-su-escuela-de-futbol-cicloncitos-en-paraguay-y-el-exterior) · [Academias de danza reconocidas por el MEC](https://informacionpublica.paraguay.gov.py/public/2024/1721910417_1_84.654LISTADODEACADEMIASDEDANZARECONOCIDASPORELMEC.pdf) · [FEPEFU](https://fepefu.org/contactar/)
