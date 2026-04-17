<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

final class TurnoService
{
    /** @return list<array<string, mixed>> */
    public function listar(bool $soloActivos = true): array
    {
        $sql = 'SELECT id, nombre, hora_inicio, hora_fin, activo FROM turnos';
        if ($soloActivos) {
            $sql .= ' WHERE activo = 1';
        }
        $sql .= ' ORDER BY hora_inicio';
        return Database::fetchAll($sql);
    }

    public function crear(string $nombre, string $horaInicio, string $horaFin, int $usuarioId): int
    {
        $nombre = trim($nombre);
        $this->validarNombre($nombre);
        $this->validarHora($horaInicio);
        $this->validarHora($horaFin);
        if ($this->horaAMinutos($horaFin) <= $this->horaAMinutos($horaInicio)) {
            throw new TurnoException('RANGO_INVALIDO', 'hora_fin debe ser mayor que hora_inicio.', 400);
        }
        $existente = Database::fetchOne('SELECT id FROM turnos WHERE nombre = ?', [$nombre]);
        if ($existente !== null) {
            throw new TurnoException('NOMBRE_DUPLICADO', "Ya existe un turno con nombre '{$nombre}'.", 409);
        }
        Database::execute(
            'INSERT INTO turnos (nombre, hora_inicio, hora_fin, activo) VALUES (?, ?, ?, 1)',
            [$nombre, $horaInicio, $horaFin]
        );
        $id = Database::lastInsertId();
        Logger::audit($usuarioId, 'turno.crear', 'turno', $id, [
            'nombre' => $nombre, 'hora_inicio' => $horaInicio, 'hora_fin' => $horaFin,
        ]);
        return $id;
    }

    /**
     * @param array{nombre?:string, hora_inicio?:string, hora_fin?:string, activo?:bool} $datos
     */
    public function actualizar(int $turnoId, array $datos, int $usuarioId): void
    {
        $existente = Database::fetchOne('SELECT * FROM turnos WHERE id = ?', [$turnoId]);
        if ($existente === null) {
            throw new TurnoException('TURNO_NO_ENCONTRADO', 'Turno no encontrado.', 404);
        }
        $sets = [];
        $params = [];
        if (isset($datos['nombre'])) {
            $nombre = trim((string) $datos['nombre']);
            $this->validarNombre($nombre);
            $dup = Database::fetchOne('SELECT id FROM turnos WHERE nombre = ? AND id <> ?', [$nombre, $turnoId]);
            if ($dup !== null) {
                throw new TurnoException('NOMBRE_DUPLICADO', "Ya existe un turno con nombre '{$nombre}'.", 409);
            }
            $sets[] = 'nombre = ?';
            $params[] = $nombre;
        }
        $hi = $datos['hora_inicio'] ?? $existente['hora_inicio'];
        $hf = $datos['hora_fin'] ?? $existente['hora_fin'];
        if (isset($datos['hora_inicio'])) {
            $this->validarHora((string) $datos['hora_inicio']);
            $sets[] = 'hora_inicio = ?';
            $params[] = (string) $datos['hora_inicio'];
        }
        if (isset($datos['hora_fin'])) {
            $this->validarHora((string) $datos['hora_fin']);
            $sets[] = 'hora_fin = ?';
            $params[] = (string) $datos['hora_fin'];
        }
        if ($this->horaAMinutos((string) $hf) <= $this->horaAMinutos((string) $hi)) {
            throw new TurnoException('RANGO_INVALIDO', 'hora_fin debe ser mayor que hora_inicio.', 400);
        }
        if (isset($datos['activo'])) {
            $sets[] = 'activo = ?';
            $params[] = $datos['activo'] ? 1 : 0;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $turnoId;
        Database::execute('UPDATE turnos SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        Logger::audit($usuarioId, 'turno.actualizar', 'turno', $turnoId, $datos);
    }

    public function asignarAUsuario(int $usuarioId, int $turnoId, string $fecha, int $asignadoPor): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new TurnoException('FECHA_INVALIDA', 'fecha debe ser YYYY-MM-DD.', 400);
        }
        $u = Database::fetchOne('SELECT id FROM usuarios WHERE id = ? AND activo = 1', [$usuarioId]);
        if ($u === null) {
            throw new TurnoException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado o inactivo.', 404);
        }
        $t = Database::fetchOne('SELECT id FROM turnos WHERE id = ? AND activo = 1', [$turnoId]);
        if ($t === null) {
            throw new TurnoException('TURNO_NO_ENCONTRADO', 'Turno no encontrado o inactivo.', 404);
        }
        $existente = Database::fetchOne(
            'SELECT id FROM usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
            [$usuarioId, $fecha]
        );
        if ($existente !== null) {
            Database::execute(
                'UPDATE usuarios_turnos SET turno_id = ? WHERE id = ?',
                [$turnoId, (int) $existente['id']]
            );
            $id = (int) $existente['id'];
        } else {
            Database::execute(
                'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
                [$usuarioId, $turnoId, $fecha]
            );
            $id = Database::lastInsertId();
        }
        Logger::audit($asignadoPor, 'turno.asignar_usuario', 'usuarios_turnos', $id, [
            'usuario_id' => $usuarioId, 'turno_id' => $turnoId, 'fecha' => $fecha,
        ]);
        return $id;
    }

    public function quitarDeUsuario(int $usuarioId, string $fecha, int $usuarioActuante): void
    {
        Database::execute(
            'DELETE FROM usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
            [$usuarioId, $fecha]
        );
        Logger::audit($usuarioActuante, 'turno.quitar_usuario', 'usuarios_turnos', null, [
            'usuario_id' => $usuarioId, 'fecha' => $fecha,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function turnosDelDia(string $fecha): array
    {
        return Database::fetchAll(
            'SELECT ut.id, ut.usuario_id, u.nombre AS usuario_nombre, u.hotel_default,
                    t.id AS turno_id, t.nombre AS turno_nombre, t.hora_inicio, t.hora_fin
               FROM usuarios_turnos ut
               JOIN usuarios u ON u.id = ut.usuario_id
               JOIN turnos t ON t.id = ut.turno_id
              WHERE ut.fecha = ?
              ORDER BY t.hora_inicio, u.nombre',
            [$fecha]
        );
    }

    private function validarNombre(string $nombre): void
    {
        if ($nombre === '' || strlen($nombre) > 50) {
            throw new TurnoException('NOMBRE_INVALIDO', 'Nombre debe tener entre 1 y 50 caracteres.', 400);
        }
    }

    private function validarHora(string $hora): void
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new TurnoException('HORA_INVALIDA', "Hora inválida: {$hora}. Formato HH:MM.", 400);
        }
    }

    private function horaAMinutos(string $hora): int
    {
        [$h, $m] = explode(':', $hora);
        return ((int) $h) * 60 + (int) $m;
    }
}
