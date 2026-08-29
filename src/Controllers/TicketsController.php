<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Models\TicketAdjunto;
use Atankalama\Limpieza\Services\ImagenAdjuntoService;
use Atankalama\Limpieza\Services\ImagenException;
use Atankalama\Limpieza\Services\TicketException;
use Atankalama\Limpieza\Services\TicketService;

final class TicketsController
{
    /** Máximo de fotos por acción (crear o cerrar). Ver docs/tickets.md. */
    private const MAX_FOTOS = 3;

    public function __construct(
        private readonly TicketService $svc = new TicketService(),
        private readonly ImagenAdjuntoService $imagenes = new ImagenAdjuntoService(),
    ) {
    }

    public function listar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $filtros = [
            'hotel' => is_string($request->query['hotel'] ?? null) ? (string) $request->query['hotel'] : null,
            'estado' => is_string($request->query['estado'] ?? null) ? (string) $request->query['estado'] : null,
        ];
        if (!$request->usuario->tienePermiso('tickets.ver_todos')) {
            if (!$request->usuario->tienePermiso('tickets.ver_propios')) {
                return Response::error('PERMISO_INSUFICIENTE', 'No tienes permiso para ver tickets.', 403);
            }
            // Dos vistas para quien no gestiona (botones en tickets.php): "mios" (default) =
            // asignados a mí, no los que yo creé; "sin_asignar" = los que nadie ha tomado
            // todavía, para poder tomarlos ("todos los tickets pueden ser tomados por
            // cualquier persona"). Ver TicketService::listar().
            $alcance = is_string($request->query['alcance'] ?? null) ? (string) $request->query['alcance'] : 'mios';
            if ($alcance === 'sin_asignar') {
                $filtros['sin_asignar'] = true;
            } else {
                $filtros['asignado_a'] = $request->usuario->id;
            }
        }
        $tickets = $this->svc->listar($filtros);
        return Response::ok(['tickets' => $tickets, 'total' => count($tickets)]);
    }

    public function obtener(Request $request): Response
    {
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ticket_id inválido.', 400);
        }
        $ticket = $this->svc->obtener($id);
        if ($ticket === null) {
            return Response::error('TICKET_NO_ENCONTRADO', 'Ticket no encontrado.', 404);
        }
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        if (!$request->usuario->tienePermiso('tickets.ver_todos')
            && $ticket->levantadoPor !== $request->usuario->id
            && $ticket->asignadoA !== $request->usuario->id) {
            return Response::error('PERMISO_INSUFICIENTE', 'No puedes ver este ticket.', 403);
        }
        return Response::ok(['ticket' => $ticket->toArray(), 'adjuntos' => $this->svc->adjuntosDe($id)]);
    }

    /**
     * POST /api/tickets — multipart/form-data. Campos de texto igual que antes
     * (hotel_id, titulo, descripcion, prioridad, habitacion_id) + fotos[] opcional (máx. 3).
     * Si una foto falla al procesarse NO aborta la creación del ticket: es peor perder
     * el reporte completo por un problema de imagen. Las fallidas van en adjuntos_fallidos.
     */
    public function crear(Request $request): Response
    {
        // Instrumentación temporal (fase 0 del plan "eliminar No pudimos conectar con el
        // servidor"): sin esto vamos a ciegas sobre dónde se va el tiempo antes de responder.
        // Quitar una vez que fase 2 (cola offline) esté validada en producción.
        $t0 = microtime(true);

        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $hotelId = $request->inputInt('hotel_id');
        $titulo = $request->inputString('titulo');
        $descripcion = $request->inputString('descripcion');
        $prioridad = $request->inputString('prioridad', Ticket::PRIORIDAD_NORMAL);
        $habitacionId = $request->inputInt('habitacion_id');
        // Fase 1 del plan de idempotencia: UUID generado por el cliente, uno por intento de
        // envío (se reusa en los reintentos del mismo envío, no en un ticket nuevo). Formato
        // libre pero acotado — si viene vacío o absurdamente largo, se ignora (columna
        // VARCHAR(64) en MariaDB) y el ticket se crea igual, solo sin protección de reintento.
        $idempotencyKey = $request->inputString('idempotency_key', '');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            $idempotencyKey = null;
        }

        if ($hotelId === null || $titulo === '' || $descripcion === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'hotel_id, titulo y descripcion son requeridos.', 400);
        }

        $archivos = $this->normalizarArchivos($_FILES['fotos'] ?? null);
        if (count($archivos) > self::MAX_FOTOS) {
            return Response::error('DEMASIADAS_FOTOS', 'Máximo ' . self::MAX_FOTOS . ' fotos por ticket.', 400);
        }

        $tInsertar = microtime(true);
        try {
            $ticket = $this->svc->crear($hotelId, $titulo, $descripcion, $prioridad, $request->usuario->id, $habitacionId, $idempotencyKey);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        $msInsertar = (int) round((microtime(true) - $tInsertar) * 1000);

        $tFotos = microtime(true);
        // Si esto es un reintento (misma idempotency_key) y el intento anterior ya alcanzó
        // a subir sus fotos, NO volver a procesarlas — TicketService::crear() ya devolvió el
        // ticket existente sin tocarlo, pero procesarArchivos() no sabe de idempotencia y
        // crearía adjuntos duplicados si lo dejamos correr de nuevo con los mismos archivos.
        $yaTieneFotosDeCreacion = $idempotencyKey !== null && count(array_filter(
            $this->svc->adjuntosDe($ticket->id),
            static fn(array $a): bool => $a['contexto'] === TicketAdjunto::CONTEXTO_CREACION
        )) > 0;
        $fallidas = $yaTieneFotosDeCreacion
            ? []
            : $this->procesarArchivos($archivos, $ticket->id, TicketAdjunto::CONTEXTO_CREACION, $request->usuario->id);
        $msFotos = (int) round((microtime(true) - $tFotos) * 1000);

        // Diferida (ver deferir()): notificarCreacionANovedades hace un curl síncrono con
        // hasta 20s de timeout (NovedadesSyncService::enviar) — el trabajador no debe
        // esperar eso para recibir la confirmación del ticket. Sigue incluyendo las fotos
        // recién procesadas igual que antes, solo corre después de responder.
        $ticketId = $ticket->id;
        $this->deferir(function () use ($ticketId): void {
            $this->svc->notificarCreacionANovedades($ticketId);
        });

        $datosTiming = [
            'ticket_id' => $ticket->id,
            'cantidad_fotos' => count($archivos),
            'ms_insertar' => $msInsertar,
            'ms_procesar_fotos' => $msFotos,
            'ms_total_antes_de_responder' => (int) round((microtime(true) - $t0) * 1000),
        ];
        Logger::info('tickets_timing', 'crear', $datosTiming);

        return Response::ok([
            'ticket' => $ticket->toArray(),
            'adjuntos' => $this->svc->adjuntosDe($ticket->id),
            'adjuntos_fallidos' => $fallidas,
        ], 201);
    }

    /**
     * POST /api/tickets/{id}/cerrar — multipart/form-data. fotos[] opcional (máx. 3),
     * evidencia de la solución. Mismo permiso que cambiarEstado (tickets.ver_todos).
     */
    public function cerrar(Request $request): Response
    {
        // Instrumentación temporal — ver comentario igual en crear().
        $t0 = microtime(true);

        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('ID_INVALIDO', 'ticket_id inválido.', 400);
        }

        $archivos = $this->normalizarArchivos($_FILES['fotos'] ?? null);
        if (count($archivos) > self::MAX_FOTOS) {
            return Response::error('DEMASIADAS_FOTOS', 'Máximo ' . self::MAX_FOTOS . ' fotos por ticket.', 400);
        }

        try {
            $ticket = $this->svc->cambiarEstado($id, Ticket::ESTADO_CERRADO, $request->usuario->id);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }

        $tFotos = microtime(true);
        $fallidas = $this->procesarArchivos($archivos, $id, TicketAdjunto::CONTEXTO_CIERRE, $request->usuario->id);
        $msFotos = (int) round((microtime(true) - $tFotos) * 1000);

        // Diferida — mismo motivo que en crear(). Ver deferir().
        $this->deferir(function () use ($id): void {
            $this->svc->notificarCierreANovedades($id);
        });

        $datosTiming = [
            'ticket_id' => $id,
            'cantidad_fotos' => count($archivos),
            'ms_procesar_fotos' => $msFotos,
            'ms_total_antes_de_responder' => (int) round((microtime(true) - $t0) * 1000),
        ];
        Logger::info('tickets_timing', 'cerrar', $datosTiming);

        return Response::ok([
            'ticket' => $ticket->toArray(),
            'adjuntos' => $this->svc->adjuntosDe($id),
            'adjuntos_fallidos' => $fallidas,
        ]);
    }

    public function usuariosAsignables(Request $request): Response
    {
        return Response::ok(['usuarios' => $this->svc->usuariosActivos()]);
    }

    public function asignar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        $usuarioId = $request->inputInt('usuario_id');
        if ($id === null || $usuarioId === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'id y usuario_id son requeridos.', 400);
        }

        // Quien gestiona (tickets.ver_todos) puede asignar cualquier ticket a cualquier
        // persona. Quien solo tiene tickets.ver_propios únicamente puede "tomar" — autoasignarse
        // (usuario_id = él mismo) un ticket que todavía no tiene dueño. Sin este chequeo fino,
        // "todos los tickets pueden ser tomados por cualquier persona" no seria posible (el
        // router ya no filtra por tickets.ver_todos, ver Kernel.php).
        $puedeGestionarTodo = $request->usuario->tienePermiso('tickets.ver_todos');
        if (!$puedeGestionarTodo) {
            if (!$request->usuario->tienePermiso('tickets.ver_propios') || $usuarioId !== $request->usuario->id) {
                return Response::error('PERMISO_INSUFICIENTE', 'No tienes permiso para asignar este ticket.', 403);
            }
            $ticketActual = $this->svc->obtener($id);
            if ($ticketActual === null) {
                return Response::error('TICKET_NO_ENCONTRADO', 'Ticket no encontrado.', 404);
            }
            if ($ticketActual->asignadoA !== null) {
                return Response::error('YA_ASIGNADO', 'Este ticket ya tiene un responsable.', 409);
            }
        }

        try {
            $ticket = $this->svc->asignar($id, $usuarioId, $request->usuario->id);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ticket' => $ticket->toArray()]);
    }

    public function cambiarEstado(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        $estado = $request->inputString('estado');
        if ($id === null || $estado === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'id y estado son requeridos.', 400);
        }

        $ticketActual = $this->svc->obtener($id);
        if ($ticketActual === null) {
            return Response::error('TICKET_NO_ENCONTRADO', 'Ticket no encontrado.', 404);
        }

        // Quien gestiona (tickets.ver_todos) puede cualquier transición. Quien solo tiene el
        // ticket asignado a sí mismo puede pasarlo a 'en_progreso' (lo toma, ver tomar() en
        // tickets.php) o 'resuelto' (hizo el trabajo) — pero cerrar (con evidencia) o reabrir
        // queda para quien gestiona.
        $puedeGestionarTodo = $request->usuario->tienePermiso('tickets.ver_todos');
        $esSuTicketAsignado = !$puedeGestionarTodo
            && $ticketActual->asignadoA === $request->usuario->id
            && in_array($estado, [Ticket::ESTADO_EN_PROGRESO, Ticket::ESTADO_RESUELTO], true);
        if (!$puedeGestionarTodo && !$esSuTicketAsignado) {
            return Response::error('PERMISO_INSUFICIENTE', 'No tienes permiso para cambiar el estado de este ticket.', 403);
        }

        try {
            $ticket = $this->svc->cambiarEstado($id, $estado, $request->usuario->id);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        // Cubre el cierre sin evidencia (este endpoint no sube fotos) — notificarCierreANovedades()
        // no hace nada si el estado resultante no quedó en 'cerrado'. Diferida, ver deferir().
        $this->deferir(function () use ($id): void {
            $this->svc->notificarCierreANovedades($id);
        });

        return Response::ok(['ticket' => $ticket->toArray()]);
    }

    /**
     * Corre $fn DESPUÉS de que el cliente ya recibió la respuesta HTTP completa
     * (fastcgi_finish_request cierra la conexión apenas se termina de emitir el body).
     * Usado para la notificación a Novedades (NovedadesSyncService::enviar): es un curl
     * síncrono con hasta 20s de timeout (15s + 5s de conexión) que, si corre ANTES de
     * responder, deja al trabajador esperando esos 20s en una conexión de hotel/celular
     * inestable — tiempo de sobra para que el navegador tire la conexión con "No pudimos
     * conectar con el servidor" aunque el ticket se haya creado bien en el servidor.
     *
     * Si el hosting no soporta fastcgi_finish_request (no todos los entornos PHP-FPM lo
     * exponen), cae de vuelta al comportamiento síncrono de siempre — no rompe nada, solo
     * pierde la mejora.
     */
    private function deferir(callable $fn): void
    {
        ignore_user_abort(true);
        register_shutdown_function(function () use ($fn): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $fn();
        });
    }

    /**
     * Procesa cada archivo con ImagenAdjuntoService y lo persiste vía TicketService.
     * Una foto que falla NO interrumpe a las demás — se acumula en el resultado.
     *
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $archivos
     * @return list<array{nombre:string, motivo:string}>
     */
    private function procesarArchivos(array $archivos, int $ticketId, string $contexto, int $subidoPor): array
    {
        $fallidas = [];
        foreach ($archivos as $archivo) {
            if ($archivo['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                    throw new ImagenException('ARCHIVO_INVALIDO', 'No se pudo leer el archivo subido.');
                }
                $procesada = $this->imagenes->guardarComoWebp($archivo['tmp_name'], (int) $archivo['size']);
                $this->svc->agregarAdjunto(
                    $ticketId,
                    $procesada['ruta'],
                    $archivo['name'] !== '' ? $archivo['name'] : null,
                    $procesada['tamano_bytes'],
                    $subidoPor,
                    $contexto,
                );
            } catch (ImagenException $e) {
                $fallidas[] = ['nombre' => $archivo['name'], 'motivo' => $e->getMessage()];
            }
        }
        return $fallidas;
    }

    /**
     * Normaliza $_FILES['fotos'] (estructura multi-archivo bajo un mismo campo) a una
     * lista plana de entradas individuales, sin importar si vino uno o varios archivos.
     *
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private function normalizarArchivos(?array $filesFoto): array
    {
        if ($filesFoto === null || !isset($filesFoto['name'])) {
            return [];
        }
        if (!is_array($filesFoto['name'])) {
            return [$filesFoto];
        }
        $lista = [];
        foreach ($filesFoto['name'] as $i => $nombre) {
            $lista[] = [
                'name' => (string) $nombre,
                'type' => (string) ($filesFoto['type'][$i] ?? ''),
                'tmp_name' => (string) ($filesFoto['tmp_name'][$i] ?? ''),
                'error' => (int) ($filesFoto['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($filesFoto['size'][$i] ?? 0),
            ];
        }
        return $lista;
    }
}
