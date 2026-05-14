<?php
/**
 * BrevoMailer — envío de emails via Brevo (ex Sendinblue) API v3
 * No requiere librerías externas, usa cURL nativo de PHP
 */
class BrevoMailer
{
    private static function config(): array
    {
        return [
            'api_key'    => self::getSetting('brevo_api_key'),
            'from_email' => self::getSetting('brevo_from_email') ?: 'no-reply@sistema.cl',
            'from_name'  => self::getSetting('brevo_from_name')  ?: 'SistemaPabellones',
            'activo'     => (bool)(int)self::getSetting('email_activo'),
        ];
    }

    private static function getSetting(string $clave): string
    {
        try {
            $row = Database::fetchOne(
                "SELECT valor FROM configuracion_sistema WHERE clave=?", [$clave]
            );
            return $row['valor'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Enviar un email
     * @param string $to_email   Destinatario
     * @param string $to_name    Nombre destinatario
     * @param string $subject    Asunto
     * @param string $html       Cuerpo HTML
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    public static function enviar(
        string $to_email,
        string $to_name,
        string $subject,
        string $html
    ): array {
        $cfg = self::config();

        if (!$cfg['activo']) {
            return ['ok' => false, 'msg' => 'Email no habilitado en configuración.'];
        }
        if (!$cfg['api_key']) {
            return ['ok' => false, 'msg' => 'API Key de Brevo no configurada.'];
        }
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => 'Email destinatario inválido.'];
        }

        $payload = json_encode([
            'sender'     => ['name' => $cfg['from_name'], 'email' => $cfg['from_email']],
            'to'         => [['email' => $to_email, 'name' => $to_name]],
            'subject'    => $subject,
            'htmlContent'=> $html,
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: '.$cfg['api_key'],
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'msg' => 'Error cURL: '.$err];
        }
        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'msg' => 'Email enviado correctamente.'];
        }
        $resp = json_decode($response, true);
        return ['ok' => false, 'msg' => 'Brevo error '.$http.': '.($resp['message'] ?? $response)];
    }

    // ── Plantillas de email ──────────────────────────────

    public static function templateBase(string $titulo, string $contenido, string $logo_url = ''): string
    {
        $logo_html = $logo_url
            ? "<img src='$logo_url' alt='Logo' style='max-height:60px;max-width:200px;margin-bottom:16px'>"
            : "<div style='font-size:1.3rem;font-weight:700;color:#1a6fa3;margin-bottom:16px'>🏥 SistemaPabellones</div>";

        return "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'></head>
<body style='margin:0;padding:0;background:#f0f4f8;font-family:Segoe UI,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr><td align='center' style='padding:32px 16px'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
  <tr><td style='background:#1a6fa3;border-radius:10px 10px 0 0;padding:24px;text-align:center'>
    $logo_html
    <div style='color:#fff;font-size:1.1rem;font-weight:600'>$titulo</div>
  </td></tr>
  <tr><td style='padding:28px 32px'>
    $contenido
  </td></tr>
  <tr><td style='background:#f8fafc;border-radius:0 0 10px 10px;padding:16px 32px;text-align:center;color:#94a3b8;font-size:.78rem'>
    SistemaPabellones &mdash; Mensaje automático, no responder a este correo.
  </td></tr>
</table>
</td></tr>
</table>
</body></html>";
    }

    /**
     * Notificar al médico: solicitud aprobada o rechazada
     */
    public static function notificarCambioSolicitud(array $solicitud, array $medico, string $logo_url = ''): array
    {
        $estado  = $solicitud['estado'];
        $colores = [
            'aprobada'  => ['#16a34a','✅ Solicitud Aprobada'],
            'rechazada' => ['#dc2626','❌ Solicitud Rechazada'],
            'cancelada' => ['#6b7280','Solicitud Cancelada'],
            'completada'=> ['#0ea5e9','Solicitud Completada'],
        ];
        [$color, $titulo] = $colores[$estado] ?? ['#3788d8', 'Actualización de Solicitud'];

        $pabellon = $solicitud['pabellon_nombre'] ?? 'Por asignar';
        $fecha    = isset($solicitud['fecha_solicitada'])
            ? date('d/m/Y', strtotime($solicitud['fecha_solicitada'])) : '—';
        $horario  = (substr($solicitud['hora_inicio'] ?? '', 0, 5))
                  .' – '.(substr($solicitud['hora_fin'] ?? '', 0, 5));

        $obs_html = $solicitud['observacion']
            ? "<p style='background:#f8fafc;border-left:3px solid #e2e8f0;padding:8px 12px;border-radius:4px;color:#475569;font-size:.9rem'>
                <strong>Observación:</strong> ".htmlspecialchars($solicitud['observacion'])."</p>"
            : '';

        $contenido = "
        <p style='color:#475569'>Estimado/a <strong>".htmlspecialchars($medico['nombres'].' '.$medico['apellidos'])."</strong>,</p>
        <p style='color:#475569'>Le informamos que su solicitud de pabellón ha sido actualizada:</p>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin:16px 0'>
          <tr><td style='background:#f8fafc;border-radius:8px;padding:16px'>
            <table width='100%'>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Estado</td>
                <td style='text-align:right'><span style='background:$color;color:#fff;padding:3px 10px;border-radius:20px;font-size:.85rem;font-weight:600'>".ucfirst($estado)."</span></td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Cirugía</td>
                <td style='text-align:right;color:#1e293b;font-weight:600'>".htmlspecialchars($solicitud['cirugia'] ?? '—')."</td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Fecha</td>
                <td style='text-align:right;color:#1e293b'>$fecha</td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Horario</td>
                <td style='text-align:right;color:#1e293b'>$horario</td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Pabellón</td>
                <td style='text-align:right;color:#1e293b;font-weight:600'>$pabellon</td>
              </tr>
            </table>
          </td></tr>
        </table>
        $obs_html
        <p style='color:#94a3b8;font-size:.82rem'>Puede revisar el detalle en el sistema.</p>";

        return self::enviar(
            $medico['email'],
            $medico['nombres'].' '.$medico['apellidos'],
            $titulo.' — SistemaPabellones',
            self::templateBase($titulo, $contenido, $logo_url)
        );
    }

    /**
     * Notificar al admin: nueva solicitud pendiente
     */
    public static function notificarNuevaSolicitud(array $solicitud, array $admin, string $logo_url = ''): array
    {
        $fecha   = date('d/m/Y', strtotime($solicitud['fecha_solicitada']));
        $horario = substr($solicitud['hora_inicio'], 0, 5).' – '.substr($solicitud['hora_fin'], 0, 5);
        $titulo  = '📋 Nueva Solicitud de Pabellón';

        $contenido = "
        <p style='color:#475569'>Estimado/a administrador/a,</p>
        <p style='color:#475569'>Se ha recibido una nueva solicitud de pabellón que requiere su atención:</p>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin:16px 0'>
          <tr><td style='background:#f8fafc;border-radius:8px;padding:16px'>
            <table width='100%'>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Médico</td>
                <td style='text-align:right;color:#1e293b;font-weight:600'>".htmlspecialchars($solicitud['medico'] ?? '—')."</td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Cirugía</td>
                <td style='text-align:right;color:#1e293b'>".htmlspecialchars($solicitud['cirugia'] ?? '—')."</td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Fecha solicitada</td>
                <td style='text-align:right;color:#1e293b'>$fecha</td>
              </tr>
              <tr>
                <td style='color:#64748b;font-size:.85rem;padding:4px 0'>Horario</td>
                <td style='text-align:right;color:#1e293b'>$horario</td>
              </tr>
            </table>
          </td></tr>
        </table>
        <p style='text-align:center;margin:24px 0'>
          <a href='".APP_URL."/modules/solicitudes/index.php'
             style='background:#1a6fa3;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block'>
            Ver solicitudes pendientes
          </a>
        </p>";

        return self::enviar(
            $admin['email'],
            $admin['nombres'].' '.$admin['apellidos'],
            'Nueva solicitud pendiente — SistemaPabellones',
            self::templateBase($titulo, $contenido, $logo_url)
        );
    }
}
