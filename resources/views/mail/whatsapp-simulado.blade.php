{{-- Desarrollo: el chat de WhatsApp con Tuku tal como llega el código (plantilla de autenticación de Meta). --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp de Tuku</title>
</head>
<body style="margin: 0; padding: 24px 0; background-color: #e5ddd5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td align="center">
<table width="360" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 360px; background-color: #efeae2; border-radius: 16px; overflow: hidden;">
<tr>
<td style="background-color: #008069; padding: 12px 16px;">
<table cellpadding="0" cellspacing="0" role="presentation"><tr>
<td style="padding-right: 12px;"><img src="{{ asset('brand/whatsapp-perfil.png') }}" width="40" height="40" alt="" style="display: block; border-radius: 20px;"></td>
<td>
<div style="color: #ffffff; font-size: 16px; font-weight: 700;">Tuku</div>
<div style="color: #d9fdd3; font-size: 12px;">Cuenta de empresa</div>
</td>
</tr></table>
</td>
</tr>
<tr>
<td style="padding: 16px 12px 8px;">
<div style="background-color: #ffffff; border-radius: 0 12px 12px 12px; padding: 10px 12px 6px; color: #111b21; font-size: 15px; line-height: 1.4; max-width: 300px;">
<p style="margin: 0 0 6px;"><strong>{{ $code }}</strong> es tu código de verificación. Por tu seguridad, no lo compartas.</p>
<p style="margin: 0 0 4px; color: #667781; font-size: 13px;">Este código caduca en {{ $minutes }} minutos.</p>
<div style="text-align: right; color: #667781; font-size: 11px;">{{ now()->format('H:i') }}</div>
<div style="border-top: 1px solid #e9edef; margin-top: 6px; padding: 10px 0 4px; text-align: center; color: #027eb5; font-size: 14px; font-weight: 600;">{{ $button }}</div>
</div>
</td>
</tr>
<tr>
<td style="padding: 8px 16px 16px; color: #667781; font-size: 12px; text-align: center;">
{{ $about }}<br>
(WhatsApp simulado al {{ $phoneDisplay }}.)
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
