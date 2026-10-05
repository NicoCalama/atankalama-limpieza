<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Url;
use Atankalama\Limpieza\Helpers\Fechas;
use Atankalama\Limpieza\Models\Habitacion;

/**
 * Inspección pre-entrega (v6.18, pedido de gerencia 04/10/2026; «visión cliente»; en código
 * «revision_entrega»): Recepción revisa una pieza antes de entregársela al huésped y registra SÍ o NO,
 * desde la tarjeta de la pieza en Habitaciones. No es obligatoria; cualquier pieza, en cualquier estado.
 *
 * Un NO lleva un motivo del catálogo (+ observaciones y foto opcionales) y avisa a las supervisoras
 * (notificación + push; no alertas_activas: su CHECK está cerrado en prod). Qué más hace un NO lo
 * decide el interruptor de Ajustes (alertas_config 'revision_entrega_no_ensucia'):
 *   - apagado (default): nada más; la pieza no cambia de estado y no se toca Cloudbeds.
 *   - prendido: si la pieza está aprobada, vuelve a 'sucia' (cambiarEstado) y se avisa 'dirty' a
 *     Cloudbeds, igual que «Marcar sucia» a mano.
 * Nunca escribe auditorias. Cada revisión guarda una foto del momento (estado de la pieza, última
 * limpieza y su inspección) para el KPI de calidad de las supervisoras, que se arma después.
 * Ver docs/revision-entrega.md.
 */
final class RevisionEntregaService
{
    public const RESULTADO_SI = 'si';
    public const RESULTADO_NO = 'no';
    public const NOTIF_TIPO = 'revision_entrega_no';
    public const SUBCARPETA_FOTO = 'revision-entrega';
    public const CLAVE_CONFIG_NO_ENSUCIA = 'revision_entrega_no_ensucia';

    // DEFAULT APLICADO (plan v6.18 aprobado por el usuario): topes de texto y del historial de Reportes.
    public const MAX_COMENTARIO = 300;
    public const MAX_NOMBRE_MOTIVO = 60;
    public const MAX_HISTORIAL = 500;
    private const MAX_IDEMPOTENCY_KEY = 64;

    public function __construct(
        private readonly HabitacionService $habitaciones = new HabitacionService(),
        private readonly PushService $push = new PushService(),
        private readonly ?CloudbedsSyncService $cloudbeds = null,
    ) {
    }

    // ───────────────────────────── Interruptor ─────────────────────────────

    /** ¿Un NO devuelve la pieza aprobada a sucia? Sin fila = apagado. */
    public function noEnsucia(): bool
    {
        $valor = Database::fetchColumn(
            'SELECT valor FROM #__alertas_config WHERE clave = ?',
            [self::CLAVE_CONFIG_NO_ENSUCIA]
        );
        return $valor === '1';
    }

    public function configurarNoEnsucia(bool $prendido, int $usuarioId): void
    {
        Database::execute(
            'INSERT INTO #__alertas_config (clave, valor, descripcion, updated_at, updated_by) VALUES (?, ?, ?, ?, ?) '
            . Database::onConflictUpdate(['clave'], ['valor', 'updated_at', 'updated_by']),
            [
                self::CLAVE_CONFIG_NO_ENSUCIA,
                $prendido ? '1' : '0',
                'Un NO de la inspección pre-entrega devuelve la pieza aprobada a sucia (1) o solo avisa (0)',
                Database::now(),
                $usuarioId,
            ]
        );
        Logger::audit($usuarioId, 'revision_entrega.config_actualizar', 'alertas_config', null, [
            'no_ensucia' => $prendido,
        ]);
    }

    // ───────────────────────────── Tarjetas de Habitaciones y formulario ─────────────────────────────

    /**
     * Revisión VIGENTE de cada pieza (habitacion_id => revisión): la última, mientras la pieza no haya
     * cambiado de estado después. La usa la lista de Habitaciones para pintar el botón de cada tarjeta.
     *
     * Se reinicia como el estado de la pieza, no a medianoche (decisión de Nicolás, 05/10/2026): cuando
     * la pieza se ensucia (p. ej. el sync de la noche marca sucias las ocupadas), empiezan a limpiarla o la
     * vuelven a aprobar, el resultado anterior ya no aplica y el botón vuelve a «Inspección pre-entrega».
     * Un NO sin resolver no se pierde a medianoche. El cambio a sucia que provoca el mismo NO (interruptor
     * prendido) no cuenta: se hace ANTES de guardar la revisión (ver registrar()).
     *
     * Dos condiciones, porque no todo cambio de estado deja rastro igual:
     *   1. La pieza sigue en el estado en que quedó al revisarla (estado_pieza, o 'sucia' si ese NO la
     *      devolvió a sucia). Cubre los cambios que no pasan por cambiarEstado() (la (re)asignación y la
     *      desasignación de AsignacionService la ponen 'sucia' con un UPDATE directo, sin audit_log).
     *   2. Su último 'habitacion.cambiar_estado' del audit_log no es posterior a la revisión. Cubre las
     *      idas y vueltas (aprobada → sucia → … → aprobada de nuevo). Se busca solo el último cambio de
     *      cada pieza (índice entidad/entidad_id, de atrás hacia adelante), así no recorre todo el historial.
     *      Las dos fechas salen del reloj de la base (created_at por defecto); el empate de milisegundo con
     *      el cambio que provocó la propia revisión cuenta como anterior.
     *
     * @return array<int, array<string, mixed>>
     */
    public function revisionesVigentes(): array
    {
        $filas = Database::fetchAll(
            self::SELECT_REVISION . "
               JOIN (SELECT habitacion_id, MAX(id) AS id
                       FROM #__revisiones_entrega
                      GROUP BY habitacion_id) ult ON ult.id = r.id
              WHERE ((r.paso_a_sucia = 1 AND h.estado = 'sucia')
                     OR (r.paso_a_sucia = 0 AND h.estado = r.estado_pieza))
                AND COALESCE((SELECT al.created_at
                                FROM #__audit_log al
                               WHERE al.entidad = 'habitacion' AND al.entidad_id = r.habitacion_id
                                 AND al.accion = 'habitacion.cambiar_estado'
                               ORDER BY al.id DESC
                               LIMIT 1), '') <= r.created_at"
        );
        $porPieza = [];
        foreach ($filas as $fila) {
            $porPieza[(int) $fila['habitacion_id']] = $this->mapear($fila);
        }
        return $porPieza;
    }

    /**
     * Lo que necesita el formulario del NO: motivos activos y si un NO devuelve la pieza a sucia
     * (para el aviso de la ventana).
     *
     * @return array{motivos: list<array{id: int, nombre: string, activo: bool}>, no_ensucia: bool}
     */
    public function formulario(): array
    {
        return ['motivos' => $this->listarMotivos(true), 'no_ensucia' => $this->noEnsucia()];
    }

    /** @return array<string, mixed>|null */
    public function buscarPorClave(string $idempotencyKey): ?array
    {
        $key = $this->normalizarClave($idempotencyKey);
        if ($key === null) {
            return null;
        }
        $id = Database::fetchColumn('SELECT id FROM #__revisiones_entrega WHERE idempotency_key = ?', [$key]);
        return $id === false || $id === null ? null : $this->obtener((int) $id);
    }

    /**
     * La revisión ya guardada con esta clave, SOLO si este envío es un reintento de ella: misma pieza,
     * misma respuesta y sigue siendo la última revisión de esa pieza. Si no (la clave quedó pegada de un
     * envío viejo, o ya hay una revisión más nueva), devuelve null y el envío cuenta como revisión nueva.
     * Así una clave vieja nunca se «traga» un SÍ o un NO posterior.
     *
     * @return array<string, mixed>|null
     */
    public function reintentoVigente(string $idempotencyKey, int $habitacionId, string $resultado): ?array
    {
        $existente = $this->buscarPorClave($idempotencyKey);
        if ($existente === null
            || $existente['habitacion_id'] !== $habitacionId
            || $existente['resultado'] !== $resultado) {
            return null;
        }
        $masNueva = Database::fetchOne(
            'SELECT 1 FROM #__revisiones_entrega WHERE habitacion_id = ? AND id > ? LIMIT 1',
            [$habitacionId, $existente['id']]
        );
        return $masNueva === null ? $existente : null;
    }

    /**
     * Registra un SÍ o un NO. Idempotente por clave: un reintento con la misma clave devuelve la
     * revisión que ya quedó, sin duplicar fila, audit ni aviso (ver reintentoVigente()).
     *
     * @return array{revision: array<string, mixed>, repetida: bool}
     */
    public function registrar(
        int $habitacionId,
        string $resultado,
        ?int $motivoId,
        ?string $comentario,
        ?string $fotoRuta,
        int $usuarioId,
        ?string $idempotencyKey = null,
    ): array {
        $key = $idempotencyKey === null ? null : $this->normalizarClave($idempotencyKey);
        if ($key !== null) {
            $reintento = $this->reintentoVigente($key, $habitacionId, $resultado);
            if ($reintento !== null) {
                return ['revision' => $reintento, 'repetida' => true];
            }
            if ($this->buscarPorClave($key) !== null) {
                // La clave ya se usó para otra revisión: no es un reintento. Se guarda como nueva, sin clave
                // (el índice UNIQUE no deja repetirla).
                Logger::warning('revision_entrega', 'clave de idempotencia reusada para otra revisión: se ignora', [
                    'habitacion_id' => $habitacionId,
                    'resultado' => $resultado,
                ], $usuarioId);
                $key = null;
            }
        }

        $hab = Database::fetchOne(
            'SELECT h.id, h.numero, h.estado, h.cloudbeds_room_id, ho.codigo AS hotel_codigo
               FROM #__habitaciones h
               JOIN #__hoteles ho ON ho.id = h.hotel_id
              WHERE h.id = ? AND h.activa = 1 AND h.es_espacio_comun = 0',
            [$habitacionId]
        );
        if ($hab === null) {
            throw new RevisionEntregaException('HABITACION_NO_ENCONTRADA', 'No encontramos esa habitación.', 404);
        }
        // El estado de la pieza NO se compara: Recepción puede revisar cualquier pieza (decisión 2).

        if (!in_array($resultado, [self::RESULTADO_SI, self::RESULTADO_NO], true)) {
            throw new RevisionEntregaException('RESULTADO_INVALIDO', 'Elige SÍ o NO.', 400);
        }

        // El multipart manda los saltos de línea como CRLF y la pantalla los cuenta como 1: se normaliza
        // ANTES de medir, así el tope es el mismo que ve Recepción (y el que se guarda).
        $comentario = $comentario === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $comentario));
        if ($comentario === '') {
            $comentario = null;
        }
        if ($comentario !== null && mb_strlen($comentario) > self::MAX_COMENTARIO) {
            throw new RevisionEntregaException(
                'COMENTARIO_LARGO',
                'Las observaciones pueden tener hasta ' . self::MAX_COMENTARIO . ' caracteres.',
                400
            );
        }

        $motivoNombre = null;
        if ($resultado === self::RESULTADO_NO) {
            if ($motivoId === null) {
                throw new RevisionEntregaException(
                    'MOTIVO_REQUERIDO',
                    'Elige el motivo por el que la pieza no se puede entregar.',
                    400
                );
            }
            $motivo = Database::fetchOne(
                'SELECT id, nombre FROM #__motivos_revision_entrega WHERE id = ? AND activo = 1',
                [$motivoId]
            );
            if ($motivo === null) {
                throw new RevisionEntregaException(
                    'MOTIVO_NO_ENCONTRADO',
                    'Ese motivo ya no está disponible. Elige otro.',
                    404
                );
            }
            $motivoNombre = (string) $motivo['nombre'];
        } else {
            // El SÍ no lleva motivo, comentario ni foto: si llegan, se ignoran.
            $motivoId = null;
            $comentario = null;
            $fotoRuta = null;
        }

        $estadoAntes = (string) $hab['estado'];
        $pasaASucia = $resultado === self::RESULTADO_NO
            && in_array($estadoAntes, Habitacion::ESTADOS_APROBADOS, true)
            && $this->noEnsucia();

        // Foto para el KPI de calidad de las supervisoras: qué limpieza (la última de la pieza) y qué
        // inspección (la de esa limpieza, si hubo) se está evaluando. Se guarda tal cual; la regla de qué
        // cuenta para el KPI se define cuando se arme (con estado_pieza al lado para filtrar).
        $foto = Database::fetchOne(
            'SELECT ec.id AS ejecucion_id, a.id AS auditoria_id
               FROM #__ejecuciones_checklist ec
          LEFT JOIN #__auditorias a ON a.ejecucion_id = ec.id
              WHERE ec.habitacion_id = ?
              ORDER BY ec.id DESC
              LIMIT 1',
            [$habitacionId]
        );
        $ejecucionId = $foto !== null ? (int) $foto['ejecucion_id'] : null;
        $auditoriaId = $foto !== null && $foto['auditoria_id'] !== null ? (int) $foto['auditoria_id'] : null;

        // El cambio a sucia (interruptor prendido) va ANTES de guardar la revisión y en la misma transacción:
        // así ese cambio queda antes que la revisión y no la «reinicia» (revisionesVigentes()), y si la
        // revisión no se puede guardar, la pieza tampoco cambia. created_at sale del reloj de la base, el
        // mismo que fecha el audit_log con el que se compara.
        try {
            $id = Database::transaction(function () use (
                $habitacionId, $usuarioId, $resultado, $motivoId, $comentario, $fotoRuta,
                $estadoAntes, $ejecucionId, $auditoriaId, $key, &$pasaASucia
            ): int {
                if ($pasaASucia) {
                    $pasaASucia = $this->pasarASucia($habitacionId, $usuarioId);
                }
                Database::execute(
                    'INSERT INTO #__revisiones_entrega
                        (habitacion_id, usuario_id, resultado, motivo_id, comentario, foto_ruta, paso_a_sucia,
                         estado_pieza, ejecucion_id, auditoria_id, idempotency_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $habitacionId, $usuarioId, $resultado, $motivoId, $comentario, $fotoRuta, $pasaASucia ? 1 : 0,
                        $estadoAntes, $ejecucionId, $auditoriaId, $key,
                    ]
                );
                return Database::lastInsertId();
            });
        } catch (\PDOException $e) {
            // Carrera del doble toque: dos intentos con la misma clave pasaron juntos el chequeo de
            // arriba. El índice UNIQUE frena al segundo (y su transacción deshace lo demás): se devuelve
            // la revisión que sí quedó. Mismo patrón que TicketService::crear().
            $mensaje = $e->getMessage();
            if ($key !== null
                && (stripos($mensaje, 'idempotency_key') !== false
                    || stripos($mensaje, 'UNIQUE') !== false
                    || stripos($mensaje, 'Duplicate') !== false)) {
                $existente = $this->reintentoVigente($key, $habitacionId, $resultado);
                if ($existente !== null) {
                    return ['revision' => $existente, 'repetida' => true];
                }
            }
            throw $e;
        }

        if ($pasaASucia) {
            $this->avisarDirtyACloudbeds($habitacionId, $hab['cloudbeds_room_id'] !== null);
        }

        Logger::audit($usuarioId, 'revision_entrega.registrar', 'habitacion', $habitacionId, [
            'revision_id' => $id,
            'hotel' => $hab['hotel_codigo'],
            'numero' => $hab['numero'],
            'resultado' => $resultado,
            'motivo' => $motivoNombre,
            'comentario' => $comentario,
            'foto' => $fotoRuta !== null,
            'estado_pieza' => $estadoAntes,
            'paso_a_sucia' => $pasaASucia,
        ]);

        if ($resultado === self::RESULTADO_NO) {
            try {
                $this->avisarSupervisoras($hab, (string) $motivoNombre, $comentario, $fotoRuta !== null, $pasaASucia, $usuarioId);
            } catch (\Throwable $e) {
                // La revisión ya quedó guardada: un aviso que falla nunca le devuelve error a Recepción.
                Logger::error('revision_entrega', 'no se pudo avisar a las supervisoras', [
                    'revision_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $revision = $this->obtener($id);
        if ($revision === null) {
            throw new RevisionEntregaException('ERROR_INTERNO', 'No pudimos guardar la revisión, intenta de nuevo.', 500);
        }
        return ['revision' => $revision, 'repetida' => false];
    }

    /**
     * Interruptor prendido: la pieza aprobada vuelve a sucia. Mismo camino que «Marcar sucia» a mano
     * (HabitacionesController::marcarSuciaManual). Devuelve si de verdad pasó a sucia.
     */
    private function pasarASucia(int $habitacionId, int $usuarioId): bool
    {
        try {
            $this->habitaciones->cambiarEstado($habitacionId, Habitacion::ESTADO_SUCIA, $usuarioId, 'ui');
            return true;
        } catch (HabitacionException $e) {
            // Alguien cambió el estado entre la lectura y acá: la revisión se guarda igual, sin pasar a sucia.
            Logger::warning('revision_entrega', 'el NO no pudo devolver la pieza a sucia', [
                'habitacion_id' => $habitacionId,
                'error' => $e->getMessage(),
            ], $usuarioId);
            return false;
        }
    }

    /**
     * Tras devolverla a sucia, se avisa 'dirty' a Cloudbeds (si no, el próximo sync la devolvería a
     * aprobada). Fuera de la transacción: es una llamada externa y best-effort.
     */
    private function avisarDirtyACloudbeds(int $habitacionId, bool $tieneCloudbeds): void
    {
        if (!$tieneCloudbeds || $this->cloudbeds === null) {
            return;
        }
        try {
            $actualizada = $this->habitaciones->obtener($habitacionId);
            if ($actualizada !== null) {
                $this->cloudbeds->escribirEstadoDirty($actualizada);
            }
        } catch (\Throwable) {
            // No crítico: escribirEstadoDirty ya loguea y levanta la alerta P0 si la escritura falla.
        }
    }

    /**
     * Aviso a quienes reciben las alertas operativas (permiso alertas.recibir_predictivas), sin el
     * usuario del cron ni quien registró el NO. Sin dedupe: cada NO es un evento.
     *
     * @param array<string, mixed> $hab
     */
    private function avisarSupervisoras(
        array $hab,
        string $motivo,
        ?string $comentario,
        bool $conFoto,
        bool $pasoASucia,
        int $actorId,
    ): void {
        $ids = array_map('intval', array_column(Database::fetchAll(
            "SELECT DISTINCT u.id
               FROM #__usuarios u
               JOIN #__usuarios_roles ur ON ur.usuario_id = u.id
               JOIN #__rol_permisos rp ON rp.rol_id = ur.rol_id
              WHERE rp.permiso_codigo = 'alertas.recibir_predictivas'
                AND u.activo = 1
                AND u.rut <> 'SISTEMA-CRON'
                AND u.id <> ?",
            [$actorId]
        ), 'id'));
        if ($ids === []) {
            return;
        }

        $actor = (string) Database::fetchColumn('SELECT nombre FROM #__usuarios WHERE id = ?', [$actorId]);
        $primerNombre = explode(' ', trim($actor))[0];
        $hotel = $hab['hotel_codigo'] === 'inn' ? 'INN' : 'Atankalama';

        $titulo = "Hab. {$hab['numero']} ({$hotel}): pre-entrega no aprobada";
        $cuerpo = $motivo
            . ($comentario !== null ? ': «' . rtrim(mb_substr($comentario, 0, 140), '. ') . '»' : '')
            . ($primerNombre !== '' ? ". Revisó {$primerNombre} a las " . date('H:i') . '.' : '.')
            . ($conFoto ? ' Hay foto.' : '')
            . ($pasoASucia ? ' La pieza volvió a sucia: asígnala desde Asignaciones.' : '');

        $this->push->notificar($ids, $titulo, $cuerpo, "/habitaciones/{$hab['id']}", [], true, self::NOTIF_TIPO);
    }

    // ───────────────────────────── Lectura ─────────────────────────────

    private const SELECT_REVISION = 'SELECT r.*, m.nombre AS motivo_nombre, m.activo AS motivo_activo,
                   u.nombre AS usuario_nombre, h.numero, ho.codigo AS hotel_codigo
              FROM #__revisiones_entrega r
              JOIN #__habitaciones h ON h.id = r.habitacion_id
              JOIN #__hoteles ho ON ho.id = h.hotel_id
              JOIN #__usuarios u ON u.id = r.usuario_id
         LEFT JOIN #__motivos_revision_entrega m ON m.id = r.motivo_id';

    /** @return array<string, mixed>|null */
    public function obtener(int $id): ?array
    {
        $fila = Database::fetchOne(self::SELECT_REVISION . ' WHERE r.id = ?', [$id]);
        return $fila === null ? null : $this->mapear($fila);
    }

    /**
     * Última revisión de la pieza (de cualquier día). La muestra el detalle de la habitación.
     *
     * @return array<string, mixed>|null
     */
    public function ultimaDePieza(int $habitacionId): ?array
    {
        $fila = Database::fetchOne(
            self::SELECT_REVISION . ' WHERE r.habitacion_id = ? ORDER BY r.id DESC LIMIT 1',
            [$habitacionId]
        );
        return $fila === null ? null : $this->mapear($fila);
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function mapear(array $f): array
    {
        $creado = (string) $f['created_at'];
        $foto = $f['foto_ruta'] !== null && $f['foto_ruta'] !== '' ? (string) $f['foto_ruta'] : null;
        return [
            'id' => (int) $f['id'],
            'habitacion_id' => (int) $f['habitacion_id'],
            'numero' => (string) $f['numero'],
            'hotel_codigo' => (string) $f['hotel_codigo'],
            'resultado' => (string) $f['resultado'],
            'motivo_id' => $f['motivo_id'] !== null ? (int) $f['motivo_id'] : null,
            'motivo_nombre' => $f['motivo_nombre'] !== null ? (string) $f['motivo_nombre'] : null,
            'comentario' => $f['comentario'] !== null ? (string) $f['comentario'] : null,
            'foto_url' => $foto !== null ? Url::a('/uploads/' . $foto) : null,
            // MariaDB devuelve TINYINT como string: "0" es truthy en JS.
            'paso_a_sucia' => ((int) $f['paso_a_sucia']) === 1,
            'estado_pieza' => (string) $f['estado_pieza'],
            'usuario_id' => (int) $f['usuario_id'],
            'usuario_nombre' => (string) $f['usuario_nombre'],
            'created_at' => $creado,
            'fecha_local' => Fechas::fechaLocalDeUtc($creado),
            'hora_local' => Fechas::horaMinutoLocalDeUtc($creado),
        ];
    }

    // ───────────────────────────── Reportes ─────────────────────────────

    /**
     * Sección «Inspección pre-entrega» de Reportes. Lee solo revisiones_entrega: ReportesService no
     * la conoce y ningún KPI cambia.
     *
     * @return array<string, mixed>
     */
    public function reporte(string $desde, string $hasta, string $hotel): array
    {
        [$d, $h] = Fechas::rangoUtc($desde, $hasta);
        $where = 'r.created_at >= ? AND r.created_at < ?';
        $params = [$d, $h];
        if ($hotel !== 'ambos') {
            $where .= ' AND ho.codigo = ?';
            $params[] = $hotel;
        }
        $base = 'FROM #__revisiones_entrega r
                 JOIN #__habitaciones h ON h.id = r.habitacion_id
                 JOIN #__hoteles ho ON ho.id = h.hotel_id';

        $tot = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN r.resultado = 'si' THEN 1 ELSE 0 END) AS cant_si,
                    SUM(CASE WHEN r.resultado = 'no' THEN 1 ELSE 0 END) AS cant_no,
                    SUM(CASE WHEN r.paso_a_sucia = 1 THEN 1 ELSE 0 END) AS cant_sucia
               {$base} WHERE {$where}",
            $params
        ) ?? [];
        $total = (int) ($tot['total'] ?? 0);
        $no = (int) ($tot['cant_no'] ?? 0);

        $porMotivo = array_map(static fn(array $f): array => [
            'motivo_id' => (int) $f['motivo_id'],
            'nombre' => (string) $f['nombre'],
            'activo' => ((int) $f['activo']) === 1,
            'cantidad' => (int) $f['cantidad'],
            'pct' => $no > 0 ? round(((int) $f['cantidad']) * 100 / $no, 1) : null,
        ], Database::fetchAll(
            "SELECT m.id AS motivo_id, m.nombre, m.activo, COUNT(*) AS cantidad
               {$base}
               JOIN #__motivos_revision_entrega m ON m.id = r.motivo_id
              WHERE {$where} AND r.resultado = 'no'
              GROUP BY m.id, m.nombre, m.activo
              ORDER BY cantidad DESC, LOWER(m.nombre)",
            $params
        ));

        $filas = Database::fetchAll(
            self::SELECT_REVISION . " WHERE {$where} ORDER BY r.created_at DESC, r.id DESC LIMIT " . (self::MAX_HISTORIAL + 1),
            $params
        );
        $truncado = count($filas) > self::MAX_HISTORIAL;
        $historial = array_map(fn(array $f): array => $this->mapear($f), array_slice($filas, 0, self::MAX_HISTORIAL));

        return [
            'resumen' => [
                'total' => $total,
                'si' => (int) ($tot['cant_si'] ?? 0),
                'no' => $no,
                'pct_no' => $total > 0 ? round($no * 100 / $total, 1) : null,
                'a_sucia' => (int) ($tot['cant_sucia'] ?? 0),
            ],
            'por_motivo' => $porMotivo,
            'historial' => $historial,
            'truncado' => $truncado,
        ];
    }

    // ───────────────────────────── Catálogo de motivos ─────────────────────────────

    /**
     * DEFAULT APLICADO (plan v6.18 aprobado por el usuario): orden alfabético, sin reordenar.
     *
     * @return list<array{id: int, nombre: string, activo: bool}>
     */
    public function listarMotivos(bool $soloActivos = true): array
    {
        $sql = 'SELECT id, nombre, activo FROM #__motivos_revision_entrega'
            . ($soloActivos ? ' WHERE activo = 1' : '')
            . ' ORDER BY LOWER(nombre)';
        return array_map(static fn(array $f): array => [
            'id' => (int) $f['id'],
            'nombre' => (string) $f['nombre'],
            'activo' => ((int) $f['activo']) === 1,
        ], Database::fetchAll($sql));
    }

    public function crearMotivo(string $nombre, int $usuarioId): int
    {
        $nombre = $this->validarNombreMotivo($nombre);
        $this->exigirNombreLibre($nombre, null);
        try {
            Database::execute('INSERT INTO #__motivos_revision_entrega (nombre) VALUES (?)', [$nombre]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
                throw new RevisionEntregaException('MOTIVO_DUPLICADO', 'Ya existe un motivo con ese nombre.', 409);
            }
            throw $e;
        }
        $id = Database::lastInsertId();
        Logger::audit($usuarioId, 'revision_entrega.motivo_crear', 'motivo_revision_entrega', $id, ['nombre' => $nombre]);
        return $id;
    }

    /**
     * Renombrar y/o activar-desactivar. No hay borrado: un motivo con revisiones queda en el historial.
     *
     * @param array{nombre?: string, activo?: bool} $datos
     */
    public function actualizarMotivo(int $id, array $datos, int $usuarioId): void
    {
        $existente = Database::fetchOne('SELECT id FROM #__motivos_revision_entrega WHERE id = ?', [$id]);
        if ($existente === null) {
            throw new RevisionEntregaException('MOTIVO_NO_ENCONTRADO', 'No encontramos ese motivo.', 404);
        }
        $sets = [];
        $params = [];
        if (array_key_exists('nombre', $datos)) {
            $nombre = $this->validarNombreMotivo((string) $datos['nombre']);
            $this->exigirNombreLibre($nombre, $id);
            $sets[] = 'nombre = ?';
            $params[] = $nombre;
        }
        if (array_key_exists('activo', $datos)) {
            $sets[] = 'activo = ?';
            $params[] = $datos['activo'] ? 1 : 0;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        try {
            Database::execute('UPDATE #__motivos_revision_entrega SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
                throw new RevisionEntregaException('MOTIVO_DUPLICADO', 'Ya existe un motivo con ese nombre.', 409);
            }
            throw $e;
        }
        Logger::audit($usuarioId, 'revision_entrega.motivo_actualizar', 'motivo_revision_entrega', $id, $datos);
    }

    private function validarNombreMotivo(string $nombre): string
    {
        $nombre = trim($nombre);
        $largo = mb_strlen($nombre);
        if ($largo < 2 || $largo > self::MAX_NOMBRE_MOTIVO) {
            throw new RevisionEntregaException(
                'NOMBRE_INVALIDO',
                'El motivo debe tener entre 2 y ' . self::MAX_NOMBRE_MOTIVO . ' caracteres.',
                400
            );
        }
        return $nombre;
    }

    /**
     * UNIQUE distingue mayúsculas en SQLite y no en MariaDB, y LOWER() de SQLite no baja la «Ñ» ni
     * las tildes: se compara en PHP con mb_strtolower (el catálogo son unas pocas filas).
     */
    private function exigirNombreLibre(string $nombre, ?int $excepto): void
    {
        $buscado = mb_strtolower($nombre);
        foreach (Database::fetchAll('SELECT id, nombre FROM #__motivos_revision_entrega') as $fila) {
            if ((int) $fila['id'] !== $excepto && mb_strtolower((string) $fila['nombre']) === $buscado) {
                throw new RevisionEntregaException('MOTIVO_DUPLICADO', 'Ya existe un motivo con ese nombre.', 409);
            }
        }
    }

    private function normalizarClave(string $key): ?string
    {
        $key = trim($key);
        return $key === '' || strlen($key) > self::MAX_IDEMPOTENCY_KEY ? null : $key;
    }
}
