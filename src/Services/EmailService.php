<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class EmailService
{
    private string $transport;
    private bool $habilitado;

    public function __construct()
    {
        // MAIL_TRANSPORT: 'smtp' (PHPMailer vía SMTP autenticado), 'mail' (mail()
        // nativo del servidor — recomendado en cPanel, donde Exim entrega el correo
        // del propio dominio sin credenciales) o 'log' (no envía, solo registra;
        // para dev/tests).
        $this->transport = strtolower((string) Config::get('MAIL_TRANSPORT', 'smtp'));
        $this->habilitado = match ($this->transport) {
            'mail'  => Config::get('SMTP_FROM', '') !== '',
            'log'   => true,
            default => Config::get('SMTP_HOST', '') !== '',
        };
    }

    /**
     * Envía la contraseña temporal a un usuario recién creado o con reset.
     * Si el email no está configurado o el usuario no tiene email, se omite silenciosamente.
     *
     * @param string $motivo 'creacion' | 'reset_admin' | 'olvido'
     * @return bool true si el mensaje fue aceptado para envío. Los flujos donde el
     *              usuario depende del correo para no quedar bloqueado (p. ej.
     *              recuperación de clave) DEBEN chequear este retorno antes de
     *              pisar el hash.
     */
    public function enviarPasswordTemporal(
        string $destinatario,
        string $nombre,
        string $rut,
        string $passwordTemporal,
        string $motivo = 'creacion'
    ): bool {
        if (!$this->habilitado || $destinatario === '') return false;

        $asunto = match ($motivo) {
            'creacion' => 'Tu acceso a Atankalama Limpieza',
            'olvido'   => 'Recuperación de contraseña — Atankalama Limpieza',
            default    => 'Tu contraseña fue reseteada — Atankalama Limpieza',
        };

        $cuerpo = $this->plantillaPasswordTemporal($nombre, $rut, $passwordTemporal, $motivo);

        return $this->enviar($destinatario, $nombre, $asunto, $cuerpo);
    }

    private function enviar(string $destinatario, string $nombreDest, string $asunto, string $cuerpoHtml): bool
    {
        if ($this->transport === 'log') {
            // Transport de dev/tests: no envía nada. OJO: nunca registrar el cuerpo
            // (contiene la contraseña temporal).
            Logger::info('email', 'Email simulado (MAIL_TRANSPORT=log)', [
                'destinatario' => $destinatario,
                'asunto'       => $asunto,
            ]);
            return true;
        }

        try {
            $mail = new PHPMailer(true);

            if ($this->transport === 'mail') {
                // mail() nativo de PHP: el MTA local (Exim en cPanel) entrega el
                // correo del dominio sin autenticación SMTP. PHPMailer arma los
                // headers MIME/UTF-8 y llama a mail() por debajo.
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host       = Config::get('SMTP_HOST', '');
                $mail->SMTPAuth   = true;
                $mail->Username   = Config::get('SMTP_USER', '');
                $mail->Password   = Config::get('SMTP_PASS', '');
                $mail->SMTPSecure = Config::get('SMTP_ENCRYPTION', 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int) Config::get('SMTP_PORT', '587');
                $mail->Timeout    = 10;
            }

            $mail->CharSet = 'UTF-8';

            $mail->setFrom(
                Config::get('SMTP_FROM', Config::get('SMTP_USER', '')),
                Config::get('SMTP_FROM_NAME', 'Atankalama Limpieza')
            );
            $mail->addAddress($destinatario, $nombreDest);

            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $cuerpoHtml;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $cuerpoHtml));

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            // No bloquear el flujo principal si el email falla
            Logger::warning('email', 'Fallo al enviar email', [
                'destinatario' => $destinatario,
                'asunto'       => $asunto,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function plantillaPasswordTemporal(string $nombre, string $rut, string $pwd, string $motivo): string
    {
        $appUrl     = htmlspecialchars(rtrim(Config::get('APP_URL', 'http://localhost:8000'), '/'));
        $appNombre  = htmlspecialchars(Config::get('APP_NAME', 'Atankalama Limpieza'));
        $nombreHtml = htmlspecialchars($nombre);
        $rutHtml    = htmlspecialchars($rut);
        $pwdHtml    = htmlspecialchars($pwd);

        $intro = match ($motivo) {
            'creacion' => "Tu cuenta en <strong>{$appNombre}</strong> ha sido creada. A continuación encontrarás tus credenciales de acceso:",
            'olvido'   => "Recibimos una solicitud para recuperar tu contraseña en <strong>{$appNombre}</strong>. Usa esta contraseña temporal para ingresar:",
            default    => "Un administrador ha reseteado tu contraseña en <strong>{$appNombre}</strong>. Usa las siguientes credenciales para ingresar:",
        };

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f3f4f6;font-family:Inter,system-ui,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
            <tr><td align="center">
              <table width="100%" style="max-width:480px;">

                <!-- Cabecera -->
                <tr><td style="background:#2563eb;border-radius:12px 12px 0 0;padding:28px 32px;">
                  <p style="margin:0;color:#fff;font-size:20px;font-weight:700;">{$appNombre}</p>
                  <p style="margin:4px 0 0;color:#bfdbfe;font-size:13px;">Sistema de gestión de limpieza hotelera</p>
                </td></tr>

                <!-- Cuerpo -->
                <tr><td style="background:#fff;padding:32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
                  <p style="margin:0 0 16px;color:#374151;font-size:15px;">Hola, <strong>{$nombreHtml}</strong></p>
                  <p style="margin:0 0 24px;color:#6b7280;font-size:14px;line-height:1.6;">{$intro}</p>

                  <!-- Credenciales -->
                  <table width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:24px;">
                    <tr>
                      <td style="padding:8px 0;">
                        <p style="margin:0;color:#9ca3af;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Usuario (RUT)</p>
                        <p style="margin:4px 0 0;color:#111827;font-size:16px;font-weight:600;font-family:monospace;">{$rutHtml}</p>
                      </td>
                    </tr>
                    <tr><td style="padding:4px 0;"><hr style="border:none;border-top:1px solid #e5e7eb;margin:4px 0;"></td></tr>
                    <tr>
                      <td style="padding:8px 0;">
                        <p style="margin:0;color:#9ca3af;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Contraseña temporal</p>
                        <p style="margin:4px 0 0;color:#111827;font-size:22px;font-weight:700;font-family:monospace;letter-spacing:2px;">{$pwdHtml}</p>
                      </td>
                    </tr>
                  </table>

                  <!-- Aviso cambio -->
                  <table width="100%" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-bottom:24px;">
                    <tr>
                      <td>
                        <p style="margin:0;color:#92400e;font-size:13px;font-weight:600;">⚠️ Debes cambiar tu contraseña al ingresar por primera vez.</p>
                      </td>
                    </tr>
                  </table>

                  <!-- Botón -->
                  <table width="100%"><tr><td align="center">
                    <a href="{$appUrl}/login"
                       style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 32px;border-radius:8px;">
                      Ir a la aplicación
                    </a>
                  </td></tr></table>
                </td></tr>

                <!-- Pie -->
                <tr><td style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;">
                  <p style="margin:0;color:#9ca3af;font-size:12px;text-align:center;">
                    Si no esperabas este mensaje, ignóralo o contacta al administrador del sistema.<br>
                    © Atankalama Corp — Calama, Chile
                  </p>
                </td></tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
