<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Hotel;

final class HotelService
{
    /** @return Hotel[] */
    public function listar(bool $soloActivos = true): array
    {
        $sql = $soloActivos
            ? 'SELECT * FROM hoteles WHERE activo = 1 ORDER BY id'
            : 'SELECT * FROM hoteles ORDER BY id';
        return array_map(fn($f) => Hotel::desdeFila($f), Database::fetchAll($sql));
    }

    public function buscarPorId(int $id): ?Hotel
    {
        $fila = Database::fetchOne('SELECT * FROM hoteles WHERE id = ?', [$id]);
        return $fila === null ? null : Hotel::desdeFila($fila);
    }

    public function buscarPorCodigo(string $codigo): ?Hotel
    {
        $fila = Database::fetchOne('SELECT * FROM hoteles WHERE codigo = ?', [$codigo]);
        return $fila === null ? null : Hotel::desdeFila($fila);
    }
}
