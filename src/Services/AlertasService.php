<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Models\BitacoraAlerta;

final class AlertasService
{
    public const CONFIG_DEFAULTS = [
        'margen_seguridad_minutos' => '15',
        'fin_turno_anticipo_minutos' => '30',
        'recalculo_intervalo_minutos' => '15',
        'tiempo_fallback_nueva_habitacion' => '30',
    ];

    /**
     * Levanta una alerta. Si ya existe una activa del mismo tipo + dedupe key, no la duplica.
     *
     * @param array<string, mixed> $contexto
     */
    public function levantar(
        string $tipo,
        string $titulo,
        string $descripcion,
        array $contexto = [],
        ?int $hotelId = null,
        ?string $dedupeKey = null,
    ): AlertaActiva {
        if (!in_array($tipo, AlertaActiva::TIPOS_VALIDOS, true)) {
            throw new AlertasException('TIPO_INVALIDO', "Tipo de alerta inválido: {$tipo}.", 400);
        }
        $prioridad = AlertaActiva::PRIORIDAD_POR_TIPO[$tipo];

        if ($dedupeKey !== null) {
            $existente = $this->buscarActivaPorDedupe($tipo, $dedupeKey);
            if ($existente !== null) {
                return $existente;
            }
        }

        $contextoConKey = $contexto;
        if ($dedupeKey !== null) {
            $contextoConKey['_dedupe'] = $dedupeKey;
        }
        $json = $contextoConKey === [] ? null : json_encode($contextoConKey, JSON_UNESCAPED_UNICODE);

        Database::execute(
            'INSERT INTO alertas_activas (tipo, prioridad, titulo, descripcion, contexto_json, hotel_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$tipo, $prioridad, $titulo, $descripcion, $json, $hotelId]
        );
        $id = Database::lastInsertId();

        Database::execute(
            'INSERT INTO bitacora_alertas (tipo, prioridad, titulo, descripcion, contexto_json, hotel_id, levantada_at) VALUES (?, ?, ?, ?, ?, ?, strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))',
            [$tipo, $prioridad, $titulo, $descripcion, $json, $hotelId]
        );

        Logger::info('alertas', 'alerta levantada', [
            'id' => $id, 'tipo' => $tipo, 'prioridad' => $prioridad, 'hotel_id' => $hotelId,
        ]);

        $fila = Database::fetchOne('SELECT * FROM alertas_activas WHERE id = ?', [$id]);
        return AlertaActiva::desdeFila($fila);
    }

    public function resolver(int $alertaId, string $resolucion, ?int $usuarioId = null, ?string $accion = null): void
    {
        if (!in_array($resolucion, [
            BitacoraAlerta::RESOLUCION_AUTO,
            BitacoraAlerta::RESOLUCION_ACCION_USUARIO,
            BitacoraAlerta::RESOLUCION_DESCARTADA,
        ], true)) {
            throw new AlertasException('RESOLUCION_INVALIDA', "Resolución inválida: {$resolucion}.", 400);
        }
        $alerta = Database::fetchOne('SELECT * FROM alertas_activas WHERE id = ?', [$alertaId]);
        if ($alerta === null) {
            return;
        }
        Database::execute('DELETE FROM alertas_activas WHERE id = ?', [$alertaId]);
        Database::execute(
            "UPDATE bitacora_alertas
                SET resuelta_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
                    resolucion = ?,
                    resuelta_por = ?,
                    accion_tomada = ?
              WHERE tipo = ? AND levantada_at = (
                  SELECT MAX(levantada_at) FROM bitacora_alertas
                   WHERE tipo = ? AND resuelta_at IS NULL
              )",
            [$resolucion, $usuarioId, $accion, $alerta['tipo'], $alerta['tipo']]
        );

        Logger::info('alertas', 'alerta resuelta', [
            'id' => $alertaId, 'tipo' => $alerta['tipo'], 'resolucion' => $resolucion,
        ]);
    }

    /**
     * Resuelve cualquier alerta activa que matchee tipo + dedupeKey.
     */
    public function resolverPorDedupe(string $tipo, string $dedupeKey, string $resolucion = BitacoraAlerta::RESOLUCION_AUTO): void
    {
        $existente = $this->buscarActivaPorDedupe($tipo, $dedupeKey);
        if ($existente !== null) {
            $this->resolver($existente->id, $resolucion);
        }
    }

    /** @return list<AlertaActiva> */
    public function listarActivas(?string $hotelCodigo = null, ?int $limit = null): array
    {
        $sql = 'SELECT a.* FROM alertas_activas a';
        $params = [];
        if ($hotelCodigo !== null && $hotelCodigo !== 'ambos') {
            $sql .= ' LEFT JOIN hoteles h ON h.id = a.hotel_id WHERE (h.codigo = ? OR a.hotel_id IS NULL)';
            $params[] = $hotelCodigo;
        }
        $sql .= ' ORDER BY a.prioridad ASC, a.created_at DESC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $filas = Database::fetchAll($sql, $params);
        return array_map(static fn(array $f) => AlertaActiva::desdeFila($f), $filas);
    }

    /** @return array{top: list<array<string,mixed>>, total: int} */
    public function bandejaTop(?string $hotelCodigo = null, int $top = 5): array
    {
        $activas = $this->listarActivas($hotelCodigo);
        $total = count($activas);
        $topItems = array_slice($activas, 0, $top);
        return [
            'top' => array_map(static fn(AlertaActiva $a) => $a->toArray(), $topItems),
            'total' => $total,
        ];
    }

    public function obtener(int $id): ?AlertaActiva
    {
        $fila = Database::fetchOne('SELECT * FROM alertas_activas WHERE id = ?', [$id]);
        return $fila === null ? null : AlertaActiva::desdeFila($fila);
    }

    /** @return list<array<string, mixed>> */
    public function listarBitacora(?string $tipo = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM bitacora_alertas';
        $params = [];
        if ($tipo !== null) {
            $sql .= ' WHERE tipo = ?';
            $params[] = $tipo;
        }
        $sql .= ' ORDER BY levantada_at DESC LIMIT ?';
        $params[] = $limit;
        return Database::fetchAll($sql, $params);
    }

    // ----- Configuración -----

    public function obtenerConfig(string $clave): string
    {
        $fila = Database::fetchOne('SELECT valor FROM alertas_config WHERE clave = ?', [$clave]);
        if ($fila !== null) {
            return (string) $fila['valor'];
        }
        return self::CONFIG_DEFAULTS[$clave] ?? '';
    }

    public function obtenerConfigInt(string $clave): int
    {
        return (int) $this->obtenerConfig($clave);
    }

    /** @return array<string, string> */
    public function listarConfig(): array
    {
        $filas = Database::fetchAll('SELECT clave, valor FROM alertas_config');
        $persistidas = [];
        foreach ($filas as $f) {
            $persistidas[(string) $f['clave']] = (string) $f['valor'];
        }
        return array_merge(self::CONFIG_DEFAULTS, $persistidas);
    }

    public function actualizarConfig(string $clave, string $valor, int $usuarioId): void
    {
        $existente = Database::fetchOne('SELECT clave FROM alertas_config WHERE clave = ?', [$clave]);
        if ($existente === null) {
            Database::execute(
                'INSERT INTO alertas_config (clave, valor, updated_by) VALUES (?, ?, ?)',
                [$clave, $valor, $usuarioId]
            );
        } else {
            Database::execute(
                "UPDATE alertas_config SET valor = ?, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_by = ? WHERE clave = ?",
                [$valor, $usuarioId, $clave]
            );
        }
        Logger::audit($usuarioId, 'alertas.config_actualizar', 'alertas_config', null, [
            'clave' => $clave, 'valor' => $valor,
        ]);
    }

    private function buscarActivaPorDedupe(string $tipo, string $dedupeKey): ?AlertaActiva
    {
        $needle = '"_dedupe":"' . $dedupeKey . '"';
        $fila = Database::fetchOne(
            'SELECT * FROM alertas_activas WHERE tipo = ? AND contexto_json LIKE ? LIMIT 1',
            [$tipo, '%' . $needle . '%']
        );
        return $fila === null ? null : AlertaActiva::desdeFila($fila);
    }
}
