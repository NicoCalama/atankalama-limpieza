<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Edificio;
use Exception;

class EdificioException extends Exception
{
    public function __construct(public readonly string $codigo, string $mensaje, public readonly int $httpStatus = 400)
    {
        parent::__construct($mensaje);
    }
}

class EdificioService
{
    public function obtener(int $id): ?Edificio
    {
        $fila = Database::fetchOne('SELECT * FROM #__edificios WHERE id = ?', [$id]);
        return $fila ? Edificio::desdeFila($fila) : null;
    }

    /**
     * @return Edificio[]
     */
    public function listar(): array
    {
        $filas = Database::fetchAll('SELECT * FROM #__edificios ORDER BY hotel_id, nombre');
        return array_map(fn($f) => Edificio::desdeFila($f), $filas);
    }

    public function crear(int $hotelId, string $nombre, int $pisos, ?int $usuarioId = null): Edificio
    {
        if (trim($nombre) === '') {
            throw new EdificioException('NOMBRE_VACIO', 'El nombre del edificio es obligatorio.');
        }

        Database::execute(
            'INSERT INTO #__edificios (hotel_id, nombre, pisos, estado, orden) VALUES (?, ?, ?, ?, ?)',
            [$hotelId, trim($nombre), $pisos, 'operativo', 0]
        );
        $id = Database::lastInsertId();

        Logger::info('edificios', 'edificio_creado', [
            'id' => $id,
            'hotel_id' => $hotelId,
            'nombre' => trim($nombre),
            'pisos' => $pisos
        ], $usuarioId);

        return $this->obtener((int)$id);
    }

    public function actualizar(int $id, int $hotelId, string $nombre, int $pisos, ?int $usuarioId = null): Edificio
    {
        $edificio = $this->obtener($id);
        if (!$edificio) {
            throw new EdificioException('EDIFICIO_NO_ENCONTRADO', 'Edificio no encontrado.', 404);
        }
        if (trim($nombre) === '') {
            throw new EdificioException('NOMBRE_VACIO', 'El nombre del edificio es obligatorio.');
        }

        Database::execute(
            'UPDATE #__edificios SET hotel_id = ?, nombre = ?, pisos = ? WHERE id = ?',
            [$hotelId, trim($nombre), $pisos, $id]
        );

        Logger::info('edificios', 'edificio_actualizado', [
            'id' => $id,
            'hotel_id' => $hotelId,
            'nombre' => trim($nombre),
            'pisos' => $pisos
        ], $usuarioId);

        return $this->obtener($id);
    }

    public function eliminar(int $id, ?int $usuarioId = null): void
    {
        $edificio = $this->obtener($id);
        if (!$edificio) {
            throw new EdificioException('EDIFICIO_NO_ENCONTRADO', 'Edificio no encontrado.', 404);
        }

        // Check if there are rooms using it
        $uso = Database::fetchOne('SELECT COUNT(*) as c FROM #__habitaciones WHERE edificio_id = ?', [$id]);
        if ($uso && $uso['c'] > 0) {
            throw new EdificioException('EDIFICIO_EN_USO', 'No se puede eliminar el edificio porque tiene habitaciones asociadas.');
        }

        Database::execute('DELETE FROM #__edificios WHERE id = ?', [$id]);

        Logger::info('edificios', 'edificio_eliminado', ['id' => $id, 'nombre' => $edificio->nombre], $usuarioId);
    }
}
