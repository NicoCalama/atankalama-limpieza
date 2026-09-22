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
        $alcance = is_string($request->query['alcance'] ?? null) ? (string) $request->query['alcance'] : null;
        if (!$request->usuario->tienePermiso('tickets.ver_todos')) {
            if (!$request->usuario->tienePermiso('tickets.ver_propios')) {
                return Response::error('PERMISO_INSUFICIENTE', 'No tienes permiso para ver tickets.', 403);
            }
            // Dos vistas para quien no gestiona (botones en tickets.php): "mios" (default) =
            // asignados a mí, no los que yo creé; "sin_asignar" = los que nadie ha tomado
            // todavía, para poder tomarlos ("todos los tickets pueden ser tomados por
            // cualquier persona"). Ver TicketService::listar().
            if (($alcance ?? 'mios') === 'sin_asignar') {
                $filtros['sin_asignar'] = true;
            } else {
                $filtros['asignado_a'] = $request->usuario->id;
            }
        } elseif ($alcance === 'sin_asignar') {
            // Quien gestiona (Supervisora/Recepción/Admin, mismo permiso tickets.ver_todos)
            // también puede filtrar por "sin asignar" — default de tickets.php para no
            // perder de vista los tickets que nadie ha tomado. Cualquier otro valor
            // ('todos', ausente) no filtra por asignación: se ve todo, como hasta ahora.
            $filtros['sin_asignar'] = true;
        } elseif ($alcance === 'mios') {
            // Roles con ver_todos que además ejecutan tickets (ej. Mantenimiento) también
            // quieren su "Asignados a mí" — ver alcanceFiltroGestion en tickets.php.
            $filtros['asignado_a'] = $request->usuario->id;
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
        if (!$this->puedeVerTicket($request, $ticket)) {
            return Response::error('PERMISO_INSUFICIENTE', 'No puedes ver este ticket.', 403);
        }
        return Response::ok(['ticket' => $ticket->toArray(), 'adjuntos' => $this->svc->adjuntosDe($id)]);
    }

    /**
     * GET /api/tickets/{id}/comentarios — mismo criterio de acceso que obtener(): dueño,
     * asignado, o tickets.ver_todos.
     */
    public function comentarios(Request $request): Response
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
        if (!$this->puedeVerTicket($request, $ticket)) {
            return Response::error('PERMISO_INSUFICIENTE', 'No puedes ver este ticket.', 403);
        }
        return Response::ok(['comentarios' => $this->svc->comentariosDe($id)]);
    }

    /**
     * POST /api/tickets/{id}/comentarios — mismo criterio de acceso que obtener(): quien
     * puede ver el ticket puede comentarlo. No exige un permiso aparte (decisión de
     * simplicidad, ver plan).
     */
    public function comentar(Request $request): Response
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
        if (!$this->puedeVerTicket($request, $ticket)) {
            return Response::error('PERMISO_INSUFICIENTE', 'No puedes comentar este ticket.', 403);
        }
        $comentario = $request->inputString('comentario', '');
        $avisar = (bool) $request->input('avisar_supervisora', false);
        try {
            $comentarios = $this->svc->comentar($id, $request->usuario->id, $comentario, $avisar);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['comentarios' => $comentarios]);
    }

    /** Dueño, asignado (individual o múltiple), o tickets.ver_todos — ver obtener(), comentarios() y comentar(). */
    private function puedeVerTicket(Request $request, Ticket $ticket): bool
    {
        if ($request->usuario === null) {
            return false;
        }
        if ($request->usuario->tienePermiso('tickets.ver_todos') || $ticket->levantadoPor === $request->usuario->id) {
            return true;
        }
        return self::esResponsable($ticket, $request->usuario->id);
    }

    /**
     * ¿Es uno de los responsables del ticket? asignado_a guarda solo el PRIMERO (compatibilidad);
     * la lista completa viene en $ticket->responsables (tabla tickets_asignados). Todo chequeo
     * de "es su ticket" debe pasar por acá — mirar solo asignado_a deja afuera al resto.
     */
    private static function esResponsable(Ticket $ticket, int $usuarioId): bool
    {
        if ($ticket->asignadoA === $usuarioId) {
            return true;
        }
        foreach ($ticket->responsables as $r) {
            if ($r['id'] === $usuarioId) {
                return true;
            }
        }
        return false;
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
        $prioridadSolicitada = $request->inputString('prioridad', Ticket::PRIORIDAD_NORMAL);
        // Igual que asignado_a abajo: fijar la prioridad al crear (no la de por defecto)
        // exige tickets.editar_prioridad. El resto de los roles reporta siempre en 'normal'
        // y el triage lo hace después quien tenga el permiso.
        $prioridad = $request->usuario->tienePermiso('tickets.editar_prioridad')
            ? $prioridadSolicitada
            : Ticket::PRIORIDAD_NORMAL;
        $habitacionId = $request->inputInt('habitacion_id');
        // Asignación inmediata al crear: soporta asignado_a (int), usuario_ids (array o csv), o grupo_rol (string)
        $asignados = [];
        if ($request->usuario->tienePermiso('tickets.ver_todos')) {
            $inputAsignados = $request->input('usuario_ids') ?? $request->input('asignados_a');
            if (is_array($inputAsignados)) {
                $asignados = array_values(array_filter(array_map('intval', $inputAsignados), static fn(int $id) => $id > 0));
            } elseif (is_string($inputAsignados) && $inputAsignados !== '') {
                $asignados = array_values(array_filter(array_map('intval', explode(',', $inputAsignados)), static fn(int $id) => $id > 0));
            } elseif (is_string($request->input('asignado_a')) && str_starts_with((string) $request->input('asignado_a'), 'grupo:')) {
                $asignados = $this->svc->obtenerIdsPorRol(substr((string) $request->input('asignado_a'), 6));
            } elseif ($request->inputString('grupo_rol') !== '') {
                $asignados = $this->svc->obtenerIdsPorRol($request->inputString('grupo_rol'));
            } elseif ($request->inputInt('asignado_a') !== null) {
                $asignados = [$request->inputInt('asignado_a')];
            }

            if (!$request->usuario->tienePermiso('tickets.asignar_a_cualquier_perfil')) {
                foreach ($asignados as $aid) {
                    if ($aid !== $request->usuario->id && !$this->svc->esTrabajador($aid)) {
                        return Response::error(
                            'PERFIL_NO_ASIGNABLE',
                            'Solo puedes asignar tickets a usuarios con perfil Trabajador.',
                            403
                        );
                    }
                }
            }
        }

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
            $ticket = $this->svc->crear(
                hotelId: $hotelId,
                titulo: $titulo,
                descripcion: $descripcion,
                prioridad: $prioridad,
                levantadoPor: $request->usuario->id,
                habitacionId: $habitacionId,
                idempotencyKey: $idempotencyKey,
                asignadoA: $asignados !== [] ? $asignados : null,
            );
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
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $soloTrabajadores = !$request->usuario->tienePermiso('tickets.asignar_a_cualquier_perfil');
        return Response::ok(['usuarios' => $this->svc->usuariosActivos($soloTrabajadores)]);
    }

    public function asignar(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        if ($id === null) {
            return Response::error('PARAMETROS_INVALIDOS', 'id de ticket requerido.', 400);
        }

        // Obtener IDs a asignar: soporta usuario_ids (array), usuario_id (int), o grupo_rol (string)
        $usuarioIds = [];
        $rawIds = $request->input('usuario_ids');
        if (is_array($rawIds)) {
            $usuarioIds = array_values(array_filter(array_map('intval', $rawIds), static fn(int $i) => $i > 0));
        } elseif ($request->inputInt('usuario_id') !== null) {
            $usuarioIds = [$request->inputInt('usuario_id')];
        } elseif ($request->inputString('grupo_rol') !== '') {
            $usuarioIds = $this->svc->obtenerIdsPorRol($request->inputString('grupo_rol'));
        }

        if ($usuarioIds === []) {
            return Response::error('PARAMETROS_INVALIDOS', 'Debes especificar al menos un responsable válido.', 400);
        }

        // Quien gestiona (tickets.ver_todos) puede asignar cualquier ticket a cualquier
        // persona o grupo. Quien solo tiene tickets.ver_propios únicamente puede "tomar" — autoasignarse
        // (usuarioIds = [él mismo]) un ticket que todavía no tiene dueño.
        $puedeGestionarTodo = $request->usuario->tienePermiso('tickets.ver_todos');
        $ticketActual = $this->svc->obtener($id);
        if ($ticketActual === null) {
            return Response::error('TICKET_NO_ENCONTRADO', 'Ticket no encontrado.', 404);
        }
        if (!$puedeGestionarTodo) {
            if (!$request->usuario->tienePermiso('tickets.ver_propios') || $usuarioIds !== [$request->usuario->id]) {
                return Response::error('PERMISO_INSUFICIENTE', 'No tienes permiso para asignar este ticket.', 403);
            }
            if ($ticketActual->responsables !== [] || $ticketActual->asignadoA !== null) {
                return Response::error('YA_ASIGNADO', 'Este ticket ya tiene un responsable asignado.', 409);
            }
        }

        // Designar a un tercero que NO es Trabajador exige tickets.asignar_a_cualquier_perfil
        // (solo Admin). Supervisora y Recepción asignan únicamente a personal de terreno.
        // Autoasignarse ("Tomar") queda exento: no es designar a otro. Solo se controla a los
        // que se AGREGAN: la lista llega completa, y los que ya eran responsables los designó
        // antes alguien con permiso (p. ej. un Admin a otro perfil).
        $idsActuales = array_map(static fn(array $r): int => $r['id'], $ticketActual->responsables);
        if ($ticketActual->asignadoA !== null) {
            $idsActuales[] = $ticketActual->asignadoA;
        }
        if (!$request->usuario->tienePermiso('tickets.asignar_a_cualquier_perfil')) {
            foreach (array_diff($usuarioIds, $idsActuales) as $uid) {
                if ($uid !== $request->usuario->id && !$this->svc->esTrabajador($uid)) {
                    return Response::error(
                        'PERFIL_NO_ASIGNABLE',
                        'Solo puedes asignar tickets a usuarios con perfil Trabajador.',
                        403
                    );
                }
            }
        }

        try {
            $ticket = $this->svc->asignar($id, $usuarioIds, $request->usuario->id);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ticket' => $ticket->toArray()]);
    }

    public function cambiarPrioridad(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $id = $request->rutaInt('id');
        $prioridad = $request->inputString('prioridad', '');
        if ($id === null || $prioridad === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'id y prioridad son requeridos.', 400);
        }
        try {
            $ticket = $this->svc->cambiarPrioridad($id, $prioridad, $request->usuario->id);
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

        // Quien gestiona (tickets.ver_todos) puede cualquier transición. Cualquiera de los
        // responsables del ticket (no solo el primero) puede pasarlo a 'en_progreso' (lo toma,
        // ver tomar() en tickets.js) o 'resuelto' (hizo el trabajo) — pero cerrar (con
        // evidencia) o reabrir queda para quien gestiona.
        $puedeGestionarTodo = $request->usuario->tienePermiso('tickets.ver_todos');
        $esSuTicketAsignado = !$puedeGestionarTodo
            && self::esResponsable($ticketActual, $request->usuario->id)
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
            // La respuesta ya salió: un error acá no tiene a quién mostrarse. Se registra en
            // vez de terminar como fatal del proceso.
            try {
                $fn();
            } catch (\Throwable $e) {
                Logger::error('tickets', 'Falló una tarea diferida: ' . $e->getMessage());
            }
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
