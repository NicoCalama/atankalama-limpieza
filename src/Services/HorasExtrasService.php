<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * «Horas extras» (v7.17; pedido de gerencia del 06/11/2026, decisiones de Nicolás del 07 y 08/11/2026).
 *
 * El TOTAL de horas extras del mes de cada persona de Aseo. Lo carga administración (permiso
 * horas_extras.registrar) en Reportes → Mensual, en una cuadrícula del año: filas = personas, columnas =
 * meses. Desde la v7.18 «KPIs por hora» esas horas se suman a sus horas en Aseo (HorasAseoService): una
 * persona con muchas extras tenía las piezas por día «al cielo». Ver docs/horas-extras.md.
 *
 * Reglas:
 *  - Un número por persona y mes, en horas de media en media hora, hasta 100 h. Vacío o 0 = sin extras.
 *  - Plazo: cada mes se escribe desde su día 1 hasta el día 6 del mes siguiente inclusive (hora de Chile);
 *    después queda en solo lectura para todos. Un mes que aún no empieza tampoco se escribe. Lo exige el
 *    servidor (409 FUERA_DE_PLAZO).
 *  - Desde noviembre de 2026 (MES_INICIO): los meses anteriores quedan como se calcularon.
 *  - Sale el personal de Aseo que entra al bono: con habitaciones.ver_asignadas_propias, sin
 *    asignaciones.excluir_auto ni kpis.excluido, activo o con piezas en un mes que sigue abierto (quien se
 *    fue a fin de mes igual recibe sus extras). Quien ya tiene extras cargadas en el año sale siempre.
 *  - Las extras son de la persona, no de un hotel: la cuadrícula no filtra por hotel.
 */
final class HorasExtrasService
{
    public const MES_INICIO = '2026-11';
    public const DIA_PLAZO = 6;
    public const MINUTOS_PASO = 30;
    public const MINUTOS_MAX = 6000;

    private const MESES = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    /** @param ?string $hoyFijo 'YYYY-MM-DD' para los tests; null = hoy en hora de Chile (APP_TIMEZONE). */
    public function __construct(
        private readonly UsuarioService $usuarios = new UsuarioService(),
        private readonly ?string $hoyFijo = null,
    ) {
    }

    public function hoy(): string
    {
        return $this->hoyFijo ?? date('Y-m-d');
    }

    /**
     * Meses que se pueden escribir el día $hoy: el mes en curso y, hasta el día DIA_PLAZO, también el
     * anterior. Nunca antes de MES_INICIO. Ordenados de más antiguo a más nuevo.
     *
     * @return list<string> 'YYYY-MM'
     */
    public static function mesesEditables(string $hoy): array
    {
        $dia = new \DateTimeImmutable($hoy);
        $meses = [];
        if ((int) $dia->format('j') <= self::DIA_PLAZO) {
            $meses[] = $dia->modify('first day of last month')->format('Y-m');
        }
        $meses[] = $dia->format('Y-m');
        return array_values(array_filter($meses, static fn(string $m): bool => $m >= self::MES_INICIO));
    }

    /**
     * La cuadrícula de un año: las personas con sus horas extras de cada mes y qué meses se pueden escribir.
     *
     * @return array{
     *     anio: int, hoy: string, mes_inicio: string, dia_plazo: int, meses_editables: list<string>,
     *     personas: list<array{id: int, nombre: string, activo: bool, meses: array<string, float>}>
     * }
     */
    public function cuadricula(int $anio): array
    {
        $hoy = $this->hoy();
        $anioMin = (int) substr(self::MES_INICIO, 0, 4);
        if ($anio < $anioMin || $anio > (int) substr($hoy, 0, 4)) {
            throw new HorasExtrasException(
                'ANIO_INVALIDO',
                'Las horas extras se cargan desde noviembre de ' . $anioMin . ' hasta el año en curso.',
                400
            );
        }
        $desde = sprintf('%04d-01', $anio);
        $hasta = sprintf('%04d-12', $anio);
        $editables = self::mesesEditables($hoy);
        $editablesDelAnio = array_values(array_filter(
            $editables,
            static fn(string $m): bool => str_starts_with($m, $anio . '-')
        ));

        // Los «?» se llenan en el orden del texto y cada helper agrega su permiso al armarse: se arma de
        // izquierda a derecha.
        $params = [$desde, $hasta];
        $sql = 'SELECT u.id, u.nombre, u.activo
                  FROM #__usuarios u
                 WHERE EXISTS (SELECT 1 FROM #__horas_extras he WHERE he.usuario_id = u.id AND he.mes >= ? AND he.mes <= ?)
                    OR (' . RbacService::sqlConPermiso('u.id', 'habitaciones.ver_asignadas_propias', $params)
            . ' AND ' . RbacService::sqlSinPermiso('u.id', RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR, $params)
            . ' AND ' . RbacService::sqlSinPermiso('u.id', RbacService::PERMISO_EXCLUIDO_KPIS, $params)
            . ' AND (u.activo = 1';
        if ($editablesDelAnio !== []) {
            // Quien ya no está activo sale si tuvo piezas en un mes que sigue abierto.
            $sql .= ' OR EXISTS (SELECT 1 FROM #__asignaciones a WHERE a.usuario_id = u.id AND a.fecha >= ? AND a.fecha <= ?)';
            $params[] = $editablesDelAnio[0] . '-01';
            $params[] = end($editablesDelAnio) . '-31';
        }
        $sql .= '))';

        $personas = [];
        foreach (Database::fetchAll($sql, $params) as $f) {
            $personas[(int) $f['id']] = [
                'id' => (int) $f['id'],
                'nombre' => (string) $f['nombre'],
                'activo' => ((int) $f['activo']) === 1,
                'meses' => [],
            ];
        }
        if ($personas !== []) {
            foreach (Database::fetchAll(
                'SELECT usuario_id, mes, minutos FROM #__horas_extras WHERE mes >= ? AND mes <= ?',
                [$desde, $hasta]
            ) as $f) {
                $id = (int) $f['usuario_id'];
                if (isset($personas[$id])) {
                    // round() devuelve float también cuando la división es exacta (720 min → 12.0).
                    $personas[$id]['meses'][(string) $f['mes']] = round(((int) $f['minutos']) / 60, 1);
                }
            }
        }
        $salida = array_values($personas);
        foreach ($salida as &$p) {
            ksort($p['meses']);
        }
        unset($p);
        usort($salida, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre'])));

        return [
            'anio' => $anio,
            'hoy' => $hoy,
            'mes_inicio' => self::MES_INICIO,
            'dia_plazo' => self::DIA_PLAZO,
            'meses_editables' => $editables,
            'personas' => $salida,
        ];
    }

    /**
     * Deja el total de horas extras de una persona en un mes. $horas vacío, null o 0 borra el total.
     * Devuelve cómo quedó (null = sin extras).
     */
    public function guardar(int $usuarioId, string $mes, mixed $horas, int $actorId): ?float
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) !== 1) {
            throw new HorasExtrasException('MES_INVALIDO', 'El mes no es válido.', 400);
        }
        $this->exigirPlazo($mes);
        $minutos = self::aMinutos($horas);

        $persona = $this->usuarios->buscarPorId($usuarioId);
        if ($persona === null) {
            throw new HorasExtrasException('PERSONA_NO_ENCONTRADA', 'No encontramos a esa persona.', 404);
        }
        $actual = Database::fetchOne(
            'SELECT id, minutos FROM #__horas_extras WHERE usuario_id = ? AND mes = ?',
            [$usuarioId, $mes]
        );
        // Quien ya tiene extras ese mes se puede corregir aunque después le hayan cambiado el rol.
        if ($actual === null && $minutos !== null && (
            !$persona->tienePermiso('habitaciones.ver_asignadas_propias')
            || $persona->tienePermiso(RbacService::PERMISO_EXCLUIDO_AUTO_ASIGNAR)
            || $persona->tienePermiso(RbacService::PERMISO_EXCLUIDO_KPIS)
        )) {
            throw new HorasExtrasException('NO_ES_DE_ASEO', 'Aquí solo se cargan horas extras del personal de Aseo.', 400);
        }

        $antes = $actual === null ? null : (int) $actual['minutos'];
        if ($antes === $minutos) {
            return $minutos === null ? null : $minutos / 60;
        }

        if ($minutos === null) {
            Database::execute('DELETE FROM #__horas_extras WHERE id = ?', [(int) $actual['id']]);
        } elseif ($actual === null) {
            Database::execute(
                'INSERT INTO #__horas_extras (usuario_id, mes, minutos, registrado_por) VALUES (?, ?, ?, ?)',
                [$usuarioId, $mes, $minutos, $actorId]
            );
        } else {
            Database::execute(
                "UPDATE #__horas_extras
                    SET minutos = ?, registrado_por = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
                  WHERE id = ?",
                [$minutos, $actorId, (int) $actual['id']]
            );
        }
        Logger::audit($actorId, 'horas_extras.guardar', 'usuario', $usuarioId, [
            'mes' => $mes,
            'antes_minutos' => $antes,
            'despues_minutos' => $minutos,
        ]);

        return $minutos === null ? null : $minutos / 60;
    }

    // ───────────────────────────── Internos ─────────────────────────────

    private function exigirPlazo(string $mes): void
    {
        if ($mes < self::MES_INICIO) {
            throw new HorasExtrasException(
                'ANTES_DE_INICIO',
                'Las horas extras se cargan desde ' . self::nombreMes(self::MES_INICIO) . '.',
                409
            );
        }
        $hoy = $this->hoy();
        if (in_array($mes, self::mesesEditables($hoy), true)) {
            return;
        }
        if ($mes > substr($hoy, 0, 7)) {
            throw new HorasExtrasException('FUERA_DE_PLAZO', 'Ese mes todavía no empieza.', 409);
        }
        $siguiente = (new \DateTimeImmutable($mes . '-01'))->modify('first day of next month');
        throw new HorasExtrasException(
            'FUERA_DE_PLAZO',
            'Las horas extras de ' . self::nombreMes($mes) . ' se podían cargar hasta el ' . self::DIA_PLAZO
                . ' de ' . self::nombreMes($siguiente->format('Y-m')) . '.',
            409
        );
    }

    /** Horas (número o texto con coma) → minutos de 30 en 30; null si viene vacío o 0. */
    private static function aMinutos(mixed $horas): ?int
    {
        if ($horas === null || $horas === '') {
            return null;
        }
        if (is_string($horas)) {
            $horas = str_replace(',', '.', trim($horas));
        }
        if (!is_numeric($horas)) {
            throw new HorasExtrasException('HORAS_INVALIDAS', 'Escribe las horas como número, por ejemplo 7,5.', 400);
        }
        $exactos = (float) $horas * 60;
        $minutos = (int) round($exactos);
        if (abs($exactos - $minutos) > 0.001 || $minutos < 0 || $minutos % self::MINUTOS_PASO !== 0 || $minutos > self::MINUTOS_MAX) {
            throw new HorasExtrasException(
                'HORAS_INVALIDAS',
                'Las horas van de media en media hora, hasta ' . (self::MINUTOS_MAX / 60) . ' horas en el mes.',
                400
            );
        }
        return $minutos === 0 ? null : $minutos;
    }

    /** 'YYYY-MM' → «noviembre de 2026». */
    public static function nombreMes(string $mes): string
    {
        return self::MESES[(int) substr($mes, 5, 2)] . ' de ' . substr($mes, 0, 4);
    }
}
