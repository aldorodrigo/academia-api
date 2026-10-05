# Plan — cobro en efectivo desde la app y caja del técnico

> Hallazgo **F1** de la prueba integral (2026-10-04). Entra en el **MVP**.
> Contrato: sección «Cobro en efectivo y caja del técnico» de `API_V1.md`. Reglas: `business-logic.md` §8 y §9.

## 1. Problema

En Jakare casi la mitad de las cuotas se pagan **en efectivo en la cancha**, al técnico. Hoy eso solo se registra en
el panel (`RegisterPayment` desde Finanzas → Pagos), así que el técnico anota en un papel, le pasa la plata y el papel
al tesorero y este carga el pago días después. Mientras tanto:

- la familia no tiene recibo y su estado de cuenta en la app sigue diciendo que debe;
- nadie sabe cuánta plata del club tiene cada técnico en el bolsillo;
- si la plata no llega al tesorero, no queda rastro.

La app solo cobra **clases particulares** (`POST teacher/payments`), que entra en la cuenta del perfil del profesor.

## 2. Decisiones

Pedido del usuario: "permite que el técnico tenga esa plata hasta depositar en la cuenta del club. Agregá una
notificación y una cuenta para él."

1. **Caja personal.** Cada persona que cobra en efectivo desde la app tiene su caja: una `MoneyAccount` de tipo `caja`
   con titular (`money_accounts.user_id`), llamada "Caja de Juan Pérez". Se crea sola con el primer cobro. Es una cuenta
   del club como cualquier otra: entra en saldos, balance e informes. Es **plata del club en poder de esa persona**.
2. **Cobrar = `RegisterPayment`.** El cobro desde la app es un pago normal (método efectivo, fecha de hoy) que entra en
   la caja personal de quien cobra. La imputación, el pronto pago, el saldo a favor, el recibo correlativo, el
   movimiento del libro mayor y la activación de paquetes son los de siempre: no se duplica nada.
3. **Depositar = rendición con confirmación.** El técnico informa "Deposité ₲ 450.000 en Banco Itaú" (o "Se lo di al
   tesorero" → Caja del club). Queda **por confirmar**: la plata sigue en su caja hasta que quien valida la confirma; al
   confirmar se registra una **`Transfer`** (`TransferFunds`) de su caja a la cuenta del club. Si se rechaza (motivo),
   sigue en su caja. Así el saldo del banco nunca muestra plata que todavía no llegó.
4. **Quién cobra:** permiso nuevo **`Collect:Payments`** ("Cobrar en efectivo desde la app"; en la app
   `collect_payments`). Lo tienen por defecto **técnico, tesorero y protesorero** (`DefaultPermissions`), el admin por
   `Gate::before`. Es configurable en Roles: sacárselo al rol técnico corta el cobro para todos los técnicos; a una
   persona sola se le corta cerrando su caja (cuenta inactiva).
5. **A quién cobra:** quien ve todos los alumnos (`ViewAny:Student`: tesorero, secretario, admin) cobra a cualquiera; el
   resto, a los alumnos con inscripción vigente o próxima en **sus grupos** (los de `AttendanceAccess`). Ve las cuotas
   pendientes de **toda la familia** (alumno, descripción y montos), porque en la cancha se pagan juntas las de los
   hermanos. No ve pagos anteriores, saldos de otras familias ni la ficha.
6. **Quién confirma los depósitos:** los mismos que validan los comprobantes de transferencia (tesorero, protesorero,
   admin y quien tenga "Validar comprobantes de pago"; `review_payment_reports` en la app). Confirman en el panel o en
   la app.
7. **Avisos** (push + correo, `PushNotification`):
   - **a la familia, en cada cobro:** "Recibimos tu pago de ₲ 150.000 en efectivo (cobró Juan Pérez). Recibo N° 000124."
     Es el control contra el cobro sin registrar: la familia espera ese aviso.
   - **a quienes validan, cuando un técnico informa un depósito:** "Juan Pérez depositó ₲ 450.000 en Banco Itaú.
     Confirmalo." No se avisa a la comisión por cada cobro (sería ruido en un día de cancha): lo ven en "Efectivo".
   - **al técnico, cuando le confirman o rechazan el depósito.**
8. **Transferencia que la familia le mandó al club** (segunda ronda, pedido del usuario 2026-10-04): el técnico, el
   tesorero o el admin registran desde la misma pantalla de "Cobrar" la transferencia que un papá o un alumno les pasó
   por WhatsApp (la captura). Se **reutiliza el comprobante de transferencia** (`PaymentReport`) cargado en nombre de
   la familia: familia, cuotas, monto, fecha, cuenta, referencia, la imagen o el PDF guardado, el tutor que la mandó y
   **quién la registró** (`user_id` + `registered_by_staff`).
   - Si quien la registra **valida comprobantes** (tesorero, protesorero, admin): queda **aprobada al instante** y se
     registra el pago por transferencia con su recibo (`ReviewPaymentReport::approve`, el mismo de siempre).
   - Si es el **técnico**: queda **en revisión** y avisa a quienes validan ("Juan Pérez registró una transferencia de
     ₲ 300.000 (Familia Benítez)."). Un técnico no mete plata en el banco sin control.
   - La familia la ve en su estado de cuenta ("Registrado por Juan Pérez") con el comprobante y, aprobada, el recibo.
     Al aprobarse le llega "Aprobamos tu pago…" y, si la aprobó otro, al técnico "Aprobamos la transferencia … que
     registraste". Si se rechaza, el motivo le llega a quien la registró (la familia no la informó).
   - Cuenta: un banco o billetera **del club** (sin las cajas personales); sin elegir, la primera bancaria.
9. **Cajas personales fuera de los selectores** de cuenta donde entra un pago: "Registrar pago" del panel y aprobar
   comprobantes (panel y app). Siguen en Cuentas, Transferencias (el tesorero recibe la plata en mano) y Gastos (un
   faltante de caja se registra como gasto desde la caja del técnico). Las cuentas de clases particulares no cambian.
10. **"Registrar pago" del panel** (tercera ronda): con método Efectivo la cuenta por defecto es la **caja personal de
    quien registra** (la única caja personal que ve; se crea al registrar si no existe), igual que en la app. Con
    transferencia o billetera, la primera cuenta bancaria o billetera del club. La Caja del club y las demás cuentas del
    club se siguen pudiendo elegir; la caja de otra persona, no.
11. **Comprobantes rechazados en el estado de cuenta** (tercera ronda, lo decide la API con `open`): un rechazado se ve
    arriba mientras alguna de sus cuotas siga pendiente; cuando están todas pagadas (por otro comprobante, en efectivo o
    como sea) pasa al historial. Sin cuotas, 30 días. En "Informar transferencia" el monto se completa con lo que falta
    de las cuotas elegidas (mientras no se edite a mano) y avisa si es parcial o si sobra, igual que en Cobrar.
12. **Soft delete** (regla del proyecto): retirar un depósito por confirmar o un comprobante en revisión (también el
    que queda a medias si no se pudo aprobar al registrarlo) no borra el registro ni el archivo; deja de aparecer en
    listas, en lo disponible de la caja y en lo "en revisión". Pagos, transferencias y movimientos ya no se borraban
    (se anulan).
13. **Cobrar requiere conexión.** No se encola sin señal (a diferencia de la asistencia): el número de recibo es
   correlativo y lo da la API. Un reintento por mala señal no duplica el cobro (`request_id`, ver §6).

## 3. Modelo

| Tabla | Cambio |
|---|---|
| `money_accounts` | `user_id` nullable (titular de la caja personal), único por organización + usuario |
| `payment_reports` | `registered_by_staff` (lo registró el club; `user_id` = quién) y `guardian_id` (tutor que la mandó) |
| `cash_deposits` (nueva) | `organization_id`, `money_account_id` (caja personal), `user_id` (quien deposita), `to_account_id` (cuenta del club), `amount`, `deposited_on`, `reference`, `notes`, `status` (`pendiente`, `confirmado`, `rechazado`), `reviewed_by`, `reviewed_at`, `rejection_reason`, `transfer_id` |

- `CashBox::for($user)` busca o crea la caja personal ("Caja de {nombre}"). `MoneyAccount::clubAccounts()` = activas sin
  titular (a donde se deposita).
- **Saldo de la caja** = suma de sus movimientos, como toda cuenta. **Disponible para depositar** = saldo − depósitos
  por confirmar.
- Un depósito confirmado cuya transferencia se anula en el panel se muestra "Anulado" (la plata vuelve a su caja).
- Acciones: `CollectCashPayment` (arma la imputación y llama a `RegisterPayment`), `CashDeposits` (informar, retirar,
  confirmar con `TransferFunds`, rechazar). Acceso en `CashCollectionAccess`.

## 4. App

- **"Cobrar"** (`/cobrar`, botón del inicio con `collect_payments`): alumnos que puede cobrar, con buscador y lo que
  debe la familia hoy. También desde **"Mis grupos"** (tocar un alumno del grupo).
- **Cobrar a un alumno** (`/cobrar/:id`): familia, cuotas pendientes (de la más vieja a la más nueva; las vencidas y las
  de hoy elegidas, las próximas aparte y sin elegir, las que tienen una transferencia en revisión marcadas y sin elegir),
  **monto prellenado** con lo que salda las elegidas hoy (con pronto pago), quién pagó (tutor) y nota. "Cobrar ₲ X" →
  recibo: "Cobrado ₲ 150.000 · Recibo N° 000124" con "Ver recibo"; si sobra, "Quedan ₲ 20.000 a favor de la familia".
- En la misma pantalla, **Efectivo / Transferencia**: con "Transferencia" se adjunta la captura o el PDF (como
  "Informar transferencia" del tutor), fecha, cuenta del club y N° de operación; el botón dice "Registrar
  transferencia" y el texto avisa si queda aprobada con recibo o en revisión del tesorero.
- **"Mi caja"** (`/mi-caja`, botón del inicio con `collect_payments`): "En tu poder: ₲ 585.000", depósitos por
  confirmar, movimientos (cobros, depósitos, anulaciones) y **"Depositar"**: monto (por defecto todo lo disponible),
  dónde (cuentas del club), fecha y N° de boleta opcional.
- **"Efectivo"** (`/efectivo`, botón del inicio con `review_payment_reports`): total en poder de técnicos, caja por
  caja, y depósitos por confirmar con **Confirmar** / **Rechazar** (motivo).

## 5. Panel

- **Finanzas → Depósitos de efectivo** (contador de pendientes): Confirmar / Rechazar, filtro por estado.
- **Finanzas → Cuentas:** columna "En poder de" (las cajas personales) y saldo, como siempre. El tesorero también puede
  recibir la plata en mano con una **Transferencia** de la caja del técnico a la Caja (ya existe, sin confirmación).
- Los pagos cobrados desde la app aparecen en Pagos con su cuenta ("Caja de Juan Pérez") y se anulan desde ahí.

## 6. Casos borde

- **Cobro parcial:** el monto menor a lo elegido se imputa en orden de vencimiento; la última cuota queda parcialmente
  pagada.
- **Pago de más / sin cuotas:** lo que sobra queda como saldo a favor de la familia (se aplica solo a la próxima cuota),
  igual que en el panel. Se muestra el saldo a favor que ya tenía.
- **Pronto pago:** el monto sugerido ya lo descuenta si se paga hoy (lo calcula la API).
- **Transferencia registrada dos veces:** una cuota no puede estar en dos comprobantes en revisión ("Ya hay una
  transferencia en revisión para «Cuota agosto 2026»."), sea del tutor o del club.
- **Cuota con una transferencia en revisión:** se puede cobrar igual (la familia pagó en efectivo); si después se
  aprueba la transferencia, lo que ya no tiene cuota pendiente queda a favor (regla actual de `ReviewPaymentReport`).
- **Doble toque o reintento:** la app manda un `request_id` por cobro; si llega dos veces, la API devuelve el mismo pago.
- **Anular un pago:** en el panel, como siempre (`VoidPayment`): el contra-movimiento sale de la caja del técnico. Si ya
  lo había depositado, su caja queda negativa y se ve; se arregla con una transferencia entre cuentas. El técnico no
  anula desde la app: le avisa al tesorero.
- **Depósito mayor a lo disponible:** `422` "Tenés ₲ 285.000 para depositar.".
- **Técnico que se va con plata:** al desactivar su membresía no entra más a la app, pero su caja sigue con el saldo y
  el nombre (se ve en Cuentas y en "Efectivo" como "ya no está en el club"). Cuando devuelve la plata, una transferencia
  a la Caja; si no la devuelve, un gasto desde su caja ("Faltante de caja"). No se borra nada.
- **Caja cerrada** (cuenta inactiva): no puede cobrar ("Tu caja está cerrada. Hablá con el tesorero.") pero sí depositar
  lo que tiene.
- **Alumno de otro grupo / de otra organización:** `404` (tenancy y alcance por grupos).
- **Clases particulares:** `POST teacher/payments` no cambia (sigue entrando en la cuenta del perfil del profesor).

## 7. Fuera de alcance

- Cobrar sin conexión, anular desde la app, foto de la boleta de depósito.
- Arqueo o cierre de caja diario, tope de efectivo en poder y recordatorios de "tenés plata sin depositar".
- Pagos o comisiones a los técnicos.
- Mandar el recibo por WhatsApp desde la app (la familia lo recibe por push y correo y lo ve en su estado de cuenta).

## 8. Orden de trabajo

Segunda ronda (2026-10-04): transferencia registrada por el club, cajas fuera de los selectores y decisiones
confirmadas por el usuario (tesorero y admin cobran en efectivo a su caja igual que el técnico; clases particulares
sin cambios; a la comisión solo se le avisa de los depósitos; los depósitos los confirman quienes validan
comprobantes).

1. Contrato en `API_V1.md`.
2. App contra el contrato (fakes): repositorio, validaciones, pantallas, rutas y botones; tests.
3. API: migración (incluye dar `Collect:Payments` a los roles técnico, tesorero y protesorero que ya existen), acciones,
   endpoints, permiso en `GET organization`, avisos, panel; tests de feature.
4. `business-logic.md` §2 (permisos por defecto) y §8 (cobro en efectivo y caja del técnico).
