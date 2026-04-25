<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

final class TurnosImportService
{
    public function parsearCsv(string $contenido): array
    {
        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido));
        $encabezado = null;
        $filas = [];

        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            $campos = str_getcsv($linea, ',');

            if ($encabezado === null) {
                $encabezado = array_map('trim', $campos);
                continue;
            }

            while (count($campos) < count($encabezado)) {
                $campos[] = '';
            }

            $filas[] = array_combine($encabezado, array_map('trim', $campos));
        }

        return $filas;
    }

    public function preview(array $filas): array
    {
        $rutsMapeados       = $this->mapearRuts();
        $turnosExistentes   = $this->indexarTurnosPorHoras();

        $fechas              = [];
        $rutsNoEncontrados   = [];
        $turnosNuevos        = [];
        $filasImportar       = [];
        $totalPermisos       = 0;
        $totalTurnosFilas    = 0;
        $yaExistentes        = 0;

        foreach ($filas as $fila) {
            $tipo = strtoupper(trim($fila['TIPO'] ?? ''));

            if ($tipo === 'PERMISO') {
                $totalPermisos++;
                continue;
            }

            if ($tipo !== 'TURNO') continue;

            $horaInicio = trim($fila['HORA INICIO'] ?? '');
            $horaFin    = trim($fila['HORA TERMINO'] ?? '');

            if ($horaInicio === '' || $horaFin === '') continue;

            $totalTurnosFilas++;

            $rut         = $this->normalizarRut(trim($fila['DNI'] ?? ''));
            $fecha        = trim($fila['FECHA'] ?? '');
            $nombreTurno = trim($fila['NOMBRE TURNO'] ?? '');

            if (!isset($rutsMapeados[$rut])) {
                $rutsNoEncontrados[$rut] = trim(($fila['NOMBRE'] ?? '') . ' ' . ($fila['APELLIDOS'] ?? ''));
                continue;
            }

            if ($fecha !== '') $fechas[] = $fecha;

            $usuarioId = $rutsMapeados[$rut];
            $turnoKey  = $horaInicio . '-' . $horaFin;

            if (!isset($turnosExistentes[$turnoKey]) && !isset($turnosNuevos[$turnoKey])) {
                $turnosNuevos[$turnoKey] = [
                    'nombre'           => $nombreTurno,
                    'hora_inicio'      => $horaInicio,
                    'hora_fin'         => $horaFin,
                    'cruza_medianoche' => $horaFin < $horaInicio,
                ];
            }

            $existente = Database::fetchOne(
                'SELECT id FROM usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
                [$usuarioId, $fecha]
            );

            if ($existente) $yaExistentes++;

            $filasImportar[] = [
                'usuario_id'   => $usuarioId,
                'rut'          => $rut,
                'fecha'        => $fecha,
                'turno_nombre' => $nombreTurno,
                'hora_inicio'  => $horaInicio,
                'hora_fin'     => $horaFin,
                'ya_existe'    => $existente !== null,
            ];
        }

        // Display list of matched users
        $idsEncontrados = array_unique(array_column($filasImportar, 'usuario_id'));
        $usuariosDisplay = [];
        if ($idsEncontrados) {
            $ph   = implode(',', array_fill(0, count($idsEncontrados), '?'));
            $rows = Database::fetchAll("SELECT id, nombre, rut FROM usuarios WHERE id IN ($ph)", $idsEncontrados);
            foreach ($rows as $row) {
                $nTurnos = count(array_filter($filasImportar, fn($f) => $f['usuario_id'] === (int) $row['id']));
                $usuariosDisplay[] = ['id' => (int) $row['id'], 'nombre' => $row['nombre'], 'rut' => $row['rut'], 'turnos' => $nTurnos];
            }
        }

        return [
            'rango_fechas'            => ['desde' => $fechas ? min($fechas) : null, 'hasta' => $fechas ? max($fechas) : null],
            'total_filas'             => count($filas),
            'total_permisos'          => $totalPermisos,
            'total_turnos_filas'      => $totalTurnosFilas,
            'a_importar'              => count(array_filter($filasImportar, fn($f) => !$f['ya_existe'])),
            'ya_existentes'           => $yaExistentes,
            'usuarios_encontrados'    => $usuariosDisplay,
            'usuarios_no_encontrados' => array_values(array_map(
                fn($rut, $nombre) => ['rut' => $rut, 'nombre' => $nombre],
                array_keys($rutsNoEncontrados),
                $rutsNoEncontrados
            )),
            'turnos_nuevos'   => array_values($turnosNuevos),
            'filas_importar'  => $filasImportar,
        ];
    }

    public function importar(array $filasImportar, bool $reemplazar, int $actorId): array
    {
        $importados = 0;
        $omitidos   = 0;
        $errores    = [];

        foreach ($filasImportar as $fila) {
            if ($fila['ya_existe'] && !$reemplazar) {
                $omitidos++;
                continue;
            }

            try {
                $turnoId = $this->encontrarOCrearTurno($fila['turno_nombre'], $fila['hora_inicio'], $fila['hora_fin']);

                if ($reemplazar) {
                    Database::execute(
                        'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha)
                         VALUES (?, ?, ?)
                         ON CONFLICT(usuario_id, fecha) DO UPDATE SET turno_id = excluded.turno_id',
                        [$fila['usuario_id'], $turnoId, $fila['fecha']]
                    );
                } else {
                    Database::execute(
                        'INSERT OR IGNORE INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
                        [$fila['usuario_id'], $turnoId, $fila['fecha']]
                    );
                }

                $importados++;
            } catch (\Throwable $e) {
                $errores[] = "RUT {$fila['rut']} {$fila['fecha']}: " . $e->getMessage();
            }
        }

        Logger::audit($actorId, 'turnos.importar_breik', 'turnos', null, [
            'importados' => $importados, 'omitidos' => $omitidos, 'errores' => count($errores),
        ]);

        return ['importados' => $importados, 'omitidos' => $omitidos, 'errores' => $errores];
    }

    public function normalizarRut(string $rut): string
    {
        return strtoupper(str_replace('.', '', $rut));
    }

    private function mapearRuts(): array
    {
        $filas = Database::fetchAll('SELECT id, rut FROM usuarios WHERE activo = 1');
        $mapa  = [];
        foreach ($filas as $fila) {
            $mapa[strtoupper($fila['rut'])] = (int) $fila['id'];
        }
        return $mapa;
    }

    private function indexarTurnosPorHoras(): array
    {
        $turnos = Database::fetchAll('SELECT hora_inicio, hora_fin FROM turnos');
        $mapa   = [];
        foreach ($turnos as $t) {
            $mapa[$t['hora_inicio'] . '-' . $t['hora_fin']] = true;
        }
        return $mapa;
    }

    private function encontrarOCrearTurno(string $nombre, string $horaInicio, string $horaFin): int
    {
        $turno = Database::fetchOne(
            'SELECT id FROM turnos WHERE hora_inicio = ? AND hora_fin = ?',
            [$horaInicio, $horaFin]
        );
        if ($turno) return (int) $turno['id'];

        // Avoid UNIQUE conflict on nombre if same name, different hours
        $existe = Database::fetchOne('SELECT id FROM turnos WHERE nombre = ?', [$nombre]);
        $nombreFinal = $existe ? "$nombre ({$horaInicio}-{$horaFin})" : $nombre;

        Database::execute(
            'INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES (?, ?, ?)',
            [$nombreFinal, $horaInicio, $horaFin]
        );

        return (int) Database::lastInsertId();
    }
}
