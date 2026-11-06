<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;

/**
 * A quién se le pueden asignar piezas un día dado: la lista de personas de Asignaciones (tablero y
 * clásico) y de «Pedir limpieza» de Áreas comunes. Una sola consulta para las dos pantallas, así la
 * regla no se separa (v7.14, pedido de Nicolás del 06/11/2026). Todo por permiso, nunca por rol:
 *
 *  - «aseo»: sin la marca asignaciones.excluir_auto (personal de aseo) y CON turno ese día, como
 *    siempre. Sin turno no sale: el turno es lo que dice que trabaja ese día.
 *  - «otros»: con la marca asignaciones.excluir_auto (no es de aseo: Apoyo, y Recepción o Mantención
 *    si limpian) que además puede limpiar (habitaciones.ver_asignadas_propias: ve su cola), TENGA O
 *    NO turno, porque a esta gente no se le carga turno. No sale quien reparte
 *    (asignaciones.asignar_manual: Supervisora, Admin): no limpia.
 *  - Además, quien YA tiene piezas asignadas ese día sale siempre, en su grupo, aunque no calce
 *    arriba (p. ej. el Admin con la habitación PRUEBA, v7.5): ninguna asignación queda escondida
 *    del tablero.
 *
 * Mismo filtro de hotel de siempre: hotel_default igual al elegido o 'ambos'.
 */
final class PersonalAsignableService
{
    public const GRUPO_ASEO = 'aseo';
    public const GRUPO_OTROS = 'otros';

    /**
     * @return list<array{id:int, nombre:string, rut:string, hotel_default:?string, grupo:string, con_turno:bool}>
     */
    public function listar(string $fecha, ?string $hotel = null): array
    {
        $turno = 'EXISTS (SELECT 1 FROM #__usuarios_turnos ut WHERE ut.usuario_id = u.id AND ut.fecha = ?)';

        // Los «?» se llenan en el orden del texto y cada helper agrega su permiso al armarse: se arma
        // de izquierda a derecha.
        $params = [$fecha];
        $noEsAseo = RbacService::sqlConPermiso('u.id', RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR, $params);
        $aseo = RbacService::sqlSinPermiso('u.id', RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR, $params) . " AND {$turno}";
        $params[] = $fecha;
        $otros = RbacService::sqlConPermiso('u.id', RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR, $params)
            . ' AND ' . RbacService::sqlConPermiso('u.id', 'habitaciones.ver_asignadas_propias', $params)
            . ' AND ' . RbacService::sqlSinPermiso('u.id', 'asignaciones.asignar_manual', $params);
        $conPiezas = 'EXISTS (SELECT 1 FROM #__asignaciones a WHERE a.usuario_id = u.id AND a.fecha = ? AND a.activa = 1)';
        $params[] = $fecha;

        $sql = "SELECT u.id, u.nombre, u.rut, u.hotel_default,
                       {$turno} AS con_turno,
                       {$noEsAseo} AS no_es_aseo
                  FROM #__usuarios u
                 WHERE u.activo = 1
                   AND (({$aseo}) OR ({$otros}) OR {$conPiezas})";
        if ($hotel !== null && $hotel !== 'ambos') {
            $sql .= " AND (u.hotel_default = ? OR u.hotel_default = 'ambos')";
            $params[] = $hotel;
        }
        $sql .= ' ORDER BY u.nombre';

        return array_map(static fn(array $f): array => [
            'id' => (int) $f['id'],
            'nombre' => (string) $f['nombre'],
            'rut' => (string) $f['rut'],
            'hotel_default' => $f['hotel_default'] !== null ? (string) $f['hotel_default'] : null,
            // MariaDB devuelve los EXISTS como string ("0"/"1"): cast explícito.
            'grupo' => ((int) $f['no_es_aseo']) === 1 ? self::GRUPO_OTROS : self::GRUPO_ASEO,
            'con_turno' => ((int) $f['con_turno']) === 1,
        ], Database::fetchAll($sql, $params));
    }
}
