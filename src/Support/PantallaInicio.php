<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Support;

use Atankalama\Limpieza\Models\Usuario;

/**
 * Qué pantalla es el «Inicio» de cada usuario, decidido SOLO por permisos (nunca por nombre de rol).
 * Única fuente de la cascada que antes repetían views/home.php y TourResolver::resolveHome().
 *
 * Quien inspecciona pero no administra ni supervisa (Recepción) no tiene un Inicio propio: su punto de
 * partida es Habitaciones, donde además hace la inspección pre-entrega desde la tarjeta de cada pieza.
 * Por eso a esos usuarios el menú no les muestra «Inicio» (pedido de Nicolás, 05/10/2026).
 */
final class PantallaInicio
{
    public const ADMIN = 'admin';
    public const SUPERVISORA = 'supervisora';
    public const HABITACIONES = 'habitaciones';
    public const TRABAJADOR = 'trabajador';

    public static function de(Usuario $usuario): string
    {
        if ($usuario->tienePermiso('ajustes.acceder')) {
            return self::ADMIN;
        }
        if ($usuario->tienePermiso('alertas.recibir_predictivas')
            && $usuario->tienePermiso('asignaciones.asignar_manual')) {
            return self::SUPERVISORA;
        }
        if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
            return self::HABITACIONES;
        }
        return self::TRABAJADOR;
    }

    /** ¿El Inicio es una pantalla propia? Si no, el menú no muestra «Inicio» (sería otro acceso a Habitaciones). */
    public static function tienePantallaPropia(Usuario $usuario): bool
    {
        return self::de($usuario) !== self::HABITACIONES;
    }
}
