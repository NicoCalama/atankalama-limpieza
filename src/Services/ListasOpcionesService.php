<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * Listas de selección (v7.3, pedido de gerencia del 10/10/2026): las acciones ya no llevan cuadro de
 * texto, se elige de una lista que se edita en Ajustes → Checklists y listas (permiso listas.editar).
 *
 * Las listas viven en `listas_opciones` (columna `lista`), salvo el NO de la inspección pre-entrega, que
 * conserva su tabla `motivos_revision_entrega` (FK RESTRICT desde revisiones_entrega): acá se trata como
 * una lista más, `no_recepcion`. Las opciones nunca se borran, se desactivan: el historial las conserva.
 * Ver docs/ajustes.md §2.3.
 */
final class ListasOpcionesService
{
    public const MAX_NOMBRE = 60;

    /** Lista que vive en su propia tabla (inspección pre-entrega). */
    public const NO_RECEPCION = 'no_recepcion';

    /**
     * Listas que se editan desde Ajustes, en el orden en que se muestran. La v7.4 suma el mensaje de
     * Recepción a la mucama y el tipo de problema y las respuestas de un ticket; la v7.11, el motivo de
     * «No se pudo inspeccionar».
     */
    public const LISTAS = ['rechazo', 'sin_inspeccion', 'reasignar', 'saltar', self::NO_RECEPCION, 'nota_recepcion', 'ticket_tipo', 'ticket_respuesta'];

    /** Lista del motivo de «No se pudo inspeccionar» (v7.11). */
    public const SIN_INSPECCION = 'sin_inspeccion';

    /** Lista del mensaje de Recepción a la mucama: la única en que se pueden elegir varias opciones a la vez. */
    public const NOTA_RECEPCION = 'nota_recepcion';

    /**
     * Nombres de varias opciones activas de una lista, en el orden de la lista (sin repetir). La usa el
     * mensaje a la mucama, que admite varias. Si alguna no está activa, OPCION_INVALIDA como opcionActiva().
     *
     * @param list<int> $ids
     * @return list<string>
     */
    public function nombresActivos(string $lista, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new ListasOpcionesException('OPCION_REQUERIDA', 'Elige al menos una opción.', 400);
        }
        $activas = $this->listar($lista, true);
        $porId = array_column($activas, 'nombre', 'id');
        foreach ($ids as $id) {
            if (!isset($porId[$id])) {
                throw new ListasOpcionesException(
                    'OPCION_INVALIDA',
                    'Esa opción ya no está disponible. Recarga la página y elige otra.',
                    400
                );
            }
        }
        $elegidas = array_filter($activas, static fn(array $o): bool => in_array($o['id'], $ids, true));
        return array_values(array_map(static fn(array $o): string => $o['nombre'], $elegidas));
    }

    public function existe(string $lista): bool
    {
        return in_array($lista, self::LISTAS, true);
    }

    /**
     * Opciones de una lista en su orden. Con $soloActivas = false también las desactivadas (Ajustes).
     *
     * @return list<array{id: int, nombre: string, activo: bool, orden: int}>
     */
    public function listar(string $lista, bool $soloActivas = true): array
    {
        [$tabla, $where, $params] = $this->origen($lista);
        if ($soloActivas) {
            $where .= ($where === '' ? ' WHERE' : ' AND') . ' activo = 1';
        }
        $filas = Database::fetchAll("SELECT id, nombre, activo, orden FROM {$tabla}{$where} ORDER BY orden, id", $params);
        return array_map(static fn(array $f): array => [
            'id' => (int) $f['id'],
            'nombre' => (string) $f['nombre'],
            'activo' => ((int) $f['activo']) === 1,
            'orden' => (int) $f['orden'],
        ], $filas);
    }

    /**
     * Cuántas opciones activas y en total tiene cada lista (tarjetas de Ajustes).
     *
     * @return list<array{lista: string, activas: int, total: int}>
     */
    public function resumen(): array
    {
        $resumen = [];
        foreach (self::LISTAS as $lista) {
            $opciones = $this->listar($lista, false);
            $resumen[] = [
                'lista' => $lista,
                'activas' => count(array_filter($opciones, static fn(array $o): bool => $o['activo'])),
                'total' => count($opciones),
            ];
        }
        return $resumen;
    }

    /** Agrega una opción al final de la lista. Devuelve su id. */
    public function crear(string $lista, string $nombre, int $usuarioId): int
    {
        $this->exigirLista($lista);
        $nombre = $this->validarNombre($nombre);
        $this->exigirNombreLibre($lista, $nombre, null);
        [$tabla, $where, $params] = $this->origen($lista);
        $orden = (int) Database::fetchColumn("SELECT COALESCE(MAX(orden), 0) FROM {$tabla}{$where}", $params) + 1;
        try {
            if ($lista === self::NO_RECEPCION) {
                Database::execute("INSERT INTO {$tabla} (nombre, orden) VALUES (?, ?)", [$nombre, $orden]);
            } else {
                Database::execute("INSERT INTO {$tabla} (lista, nombre, orden) VALUES (?, ?, ?)", [$lista, $nombre, $orden]);
            }
        } catch (\PDOException $e) {
            $this->relanzarSiDuplicado($e);
            throw $e;
        }
        $id = Database::lastInsertId();
        Logger::audit($usuarioId, 'listas.opcion_crear', $this->entidad($lista), $id, ['lista' => $lista, 'nombre' => $nombre]);
        return $id;
    }

    /**
     * Renombra y/o activa-desactiva. Una lista nunca queda sin opciones activas: la acción que la usa
     * (rechazar, saltar…) se quedaría sin de dónde elegir.
     *
     * @param array{nombre?: string, activo?: bool} $datos
     */
    public function actualizar(string $lista, int $id, array $datos, int $usuarioId): void
    {
        $this->exigirLista($lista);
        $actual = $this->buscar($lista, $id);
        if ($actual === null) {
            throw new ListasOpcionesException('OPCION_NO_ENCONTRADA', 'No encontramos esa opción.', 404);
        }
        $sets = [];
        $params = [];
        if (array_key_exists('nombre', $datos)) {
            $nombre = $this->validarNombre((string) $datos['nombre']);
            $this->exigirNombreLibre($lista, $nombre, $id);
            $sets[] = 'nombre = ?';
            $params[] = $nombre;
        }
        if (array_key_exists('activo', $datos)) {
            $activar = (bool) $datos['activo'];
            if (!$activar && $actual['activo'] && $this->contarActivas($lista) <= 1) {
                throw new ListasOpcionesException(
                    'ULTIMA_OPCION_ACTIVA',
                    'La lista necesita al menos una opción activa: activa otra antes de desactivar esta.',
                    409
                );
            }
            $sets[] = 'activo = ?';
            $params[] = $activar ? 1 : 0;
        }
        if ($sets === []) {
            return;
        }
        [$tabla] = $this->origen($lista);
        $params[] = $id;
        try {
            Database::execute("UPDATE {$tabla} SET " . implode(', ', $sets) . ' WHERE id = ?', $params);
        } catch (\PDOException $e) {
            $this->relanzarSiDuplicado($e);
            throw $e;
        }
        Logger::audit($usuarioId, 'listas.opcion_actualizar', $this->entidad($lista), $id, ['lista' => $lista] + $datos);
    }

    /**
     * Deja la lista en el orden de $ids, que tiene que traer exactamente todas sus opciones (también las
     * desactivadas): así un orden armado con una lista vieja no pisa una opción agregada entre medio.
     *
     * @param list<int> $ids
     */
    public function reordenar(string $lista, array $ids, int $usuarioId): void
    {
        $this->exigirLista($lista);
        $actuales = array_map(static fn(array $o): int => $o['id'], $this->listar($lista, false));
        $pedidos = array_map('intval', $ids);
        $ordenadosA = $actuales;
        $ordenadosB = $pedidos;
        sort($ordenadosA);
        sort($ordenadosB);
        if ($ordenadosA !== $ordenadosB) {
            throw new ListasOpcionesException(
                'ORDEN_DESACTUALIZADO',
                'La lista cambió mientras la ordenabas. Recarga y vuelve a intentarlo.',
                409
            );
        }
        [$tabla] = $this->origen($lista);
        Database::transaction(static function () use ($tabla, $pedidos): void {
            foreach ($pedidos as $i => $id) {
                Database::execute("UPDATE {$tabla} SET orden = ? WHERE id = ?", [$i + 1, $id]);
            }
        });
        Logger::audit($usuarioId, 'listas.reordenar', null, null, ['lista' => $lista, 'ids' => $pedidos]);
    }

    /**
     * La opción elegida en una acción, si sigue activa en esa lista. La usan los flujos (rechazar, saltar,
     * reasignar) para validar y para guardar el nombre tal como se vio.
     *
     * @return array{id: int, nombre: string}
     */
    public function opcionActiva(string $lista, ?int $id): array
    {
        $fila = ($id === null || $id <= 0) ? null : $this->buscar($lista, $id);
        if ($fila === null || !$fila['activo']) {
            throw new ListasOpcionesException(
                'OPCION_INVALIDA',
                'Esa opción ya no está disponible. Recarga la página y elige otra.',
                400
            );
        }
        return ['id' => $fila['id'], 'nombre' => $fila['nombre']];
    }

    // ───────────────────────────── Internos ─────────────────────────────

    /**
     * Tabla, filtro y parámetros de una lista.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function origen(string $lista): array
    {
        if ($lista === self::NO_RECEPCION) {
            return [Database::tabla('motivos_revision_entrega'), '', []];
        }
        return [Database::tabla('listas_opciones'), ' WHERE lista = ?', [$lista]];
    }

    /** @return array{id: int, nombre: string, activo: bool}|null */
    private function buscar(string $lista, int $id): ?array
    {
        [$tabla, $where, $params] = $this->origen($lista);
        $where .= ($where === '' ? ' WHERE' : ' AND') . ' id = ?';
        $params[] = (string) $id;
        $fila = Database::fetchOne("SELECT id, nombre, activo FROM {$tabla}{$where}", $params);
        if ($fila === null) {
            return null;
        }
        return ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre'], 'activo' => ((int) $fila['activo']) === 1];
    }

    private function contarActivas(string $lista): int
    {
        return count($this->listar($lista, true));
    }

    private function exigirLista(string $lista): void
    {
        if (!$this->existe($lista)) {
            throw new ListasOpcionesException('LISTA_INVALIDA', 'Esa lista no existe.', 404);
        }
    }

    private function validarNombre(string $nombre): string
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? $nombre);
        $largo = mb_strlen($nombre);
        if ($largo < 2 || $largo > self::MAX_NOMBRE) {
            throw new ListasOpcionesException(
                'NOMBRE_INVALIDO',
                'La opción debe tener entre 2 y ' . self::MAX_NOMBRE . ' caracteres.',
                400
            );
        }
        return $nombre;
    }

    /**
     * UNIQUE distingue mayúsculas en SQLite y no en MariaDB, y LOWER() de SQLite no baja la «Ñ» ni las
     * tildes: se compara en PHP con mb_strtolower (cada lista son unas pocas filas).
     */
    private function exigirNombreLibre(string $lista, string $nombre, ?int $excepto): void
    {
        $buscado = mb_strtolower($nombre);
        foreach ($this->listar($lista, false) as $opcion) {
            if ($opcion['id'] !== $excepto && mb_strtolower($opcion['nombre']) === $buscado) {
                throw new ListasOpcionesException('OPCION_DUPLICADA', 'Ya existe una opción con ese nombre en esta lista.', 409);
            }
        }
    }

    private function relanzarSiDuplicado(\PDOException $e): void
    {
        if (stripos($e->getMessage(), 'UNIQUE') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
            throw new ListasOpcionesException('OPCION_DUPLICADA', 'Ya existe una opción con ese nombre en esta lista.', 409);
        }
    }

    private function entidad(string $lista): string
    {
        return $lista === self::NO_RECEPCION ? 'motivo_revision_entrega' : 'lista_opcion';
    }
}
