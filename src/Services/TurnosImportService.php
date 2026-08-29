<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\XlsxReader;

final class TurnosImportService
{
    /**
     * Códigos que no son turnos de trabajo (ausencias) y se ignoran al leer el
     * calendario semanal .xlsx. Case-insensitive.
     */
    private const CODIGOS_AUSENCIA = ['LI', 'CG', 'VC', 'MAT', 'N/A'];

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

    /**
     * Lee el calendario semanal .xlsx — así llegan los archivos de Breik: DNI, Nombre
     * + una columna por fecha, con el nombre de un turno YA EXISTENTE en #__turnos
     * (o un código de ausencia, filtrado acá y omitido).
     *
     * @return list<array{rut:string, nombre:string, fecha:string, turno_nombre:string}>
     */
    public function parsearXlsxCalendario(string $rutaArchivo): array
    {
        $todas = XlsxReader::leerFilas($rutaArchivo);
        if ($todas === []) {
            return [];
        }

        // Encabezado: A=DNI, B=Nombre, resto = fechas por columna.
        $fechasPorColumna = [];
        foreach ($todas[0] as $columna => $valor) {
            if (in_array($columna, ['A', 'B'], true) || $valor === '') {
                continue;
            }
            $fechasPorColumna[$columna] = $valor;
        }

        $filas = [];
        foreach (array_slice($todas, 1) as $celdas) {
            $rut = $celdas['A'] ?? '';
            $nombre = $celdas['B'] ?? '';
            if ($rut === '' && $nombre === '') {
                continue;
            }
            foreach ($fechasPorColumna as $columna => $fecha) {
                $codigo = $celdas[$columna] ?? '';
                if ($codigo === '' || in_array(strtoupper($codigo), self::CODIGOS_AUSENCIA, true)) {
                    continue;
                }
                $filas[] = ['rut' => $rut, 'nombre' => $nombre, 'fecha' => $fecha, 'turno_nombre' => $codigo];
            }
        }

        return $filas;
    }

    /**
     * Preview para el calendario .xlsx. A diferencia de preview() (CSV de Breik, que
     * trae horas y crea turnos nuevos si no existen), acá el archivo NO trae horas —
     * solo el nombre del turno. Si ese nombre no matchea ningún #__turnos.nombre
     * existente, la fila se reporta en 'turnos_no_encontrados' y se omite: no hay
     * datos para poder crear el turno.
     *
     * @param list<array{rut:string, nombre:string, fecha:string, turno_nombre:string}> $filas
     */
    public function previewCalendario(array $filas): array
    {
        $rutsMapeados = $this->mapearRuts();
        $turnosPorNombre = $this->indexarTurnosPorNombre();

        $fechas = [];
        $rutsNoEncontrados = [];
        $turnosNoEncontrados = [];
        $filasImportar = [];
        $yaExistentes = 0;

        foreach ($filas as $fila) {
            $rut = $this->normalizarRut(trim($fila['rut']));
            $fecha = trim($fila['fecha']);
            $turnoNombre = trim($fila['turno_nombre']);

            if (!isset($rutsMapeados[$rut])) {
                $rutsNoEncontrados[$rut] = trim($fila['nombre']);
                continue;
            }

            if ($fecha !== '') $fechas[] = $fecha;

            $turno = $turnosPorNombre[$turnoNombre] ?? null;
            if ($turno === null) {
                $turnosNoEncontrados[$turnoNombre] = true;
                continue;
            }

            $usuarioId = $rutsMapeados[$rut];
            $existente = Database::fetchOne(
                'SELECT id FROM #__usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
                [$usuarioId, $fecha]
            );
            if ($existente) $yaExistentes++;

            $filasImportar[] = [
                'usuario_id'   => $usuarioId,
                'rut'          => $rut,
                'fecha'        => $fecha,
                'turno_nombre' => $turno['nombre'],
                'hora_inicio'  => $turno['hora_inicio'],
                'hora_fin'     => $turno['hora_fin'],
                'turno_id'     => (int) $turno['id'],
                'ya_existe'    => $existente !== null,
            ];
        }

        $idsEncontrados = array_unique(array_column($filasImportar, 'usuario_id'));
        $usuariosDisplay = [];
        if ($idsEncontrados) {
            $ph   = implode(',', array_fill(0, count($idsEncontrados), '?'));
            $rows = Database::fetchAll("SELECT id, nombre, rut FROM #__usuarios WHERE id IN ($ph)", $idsEncontrados);
            foreach ($rows as $row) {
                $nTurnos = count(array_filter($filasImportar, fn($f) => $f['usuario_id'] === (int) $row['id']));
                $usuariosDisplay[] = ['id' => (int) $row['id'], 'nombre' => $row['nombre'], 'rut' => $row['rut'], 'turnos' => $nTurnos];
            }
        }

        return [
            'rango_fechas'            => ['desde' => $fechas ? min($fechas) : null, 'hasta' => $fechas ? max($fechas) : null],
            'total_filas'             => count($filas),
            'total_permisos'          => 0, // los códigos de ausencia ya se filtraron al parsear
            'total_turnos_filas'      => count($filas),
            'a_importar'              => count(array_filter($filasImportar, fn($f) => !$f['ya_existe'])),
            'ya_existentes'           => $yaExistentes,
            'usuarios_encontrados'    => $usuariosDisplay,
            'usuarios_no_encontrados' => array_map(
                fn($rut, $nombre) => ['rut' => $rut, 'nombre' => $nombre],
                array_keys($rutsNoEncontrados),
                $rutsNoEncontrados
            ),
            'turnos_nuevos'          => [], // este formato no crea turnos nuevos, solo matchea por nombre
            'turnos_no_encontrados'  => array_keys($turnosNoEncontrados),
            'filas_importar'         => $filasImportar,
        ];
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
                'SELECT id FROM #__usuarios_turnos WHERE usuario_id = ? AND fecha = ?',
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
            $rows = Database::fetchAll("SELECT id, nombre, rut FROM #__usuarios WHERE id IN ($ph)", $idsEncontrados);
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
            'usuarios_no_encontrados' => array_map(
                fn($rut, $nombre) => ['rut' => $rut, 'nombre' => $nombre],
                array_keys($rutsNoEncontrados),
                $rutsNoEncontrados
            ),
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
                // El modo calendario ya resolvió el turno por nombre en previewCalendario()
                // (ese formato no trae horas para poder crear uno nuevo); el CSV de Breik
                // sigue matcheando/creando por horas, como siempre.
                $turnoId = $fila['turno_id'] ?? $this->encontrarOCrearTurno($fila['turno_nombre'], $fila['hora_inicio'], $fila['hora_fin']);

                if ($reemplazar) {
                    $onConflict = Database::onConflictUpdate(['usuario_id', 'fecha'], ['turno_id']);
                    Database::execute(
                        "INSERT INTO #__usuarios_turnos (usuario_id, turno_id, fecha)
                         VALUES (?, ?, ?)
                         {$onConflict}",
                        [$fila['usuario_id'], $turnoId, $fila['fecha']]
                    );
                } else {
                    Database::execute(
                        'INSERT OR IGNORE INTO #__usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
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
        $filas = Database::fetchAll('SELECT id, rut FROM #__usuarios WHERE activo = 1');
        $mapa  = [];
        foreach ($filas as $fila) {
            $mapa[strtoupper($fila['rut'])] = (int) $fila['id'];
        }
        return $mapa;
    }

    private function indexarTurnosPorHoras(): array
    {
        $turnos = Database::fetchAll('SELECT hora_inicio, hora_fin FROM #__turnos');
        $mapa   = [];
        foreach ($turnos as $t) {
            $mapa[$t['hora_inicio'] . '-' . $t['hora_fin']] = true;
        }
        return $mapa;
    }

    /** @return array<string, array{id:int, nombre:string, hora_inicio:string, hora_fin:string}> nombre => turno */
    private function indexarTurnosPorNombre(): array
    {
        $turnos = Database::fetchAll('SELECT id, nombre, hora_inicio, hora_fin FROM #__turnos');
        $mapa   = [];
        foreach ($turnos as $t) {
            $mapa[$t['nombre']] = $t;
        }
        return $mapa;
    }

    private function encontrarOCrearTurno(string $nombre, string $horaInicio, string $horaFin): int
    {
        $turno = Database::fetchOne(
            'SELECT id FROM #__turnos WHERE hora_inicio = ? AND hora_fin = ?',
            [$horaInicio, $horaFin]
        );
        if ($turno) return (int) $turno['id'];

        // Avoid UNIQUE conflict on nombre if same name, different hours
        $existe = Database::fetchOne('SELECT id FROM #__turnos WHERE nombre = ?', [$nombre]);
        $nombreFinal = $existe ? "$nombre ({$horaInicio}-{$horaFin})" : $nombre;

        Database::execute(
            'INSERT INTO #__turnos (nombre, hora_inicio, hora_fin) VALUES (?, ?, ?)',
            [$nombreFinal, $horaInicio, $horaFin]
        );

        return (int) Database::lastInsertId();
    }
}
