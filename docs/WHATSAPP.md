# WhatsApp y correos de Tuku

Qué sale por WhatsApp, cómo se copia por correo y cómo se carga la marca en el número de WhatsApp.

## Qué sale por WhatsApp (y su copia por correo)

| Qué | WhatsApp | Correo |
|---|---|---|
| Código para crear la cuenta | Cloud API de Meta, plantilla de autenticación (`SendWhatsAppCode`) | Copia con **su propio código** al correo de la cuenta, con "Confirmar mi correo" |
| Código de "Olvidé mi contraseña" | Igual | Copia solo a un correo **verificado** |
| Invitación | Quien invita la comparte con `wa.me` (texto de `Invitation::whatsappText()`; en la app, `invitationMessage()`) | `InvitationMail` si la invitación tiene correo |
| Avisos (día de clase, clase suspendida, …) | No (van por push) | Copia de cada push (`App\Notifications\PushNotification`) |

Regla: **lo que sale por WhatsApp sale también por correo**. El correo de las copias es el verificado o, si la cuenta
no tiene celular, el correo con el que se creó (`User::mailableEmail()`).

## Marca en el número de WhatsApp

- **Nombre visible:** "Tuku". Se pide en el Administrador de WhatsApp (Números de teléfono → Nombre visible) y lo
  revisa Meta; tiene que coincidir con la marca de https://tukuha.app.
- **Perfil** (`App\Support\WhatsApp\Branding`):
  - Foto: `public/brand/whatsapp-perfil.png` (640x640, la cara de Tuku sobre verde; WhatsApp la recorta en círculo).
  - Presentación: "Tuku: cuotas, asistencia y avisos de clase para academias, clubes y escuelas. Todo en una app."
  - Descripción: qué es Tuku, que el número solo manda códigos y no lee mensajes, y el correo de ayuda.
  - Correo `TUKU_EMAIL`, sitio `TUKU_URL` y categoría Educación (`EDU`).
- **Plantilla del código** (`WHATSAPP_CODE_TEMPLATE`, por defecto `codigo_verificacion`, idioma `es`): categoría
  autenticación, con la recomendación de seguridad, el vencimiento de 15 minutos y el botón "Copiar código". Los
  textos los pone Meta:

  > **042137** es tu código de verificación. Por tu seguridad, no lo compartas.
  > Este código caduca en 15 minutos.
  > [Copiar código]

### Cargarla

```bash
php artisan whatsapp:brand              # perfil (con la foto) y plantilla
php artisan whatsapp:brand --profile    # solo el perfil
php artisan whatsapp:brand --template   # solo la plantilla (Meta la revisa: queda PENDING)
```

Necesita `WHATSAPP_TOKEN` y `WHATSAPP_PHONE_NUMBER_ID`; para la foto, `WHATSAPP_APP_ID` (la app de Meta, para la
subida reanudable) y para la plantilla, `WHATSAPP_BUSINESS_ACCOUNT_ID`. Sin esos dos, la foto se sube a mano en el
Administrador de WhatsApp y la plantilla se crea ahí con los mismos datos. Si cambian los textos, se vuelve a correr.

## Vista previa de los links

Las invitaciones llevan al link de la app web (`APP_FRONTEND_URL/invitacion/{token}`). WhatsApp muestra la tarjeta de
las etiquetas `og:` de `web/index.html` de la app: "Tuku", la presentación y
`https://tukuha.app/brand/tuku-tarjeta-redes.png`.

## Correos

- Marca en `resources/views/vendor/mail` (encabezado con el logo, pie con la presentación, componentes `mascot` y
  `buttons`) y el tema `html/themes/tuku.css` (`config/mail.php` → `markdown.theme`). Las imágenes van por URL
  (`public/brand/correo/*.png`, al doble del tamaño en que se muestran), así que `APP_URL` tiene que ser público.
- Las notificaciones con `MailMessage` usan `resources/views/vendor/notifications/email.blade.php` (en español).
- Mascota: `hola` en bienvenidas e invitaciones, `salta` en reservas, `descansa` en clases suspendidas. Nunca junto a
  deudas.

## En desarrollo

Con `WHATSAPP_DEV_DRIVER=mail`, cada WhatsApp llega a Mailpit (http://localhost:8025) como un correo a
`{número}@whatsapp.test` que se ve como el chat con Tuku (foto, nombre y la plantilla con su botón).

## Producción

- `MAIL_FROM_NAME="Tuku"` y `MAIL_FROM_ADDRESS` de `tukuha.app` (con SPF y DKIM del proveedor de SMTP).
- `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_APP_ID`; después,
  `php artisan whatsapp:brand`.
- `APP_FRONTEND_URL`: la app web a la que llevan los botones "Ver en Tuku" y las invitaciones.
