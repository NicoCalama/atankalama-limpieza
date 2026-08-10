<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Support;

use Atankalama\Limpieza\Core\Url;
use Atankalama\Limpieza\Models\Usuario;

/**
 * TourResolver — arma el payload que el layout inyecta en #vg-root.
 * ---------------------------------------------------------------------------
 * Dado el PATH de la request + el usuario, devuelve el JSON del catálogo YA
 * FILTRADO por permiso y SIN datos del usuario (solo su id numérico, para
 * separar el "ya lo vi" en localStorage en un dispositivo compartido).
 *
 * Adaptación del kit a nuestro stack (dos únicos acoples con la app):
 *   1. self::MAP — de PATH de la app (sin BASE_PATH) a clave de pantalla.
 *      Nuestro router no tiene nombres de ruta, así que resolvemos por path
 *      con Url::rutaActual().
 *   2. userCan() — usa el RBAC dinámico: $usuario->tienePermiso($codigo).
 *
 * Lo que NUNCA viaja al navegador: nombre, RUT, correo ni roles del usuario. Los
 * recorridos cuyo permiso el usuario no tiene no se incluyen (filtrado en PHP).
 */
final class TourResolver
{
    /**
     * PATH de la app (SIN el prefijo BASE_PATH, tal como lo devuelve
     * Url::rutaActual()) => clave de pantalla en Tours::catalog().
     *
     * @var array<string, string>
     */
    private const MAP = [
        '/asignaciones' => 'asignaciones',
        '/auditoria'    => 'auditoria.bandeja',
        '/espacios'     => 'espacios',
        '/tickets'      => 'tickets',
        '/habitaciones' => 'habitaciones',
    ];

    /**
     * PATHS con parámetro: regex de path → clave de pantalla. Se prueban solo si
     * no hubo match exacto en MAP. (El router usa {id}; acá lo matcheamos con \d+.)
     *
     * @var array<string, string>
     */
    private const PATRONES = [
        '#^/habitaciones/\d+$#' => 'habitacion.detalle',
        '#^/auditoria/\d+$#'    => 'auditoria.detalle',
    ];

    /**
     * Punto de entrada para el layout. El layout ya tiene $usuario en scope.
     *
     * Uso en views/layout.php:
     *   $vgPayload = \Atankalama\Limpieza\Support\TourResolver::forCurrentRequest($usuario ?? null);
     */
    public static function forCurrentRequest(?Usuario $usuario): ?array
    {
        if ($usuario === null) {
            return null; // sin sesión no hay ayuda
        }

        $path = Url::rutaActual();
        // /home sirve un dashboard distinto por rol (ver views/home.php): hay que
        // resolver la clave de pantalla según permisos, no solo por el path.
        $pantalla = $path === '/home'
            ? self::resolveHome($usuario)
            : self::resolvePantalla($path);
        if ($pantalla === null) {
            return null; // esta pantalla no declara ayuda
        }

        $can = static fn (string $cap): bool => self::userCan($usuario, $cap);

        return self::payload($pantalla, $can, $usuario->id);
    }

    /**
     * /home sirve un dashboard distinto según permisos (espejo EXACTO de
     * views/home.php). Devolvemos la clave de pantalla del catálogo. Hoy solo
     * 'home.supervisora' tiene ayuda escrita; las demás claves resuelven a null
     * en payload() (sin botón «?») hasta que se les escriba su recorrido.
     */
    private static function resolveHome(Usuario $usuario): string
    {
        if ($usuario->tienePermiso('ajustes.acceder')) {
            return 'home.admin';
        }
        if ($usuario->tienePermiso('alertas.recibir_predictivas')
            && $usuario->tienePermiso('asignaciones.asignar_manual')) {
            return 'home.supervisora';
        }
        if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
            return 'home.recepcion';
        }
        return 'home.trabajador';
    }

    /** Chequeo de permiso vía RBAC dinámico. Nunca chequees roles: solo permisos. */
    private static function userCan(Usuario $usuario, string $capacidad): bool
    {
        return $usuario->tienePermiso($capacidad);
    }

    /** PATH de la app -> clave de pantalla del catálogo (o null si no hay ayuda). */
    public static function resolvePantalla(string $path): ?string
    {
        if (isset(self::MAP[$path])) {
            return self::MAP[$path];
        }
        foreach (self::PATRONES as $regex => $pantalla) {
            if (preg_match($regex, $path) === 1) {
                return $pantalla;
            }
        }
        return null;
    }

    /**
     * Construye el payload de una pantalla, filtrando por permiso.
     *
     * @param callable(string):bool $can
     * @return array<string, mixed>|null
     */
    public static function payload(string $pantalla, callable $can, int $userId): ?array
    {
        $catalogo = Tours::catalog();
        if (!isset($catalogo[$pantalla])) {
            return null;
        }
        $entrada = $catalogo[$pantalla];

        // Gate de pantalla: si declara `capacidad` y el usuario no la tiene, nada.
        if (!empty($entrada['capacidad']) && !$can($entrada['capacidad'])) {
            return null;
        }

        $recorridos = [];
        foreach ($entrada['recorridos'] as $r) {
            // Un recorrido puede exigir un permiso más fino que el de la pantalla.
            if (!empty($r['capacidad']) && !$can($r['capacidad'])) {
                continue;
            }
            $recorridos[] = self::limpiarRecorrido($r);
        }

        if ($recorridos === []) {
            return null;
        }

        return [
            'esquema'        => Tours::ESQUEMA,
            'pantalla'       => $pantalla,
            'nombrePantalla' => $entrada['nombre'] ?? null,
            'u'              => $userId,
            'recorridos'     => $recorridos,
        ];
    }

    /**
     * Deja solo los campos que el motor usa. Quita metadatos internos
     * (`capacidad`) para que no viajen al navegador.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function limpiarRecorrido(array $r): array
    {
        $limpio = [
            'id'       => $r['id'],
            'v'        => $r['v'] ?? 1,
            'titulo'   => $r['titulo'],
            'pregunta' => $r['pregunta'] ?? '',
            'pasos'    => array_map(static function (array $p): array {
                $paso = [
                    'sel'    => $p['sel'],
                    'titulo' => $p['titulo'],
                    'texto'  => $p['texto'],
                ];
                if (!empty($p['requiere'])) {
                    $paso['requiere'] = array_values($p['requiere']);
                }
                if (!empty($p['modo'])) {
                    $paso['modo'] = $p['modo'];
                }
                if (!empty($p['abrir'])) {
                    $paso['abrir'] = $p['abrir'];
                }
                return $paso;
            }, $r['pasos']),
        ];
        if (!empty($r['requiere'])) {
            $limpio['requiere'] = array_values($r['requiere']);
        }
        if (!empty($r['motivos'])) {
            $limpio['motivos'] = $r['motivos'];
        }
        return $limpio;
    }
}
