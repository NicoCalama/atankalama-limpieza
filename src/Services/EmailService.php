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

    /**
     * Reporte diario a un administrador: habitaciones limpiadas y no auditadas al
     * corte de las 23:50, separadas por turno. Ver ReportesService::auditoriasPendientes().
     * Si el email no está configurado o el destinatario no tiene email, se omite
     * silenciosamente (mismo criterio que enviarPasswordTemporal) — el cron que llama
     * a este método es quien decide si eso es un fallo a loguear.
     *
     * @param array<string, array{total:int, pendientes:list<array<string,mixed>>}> $turnos
     */
    public function enviarReporteAuditoriasPendientes(
        string $destinatario,
        string $nombre,
        string $fecha,
        array $turnos
    ): bool {
        if (!$this->habilitado || $destinatario === '') return false;

        $totalPendientes = count($turnos['mañana']['pendientes'] ?? [])
            + count($turnos['tarde']['pendientes'] ?? []);
        $fechaLabel = date('d/m/Y', strtotime($fecha));
        $asunto = "Auditorías pendientes ({$totalPendientes}) — {$fechaLabel}";

        $cuerpo = $this->plantillaReporteAuditoriasPendientes($nombre, $fecha, $turnos);

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

    /**
     * @param array<string, array{total:int, pendientes:list<array<string,mixed>>}> $turnos
     */
    private function plantillaReporteAuditoriasPendientes(string $nombre, string $fecha, array $turnos): string
    {
        $appNombre  = htmlspecialchars(Config::get('APP_NAME', 'Atankalama Limpieza'));
        $appUrl     = htmlspecialchars(rtrim(Config::get('APP_URL', 'http://localhost:8000'), '/'));
        $nombreHtml = htmlspecialchars($nombre);
        $fechaHtml  = htmlspecialchars(date('d/m/Y', strtotime($fecha)));

        $pendientesManana = $turnos['mañana']['pendientes'] ?? [];
        $pendientesTarde  = $turnos['tarde']['pendientes'] ?? [];
        $totalPendientes  = count($pendientesManana) + count($pendientesTarde);

        // Todo verde si no hay nada pendiente; rojo si queda algo por auditar —
        // el color de la cabecera y de los chips resume el estado del día de un vistazo.
        $colorEstado = $totalPendientes > 0 ? '#dc2626' : '#059669';

        $chip = function (string $numero, string $etiqueta, bool $alerta) {
            $color = $alerta ? '#dc2626' : '#059669';
            $tint  = $alerta ? '#fef2f2' : '#ecfdf5';
            $borde = $alerta ? '#fecaca' : '#a7f3d0';
            return '<td width="33%" style="padding:4px;">'
                . '<table width="100%" style="border-collapse:collapse;background:' . $tint . ';border:1px solid ' . $borde . ';border-radius:10px;">'
                . '<tr><td style="padding:12px 8px;text-align:center;">'
                . '<p style="margin:0;color:' . $color . ';font-size:24px;font-weight:800;line-height:1;">' . $numero . '</p>'
                . '<p style="margin:4px 0 0;color:#6b7280;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;">' . htmlspecialchars($etiqueta) . '</p>'
                . '</td></tr></table>'
                . '</td>';
        };
        $chips = '<table width="100%" style="border-collapse:collapse;margin:0 0 20px;"><tr>'
            . $chip((string) $totalPendientes, 'Total pendientes', $totalPendientes > 0)
            . $chip((string) count($pendientesManana), 'Turno mañana', count($pendientesManana) > 0)
            . $chip((string) count($pendientesTarde), 'Turno tarde', count($pendientesTarde) > 0)
            . '</tr></table>';

        $bloques = '';
        // El nombre del turno solo no dice cuándo empieza/termina — la hora de corte
        // (18:00) es la regla de negocio que separa mañana de tarde, hay que mostrarla
        // (mismo criterio que turnoLabel() en reportes.php).
        $titulosTurno = [
            'mañana' => 'Turno mañana (antes de las 18:00)',
            'tarde'  => 'Turno tarde (18:00 en adelante)',
        ];
        foreach ($titulosTurno as $clave => $titulo) {
            $datos      = $turnos[$clave] ?? ['total' => 0, 'pendientes' => []];
            $pendientes = $datos['pendientes'];

            $bloques .= '<tr><td style="padding:20px 0 10px;border-top:1px solid #f3f4f6;">'
                . '<table width="100%"><tr>'
                . '<td><p style="margin:0;color:#111827;font-size:15px;font-weight:700;">' . htmlspecialchars($titulo) . '</p></td>'
                . '<td align="right"><p style="margin:0;color:#9ca3af;font-size:12px;">' . (int) $datos['total'] . ' limpiadas</p></td>'
                . '</tr></table>'
                . '</td></tr>';

            if ($pendientes === []) {
                $bloques .= '<tr><td style="padding:0 0 4px;">'
                    . '<table width="100%" style="border-collapse:collapse;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">'
                    . '<tr><td style="padding:10px 14px;color:#047857;font-size:13px;font-weight:600;">✓ Todo auditado a tiempo</td></tr>'
                    . '</table>'
                    . '</td></tr>';
                continue;
            }

            $filasHtml = '';
            foreach ($pendientes as $i => $p) {
                $hotelLabel   = $this->hotelLabel((string) $p['hotel_codigo']);
                $esSinAuditar = $p['estado_auditoria'] === 'sin_auditar';
                $estado       = $esSinAuditar ? 'Sin auditar' : 'Auditada fuera de plazo';
                $colorPill    = $esSinAuditar ? '#dc2626' : '#d97706';
                $tintPill     = $esSinAuditar ? '#fee2e2' : '#fef3c7';
                $esNochero    = (bool) ($p['es_nochero'] ?? false);
                $fondoFila    = $i % 2 === 0 ? '#ffffff' : '#fafafa';

                $nocheroHtml = $esNochero
                    ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#ede9fe;color:#6d28d9;font-size:11px;font-weight:700;">Sí</span>'
                    : '<span style="color:#d1d5db;">—</span>';
                $estadoHtml = '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . $tintPill . ';color:' . $colorPill . ';font-size:12px;font-weight:700;white-space:nowrap;">' . $estado . '</span>';

                $filasHtml .= '<tr style="background:' . $fondoFila . ';">'
                    . '<td style="padding:8px;border-bottom:1px solid #f3f4f6;color:#6b7280;font-size:13px;">' . htmlspecialchars($hotelLabel) . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #f3f4f6;color:#111827;font-size:14px;font-weight:700;">' . htmlspecialchars((string) $p['numero']) . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center;">' . $nocheroHtml . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #f3f4f6;color:#374151;font-size:13px;text-align:right;font-variant-numeric:tabular-nums;">' . htmlspecialchars((string) $p['hora_termino']) . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #f3f4f6;">' . $estadoHtml . '</td>'
                    . '</tr>';
            }

            $bloques .= '<tr><td style="padding:0 0 4px;">'
                . '<table width="100%" style="border-collapse:collapse;border:1px solid #f3f4f6;border-radius:8px;overflow:hidden;">'
                . '<tr style="background:#f9fafb;">'
                . '<th style="padding:8px;text-align:left;color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:.3px;">Hotel</th>'
                . '<th style="padding:8px;text-align:left;color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:.3px;">Habitación</th>'
                . '<th style="padding:8px;text-align:center;color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:.3px;">Nochero</th>'
                . '<th style="padding:8px;text-align:right;color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:.3px;">Hora término</th>'
                . '<th style="padding:8px;text-align:left;color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:.3px;">Estado</th>'
                . '</tr>'
                . $filasHtml
                . '</table>'
                . '</td></tr>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="margin:0;padding:0;background:#f3f4f6;font-family:Inter,system-ui,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
            <tr><td align="center">
              <table width="100%" style="max-width:560px;">

                <!-- Cabecera -->
                <tr><td style="background:{$colorEstado};border-radius:12px 12px 0 0;padding:28px 32px;">
                  <table width="100%"><tr>
                    <td>
                      <p style="margin:0;color:#fff;font-size:20px;font-weight:700;">{$appNombre}</p>
                      <p style="margin:4px 0 0;color:rgba(255,255,255,.85);font-size:13px;">Auditorías pendientes al corte de las 23:50 — {$fechaHtml}</p>
                    </td>
                    <td align="right" valign="top">
                      <span style="display:inline-block;background:rgba(255,255,255,.2);color:#fff;font-size:12px;font-weight:700;padding:4px 12px;border-radius:999px;">
                        {$totalPendientes} pendientes
                      </span>
                    </td>
                  </tr></table>
                </td></tr>

                <!-- Cuerpo -->
                <tr><td style="background:#fff;padding:24px 32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
                  <p style="margin:0 0 4px;color:#374151;font-size:15px;">Hola, <strong>{$nombreHtml}</strong></p>
                  <p style="margin:0 0 4px;color:#6b7280;font-size:14px;">
                    Al cierre del día quedaron <strong style="color:{$colorEstado};">{$totalPendientes}</strong> habitaciones limpiadas sin auditar a tiempo.
                  </p>
                  <p style="margin:0 0 16px;color:#9ca3af;font-size:12px;">
                    Sin auditar a tiempo = sin auditoría registrada antes de las 23:50 de la fecha del reporte.
                  </p>

                  {$chips}

                  <table width="100%" style="border-collapse:collapse;">
                    {$bloques}
                  </table>

                  <table width="100%"><tr><td align="center" style="padding-top:20px;">
                    <a href="{$appUrl}/reportes"
                       style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 32px;border-radius:8px;">
                      Ver reporte completo
                    </a>
                  </td></tr></table>
                </td></tr>

                <!-- Pie -->
                <tr><td style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;">
                  <p style="margin:0;color:#9ca3af;font-size:12px;text-align:center;">
                    Reporte automático diario · © Atankalama Corp — Calama, Chile
                  </p>
                </td></tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    private function hotelLabel(string $codigo): string
    {
        return match ($codigo) {
            '1_sur' => 'Atankalama',
            'inn'   => 'Atankalama INN',
            default => $codigo,
        };
    }
}
