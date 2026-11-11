<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * Catálogo de tareas de las áreas comunes beta (v7.27): nombre + créditos por m², único para los dos
 * hoteles. Ver docs/areas-comunes.md §9.
 */
final class TareasCatalogoService
{
    public const NOMBRE_MAX = 60;
    public const VALOR_MAX = 100.0;
    public const M2_MAX = 99999.9;
    public const RESULTADOS_BUSQUEDA = 8;

    /**
     * Créditos de una tarea: valor por m² × m², al entero más cercano y como mínimo 1 (decisión de Nicolás,
     * 11/11/2026). En enteros (décimas de m² y milésimas de crédito) para que el resultado sea exacto: estos
     * créditos van a definir pagos, y el mismo cálculo se hace en la pantalla para la comparación antes/después.
     */
    public static function creditosDe(float $m2, float $valorM2): int
    {
        $decimasM2 = (int) round($m2 * 10);
        $milesimasValor = (int) round($valorM2 * 1000);
        return max(1, intdiv($decimasM2 * $milesimasValor + 5000, 10000));
    }

    /** Minúsculas, sin acentos y con un solo espacio entre palabras: la forma con que se busca y se compara. */
    public static function normalizarNombre(string $nombre): string
    {
        $texto = strtr(mb_strtolower(trim($nombre), 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        return (string) preg_replace('/\s+/u', ' ', $texto);
    }

    /**
     * Búsqueda mientras se escribe: tareas con alguna palabra que empiece con lo escrito, las más usadas primero.
     * Sin texto devuelve las más usadas.
     *
     * @return list<array{id: int, nombre: string, creditos_m2: float}>
     */
    public function buscar(string $texto, int $limite = self::RESULTADOS_BUSQUEDA): array
    {
        $q = self::normalizarNombre($texto);
        $condicion = '';
        $params = [];
        if ($q !== '') {
            // Escape con «!»: en MariaDB la barra invertida también escapa dentro de un literal y ESCAPE '\' no es válido.
            $patron = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
            $condicion = " AND (t.nombre_busqueda LIKE ? ESCAPE '!' OR t.nombre_busqueda LIKE ? ESCAPE '!')";
            $params = [$patron . '%', '% ' . $patron . '%'];
        }
        $limite = max(1, min(self::RESULTADOS_BUSQUEDA, $limite));

        $filas = Database::fetchAll(
            'SELECT t.id, t.nombre, t.creditos_m2,
                    (SELECT COUNT(*) FROM #__espacio_tareas et WHERE et.tarea_id = t.id)
                    + (SELECT COUNT(*) FROM #__extras_areas_comunes ex WHERE ex.tarea_id = t.id) AS usos
               FROM #__tareas_catalogo t
              WHERE t.activa = 1' . $condicion . '
              ORDER BY usos DESC, t.nombre
              LIMIT ' . $limite,
            $params
        );
        return array_map(self::publica(...), $filas);
    }

    /**
     * Todo el catálogo, por nombre (Ajustes → Checklists y listas → Tareas).
     *
     * @return list<array{id: int, nombre: string, creditos_m2: float}>
     */
    public function listar(): array
    {
        return array_map(
            self::publica(...),
            Database::fetchAll('SELECT id, nombre, creditos_m2 FROM #__tareas_catalogo WHERE activa = 1 ORDER BY nombre')
        );
    }

    public function crear(string $nombre, float $valorM2, ?int $actorId = null): int
    {
        $nombre = self::validarNombre($nombre);
        $valorM2 = self::validarValor($valorM2);
        $busqueda = self::normalizarNombre($nombre);
        self::exigirNombreLibre($busqueda, null);

        try {
            Database::execute(
                'INSERT INTO #__tareas_catalogo (nombre, nombre_busqueda, creditos_m2, creado_por) VALUES (?, ?, ?, ?)',
                [$nombre, $busqueda, $valorM2, $actorId]
            );
        } catch (\PDOException $e) {
            throw self::siEsDuplicado($e);
        }
        $id = Database::lastInsertId();
        Logger::audit($actorId, 'tarea.crear', 'tarea_catalogo', $id, ['nombre' => $nombre, 'creditos_m2' => $valorM2]);
        return $id;
    }

    /**
     * Edita una tarea ya creada (Ajustes → Checklists y listas → Tareas) y rehace el checklist de las áreas que la
     * tienen como fija. Lo ya registrado no cambia: las limpiezas empezadas siguen leyendo sus ítems.
     *
     * @return int áreas actualizadas
     */
    public function editar(int $tareaId, string $nombre, float $valorM2, ?int $actorId = null): int
    {
        $nombre = self::validarNombre($nombre);
        $valorM2 = self::validarValor($valorM2);
        $tarea = self::obtener($tareaId);
        $busqueda = self::normalizarNombre($nombre);
        self::exigirNombreLibre($busqueda, $tareaId);

        try {
            $areas = Database::transaction(function () use ($tareaId, $nombre, $busqueda, $valorM2, $actorId): int {
                Database::execute(
                    "UPDATE #__tareas_catalogo
                        SET nombre = ?, nombre_busqueda = ?, creditos_m2 = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                      WHERE id = ?",
                    [$nombre, $busqueda, $valorM2, $tareaId]
                );
                return (new EspacioService())->rehacerChecklistsDeTarea($tareaId, $actorId);
            });
        } catch (\PDOException $e) {
            throw self::siEsDuplicado($e);
        }

        Logger::audit($actorId, 'tarea.editar', 'tarea_catalogo', $tareaId, [
            'antes' => ['nombre' => $tarea['nombre'], 'creditos_m2' => (float) $tarea['creditos_m2']],
            'ahora' => ['nombre' => $nombre, 'creditos_m2' => $valorM2],
            'areas' => $areas,
        ]);
        return $areas;
    }

    private static function validarNombre(string $nombre): string
    {
        $nombre = (string) preg_replace('/\s+/u', ' ', trim($nombre));
        if ($nombre === '') {
            throw new EspacioException('TAREA_NOMBRE_REQUERIDO', 'Escribe el nombre de la tarea.', 400);
        }
        if (mb_strlen($nombre) > self::NOMBRE_MAX) {
            throw new EspacioException('TAREA_NOMBRE_LARGO', 'El nombre no puede superar los ' . self::NOMBRE_MAX . ' caracteres.', 400);
        }
        return $nombre;
    }

    private static function exigirNombreLibre(string $busqueda, ?int $excepto): void
    {
        $fila = Database::fetchOne('SELECT id FROM #__tareas_catalogo WHERE nombre_busqueda = ?', [$busqueda]);
        if ($fila !== null && (int) $fila['id'] !== $excepto) {
            throw new EspacioException('TAREA_DUPLICADA', 'Esa tarea ya existe en el catálogo.', 409);
        }
    }

    /** 23000 = dos personas guardando el mismo nombre a la vez: el UNIQUE deja pasar a una sola. */
    private static function siEsDuplicado(\PDOException $e): \Throwable
    {
        return (string) $e->getCode() === '23000'
            ? new EspacioException('TAREA_DUPLICADA', 'Esa tarea ya existe en el catálogo.', 409)
            : $e;
    }

    /** @return array<string, mixed> */
    public static function obtener(int $tareaId): array
    {
        $tarea = Database::fetchOne('SELECT * FROM #__tareas_catalogo WHERE id = ? AND activa = 1', [$tareaId]);
        if ($tarea === null) {
            throw new EspacioException('TAREA_INVALIDA', 'Esa tarea no está en el catálogo. Recarga la página.', 400);
        }
        return $tarea;
    }

    /** m² con una decimal, mayor que cero. */
    public static function validarM2(float $m2): float
    {
        $m2 = round($m2, 1);
        if ($m2 <= 0.0 || $m2 > self::M2_MAX) {
            throw new EspacioException('M2_INVALIDO', 'Los metros cuadrados deben ser mayores que cero.', 400);
        }
        return $m2;
    }

    private static function validarValor(float $valorM2): float
    {
        $valorM2 = round($valorM2, 3);
        if ($valorM2 <= 0.0 || $valorM2 > self::VALOR_MAX) {
            throw new EspacioException(
                'VALOR_INVALIDO',
                'El valor por m² debe ser mayor que cero y no superar ' . (int) self::VALOR_MAX . '.',
                400
            );
        }
        return $valorM2;
    }

    /**
     * @param array<string, mixed> $fila
     * @return array{id: int, nombre: string, creditos_m2: float}
     */
    private static function publica(array $fila): array
    {
        return ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre'], 'creditos_m2' => (float) $fila['creditos_m2']];
    }
}
