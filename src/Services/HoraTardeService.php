<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * La «hora de la tarde» (v7.23, reunión del 08/11/2026): desde esa hora (Chile) las piezas Día/Noche vigentes
 * que ya quedaron aprobadas vuelven a sucias para su segundo aseo. Antes era 16:00 fijo
 * (HabitacionService::HORA_BARRIDO_NOCHEROS). Se cambia en Ajustes → Hora de la tarde, solo Admin
 * (habitaciones.configurar_hora_tarde; decisión de Nicolás, 10/11/2026). Desde la v7.24 y la v7.25 también decide
 * el checklist premarcado y cuándo se pueden asignar las piezas Noche.
 *
 * Vive en alertas_config (clave hora_tarde, valor «HH:MM»); sin fila, 16:00. No la usan el Registro de días
 * pasados ni Reportes, que tienen su propio corte para que el pasado no cambie si se mueve la hora.
 */
final class HoraTardeService
{
    public const CLAVE = 'hora_tarde';
    public const POR_DEFECTO = '16:00';
    public const PERMISO = 'habitaciones.configurar_hora_tarde';

    /** Rango permitido, en cuartos de hora: una «tarde» razonable para un hotel. */
    public const MINIMA = '12:00';
    public const MAXIMA = '20:00';

    /** «HH:MM» vigente. Si la fila falta o quedó con un valor raro, la de siempre. */
    public function hora(): string
    {
        $valor = Database::fetchColumn('SELECT valor FROM #__alertas_config WHERE clave = ?', [self::CLAVE]);
        if (!is_string($valor) || !self::esValida($valor)) {
            return self::POR_DEFECTO;
        }
        return $valor;
    }

    /** Guarda una hora nueva («HH:MM», de 12:00 a 20:00, en cuartos de hora) y la devuelve. */
    public function guardar(string $hora, int $usuarioId): string
    {
        $hora = trim($hora);
        if (!self::esValida($hora)) {
            throw new HabitacionException(
                'HORA_INVALIDA',
                'Elige una hora entre las ' . self::MINIMA . ' y las ' . self::MAXIMA . ', en cuartos de hora (por ejemplo 16:00 o 16:30).',
                400
            );
        }
        $anterior = $this->hora();
        Database::execute(
            'INSERT INTO #__alertas_config (clave, valor, descripcion, updated_at, updated_by) VALUES (?, ?, ?, ?, ?) '
            . Database::onConflictUpdate(['clave'], ['valor', 'updated_at', 'updated_by']),
            [
                self::CLAVE,
                $hora,
                'Hora de la tarde (HH:MM): desde ahí las piezas Día/Noche vuelven a sucias para su segundo aseo',
                Database::now(),
                $usuarioId,
            ]
        );
        Logger::audit($usuarioId, 'habitaciones.hora_tarde', 'alertas_config', null, ['antes' => $anterior, 'ahora' => $hora]);
        return $hora;
    }

    private static function esValida(string $hora): bool
    {
        if (preg_match('/^([01]\d|2[0-3]):(00|15|30|45)$/', $hora) !== 1) {
            return false;
        }
        return $hora >= self::MINIMA && $hora <= self::MAXIMA;
    }
}
