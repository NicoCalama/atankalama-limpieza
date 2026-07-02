<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Asignacion;
use Atankalama\Limpieza\Models\Habitacion;

/**
 * Gestión de áreas comunes (espacios): piscina, pasillos, patio, bodega, etc. Se modelan como
 * "habitaciones especiales" (es_espacio_comun=1, sin cloudbeds_room_id) con checklist propio.
 * No pasan por Cloudbeds ni por auditoría (se auto-cierran al completar). Ver docs/areas-comunes.md
 */
final class EspacioService
{
    /** Tipo técnico que rellena el FK NOT NULL de las filas-espacio (el checklist real es por-espacio). */
    public const TIPO_NOMBRE = 'Área común';

    /** numero es VARCHAR(20) en MariaDB: el nombre del espacio debe entrar ahí. */
    private const NOMBRE_MAX = 20;

    public function __construct(
        private readonly AsignacionService $asignaciones = new AsignacionService(),
    ) {
    }

    /**
     * Id del tipo "Área común". Get-or-create: lo crea si no existe, para que el feature funcione
     * en prod sin depender de un re-seed de catálogos.
     */
    public function tipoAreaComunId(): int
    {
        $fila = Database::fetchOne('SELECT id FROM #__tipos_habitacion WHERE nombre = ?', [self::TIPO_NOMBRE]);
        if ($fila !== null) {
            return (int) $fila['id'];
        }
        Database::execute(
            'INSERT INTO #__tipos_habitacion (nombre, descripcion) VALUES (?, ?)',
            [self::TIPO_NOMBRE, 'Espacio que no es habitación de huésped (piscina, pasillo, patio…)']
        );
        return Database::lastInsertId();
    }

    /**
     * Lista los espacios activos (opcionalmente filtrados por hotel), con su hotel, estado y
     * cantidad de ítems del checklist.
     *
     * @param 'ambos'|'1_sur'|'inn'|null $hotel
     * @return list<array<string, mixed>>
     */
    public function listar(?string $hotel = 'ambos'): array
    {
        $where = ['h.es_espacio_comun = 1', 'h.activa = 1'];
        $params = [];
        if ($hotel !== null && $hotel !== 'ambos') {
            $where[] = 'ho.codigo = ?';
            $params[] = $hotel;
        }

        return Database::fetchAll(
            'SELECT h.id, h.numero, h.estado, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre,
                    (SELECT COUNT(*)
                       FROM #__checklists_template ct
                       JOIN #__items_checklist ic ON ic.template_id = ct.id
                      WHERE ct.habitacion_id = h.id AND ct.activo = 1 AND ic.activo = 1) AS items_count
               FROM #__habitaciones h
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY ho.codigo, h.numero',
            $params
        );
    }

    /**
     * Detalle de un espacio + los ítems de su checklist.
     *
     * @return array{espacio: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function obtenerDetalle(int $id): array
    {
        $espacio = Database::fetchOne(
            'SELECT h.*, ho.codigo AS hotel_codigo, ho.nombre AS hotel_nombre
               FROM #__habitaciones h
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE h.id = ? AND h.es_espacio_comun = 1',
            [$id]
        );
        if ($espacio === null) {
            throw new EspacioException('ESPACIO_NO_ENCONTRADO', 'Área común no encontrada.', 404);
        }

        $items = Database::fetchAll(
            'SELECT ic.id, ic.orden, ic.descripcion
               FROM #__items_checklist ic
               JOIN #__checklists_template ct ON ct.id = ic.template_id
              WHERE ct.habitacion_id = ? AND ct.activo = 1 AND ic.activo = 1
              ORDER BY ic.orden, ic.id',
            [$id]
        );

        return ['espacio' => $espacio, 'items' => $items];
    }

    /**
     * Crea un espacio con su checklist propio. Estado inicial 'aprobada' (idle / listo): recién al
     * "pedir limpieza" pasa a 'sucia' y entra en la cola de un trabajador.
     *
     * @param list<string> $items descripciones de los ítems del checklist (todos obligatorios)
     * @return int id del espacio creado
     */
    public function crear(string $nombre, string $hotelCodigo, array $items, ?int $actorId = null): int
    {
        $nombre = $this->validarNombre($nombre);
        $items = $this->normalizarItems($items);
        $hotelId = $this->hotelIdPorCodigo($hotelCodigo);
        $this->asegurarNombreLibre($hotelId, $nombre, null);

        $espacioId = Database::transaction(function () use ($nombre, $hotelId, $items): int {
            $tipoId = $this->tipoAreaComunId();

            Database::execute(
                'INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, cloudbeds_room_id, estado, es_espacio_comun)
                 VALUES (?, ?, ?, NULL, ?, 1)',
                [$hotelId, $nombre, $tipoId, Habitacion::ESTADO_APROBADA]
            );
            $espacioId = Database::lastInsertId();

            Database::execute(
                'INSERT INTO #__checklists_template (tipo_habitacion_id, habitacion_id, nombre) VALUES (?, ?, ?)',
                [$tipoId, $espacioId, 'Checklist — ' . $nombre]
            );
            $templateId = Database::lastInsertId();
            $this->insertarItems($templateId, $items);

            return $espacioId;
        });

        Logger::audit($actorId, 'espacio.crear', 'habitacion', $espacioId, [
            'nombre' => $nombre, 'hotel' => $hotelCodigo, 'items' => count($items),
        ]);

        return $espacioId;
    }

    /**
     * Edita un espacio: nombre y checklist. El checklist se reemplaza (los ítems viejos quedan
     * inactivos, se insertan los nuevos) para no romper el FK de ejecuciones históricas.
     *
     * @param list<string> $items
     */
    public function editar(int $id, string $nombre, array $items, ?int $actorId = null): void
    {
        $detalle = $this->obtenerDetalle($id);
        $espacio = $detalle['espacio'];
        $nombre = $this->validarNombre($nombre);
        $items = $this->normalizarItems($items);
        $this->asegurarNombreLibre((int) $espacio['hotel_id'], $nombre, $id);

        Database::transaction(function () use ($id, $nombre, $items): void {
            Database::execute(
                "UPDATE #__habitaciones SET numero = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
                [$nombre, $id]
            );

            $template = Database::fetchOne(
                'SELECT id FROM #__checklists_template WHERE habitacion_id = ? AND activo = 1 ORDER BY id LIMIT 1',
                [$id]
            );
            if ($template === null) {
                $tipoId = $this->tipoAreaComunId();
                Database::execute(
                    'INSERT INTO #__checklists_template (tipo_habitacion_id, habitacion_id, nombre) VALUES (?, ?, ?)',
                    [$tipoId, $id, 'Checklist — ' . $nombre]
                );
                $templateId = Database::lastInsertId();
            } else {
                $templateId = (int) $template['id'];
                // Desactiva los ítems actuales (no se borran: pueden estar referenciados por
                // ejecuciones históricas con FK RESTRICT). Los nuevos entran activos.
                Database::execute('UPDATE #__items_checklist SET activo = 0 WHERE template_id = ?', [$templateId]);
            }
            $this->insertarItems($templateId, $items);
        });

        Logger::audit($actorId, 'espacio.editar', 'habitacion', $id, [
            'nombre' => $nombre, 'items' => count($items),
        ]);
    }

    /**
     * Archiva un espacio (activa=0). No se borra para preservar el historial de limpiezas.
     */
    public function archivar(int $id, ?int $actorId = null): void
    {
        $this->obtenerDetalle($id); // valida que exista y sea espacio
        Database::execute(
            "UPDATE #__habitaciones SET activa = 0, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?",
            [$id]
        );
        Logger::audit($actorId, 'espacio.archivar', 'habitacion', $id, []);
    }

    /**
     * "Pedir limpieza": asigna el espacio a un trabajador para una fecha. Reusa asignarManual, que
     * resetea el estado terminal (aprobada) → sucia y crea la asignación (el trabajador lo verá en
     * su cola). Ver docs/areas-comunes.md §2.
     */
    public function pedirLimpieza(int $id, int $usuarioId, string $fecha, ?int $actorId = null): Asignacion
    {
        $this->obtenerDetalle($id); // valida que exista y sea espacio
        // El trabajador debe existir y estar activo (evita un 500 por FK si llega un id inválido).
        // No se exige turno a propósito: el coordinador puede pedir un servicio puntual a quien esté.
        $u = Database::fetchOne('SELECT id FROM #__usuarios WHERE id = ? AND activo = 1', [$usuarioId]);
        if ($u === null) {
            throw new EspacioException('TRABAJADOR_INVALIDO', 'El trabajador no existe o está inactivo.', 400);
        }
        return $this->asignaciones->asignarManual($id, $usuarioId, $fecha, $actorId);
    }

    /**
     * Trabajadores con turno para una fecha (candidatos para pedir la limpieza de un espacio).
     *
     * @return list<array<string, mixed>>
     */
    public function trabajadoresConTurno(string $fecha, ?string $hotelCodigo = null): array
    {
        $sql = 'SELECT u.id, u.nombre, u.rut, u.hotel_default
                  FROM #__usuarios u
                  JOIN #__usuarios_turnos ut ON ut.usuario_id = u.id
                 WHERE ut.fecha = ? AND u.activo = 1';
        $params = [$fecha];
        if ($hotelCodigo !== null && $hotelCodigo !== 'ambos') {
            $sql .= " AND (u.hotel_default = ? OR u.hotel_default = 'ambos')";
            $params[] = $hotelCodigo;
        }
        $sql .= ' ORDER BY u.nombre';
        return Database::fetchAll($sql, $params);
    }

    // ----- Helpers -----

    private function validarNombre(string $nombre): string
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw new EspacioException('NOMBRE_REQUERIDO', 'El nombre del área común es obligatorio.', 400);
        }
        if (mb_strlen($nombre) > self::NOMBRE_MAX) {
            throw new EspacioException(
                'NOMBRE_MUY_LARGO',
                'El nombre no puede superar los ' . self::NOMBRE_MAX . ' caracteres.',
                400
            );
        }
        return $nombre;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function normalizarItems(array $items): array
    {
        $limpios = [];
        foreach ($items as $item) {
            $desc = trim((string) $item);
            if ($desc !== '') {
                $limpios[] = $desc;
            }
        }
        if ($limpios === []) {
            throw new EspacioException('CHECKLIST_VACIO', 'El área común necesita al menos un ítem de checklist.', 400);
        }
        return $limpios;
    }

    /** @param list<string> $items */
    private function insertarItems(int $templateId, array $items): void
    {
        $orden = 1;
        foreach ($items as $desc) {
            Database::execute(
                'INSERT INTO #__items_checklist (template_id, orden, descripcion, obligatorio) VALUES (?, ?, ?, 1)',
                [$templateId, $orden, $desc]
            );
            $orden++;
        }
    }

    private function hotelIdPorCodigo(string $codigo): int
    {
        $fila = Database::fetchOne('SELECT id FROM #__hoteles WHERE codigo = ? AND activo = 1', [$codigo]);
        if ($fila === null) {
            throw new EspacioException('HOTEL_INVALIDO', 'Hotel no encontrado.', 400);
        }
        return (int) $fila['id'];
    }

    private function asegurarNombreLibre(int $hotelId, string $nombre, ?int $excluirId): void
    {
        $sql = 'SELECT id FROM #__habitaciones WHERE hotel_id = ? AND numero = ?';
        $params = [$hotelId, $nombre];
        if ($excluirId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excluirId;
        }
        if (Database::fetchOne($sql, $params) !== null) {
            throw new EspacioException('NOMBRE_DUPLICADO', 'Ya existe una habitación o área con ese nombre en el hotel.', 409);
        }
    }
}
