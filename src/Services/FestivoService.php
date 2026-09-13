<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

final class FestivoService
{
    /** @return list<array<string, mixed>> */
    public function listarRango(string $desde, string $hasta): array
    {
        $this->validarFecha($desde);
        $this->validarFecha($hasta);
        return Database::fetchAll(
            'SELECT id, fecha, nombre FROM #__festivos WHERE fecha BETWEEN ? AND ? ORDER BY fecha',
            [$desde, $hasta]
        );
    }

    public function crear(string $fecha, string $nombre, int $usuarioId): int
    {
        $this->validarFecha($fecha);
        $nombre = trim($nombre);
        if ($nombre === '' || strlen($nombre) > 100) {
            throw new FestivoException('NOMBRE_INVALIDO', 'Nombre debe tener entre 1 y 100 caracteres.', 400);
        }
        $existente = Database::fetchOne('SELECT id FROM #__festivos WHERE fecha = ?', [$fecha]);
        if ($existente !== null) {
            throw new FestivoException('FECHA_DUPLICADA', "Ya existe un festivo registrado para {$fecha}.", 409);
        }
        Database::execute('INSERT INTO #__festivos (fecha, nombre) VALUES (?, ?)', [$fecha, $nombre]);
        $id = Database::lastInsertId();
        Logger::audit($usuarioId, 'festivo.crear', 'festivo', $id, ['fecha' => $fecha, 'nombre' => $nombre]);
        return $id;
    }

    public function eliminar(int $id, int $usuarioId): void
    {
        $existente = Database::fetchOne('SELECT id FROM #__festivos WHERE id = ?', [$id]);
        if ($existente === null) {
            throw new FestivoException('FESTIVO_NO_ENCONTRADO', 'Festivo no encontrado.', 404);
        }
        Database::execute('DELETE FROM #__festivos WHERE id = ?', [$id]);
        Logger::audit($usuarioId, 'festivo.eliminar', 'festivo', $id, []);
    }

    private function validarFecha(string $fecha): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new FestivoException('FECHA_INVALIDA', 'fecha debe ser YYYY-MM-DD.', 400);
        }
    }
}
