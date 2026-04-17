<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Ticket;
use Atankalama\Limpieza\Services\TicketException;
use Atankalama\Limpieza\Services\TicketService;

final class TicketsController
{
    public function __construct(
        private readonly TicketService $svc = new TicketService(),
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
            $filtros['levantado_por'] = $request->usuario->id;
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
            && $ticket->levantadoPor !== $request->usuario->id) {
            return Response::error('PERMISO_INSUFICIENTE', 'No puedes ver este ticket.', 403);
        }
        return Response::ok(['ticket' => $ticket->toArray()]);
    }

    public function crear(Request $request): Response
    {
        if ($request->usuario === null) {
            return Response::error('NO_AUTENTICADO', 'No autenticado.', 401);
        }
        $hotelId = $request->inputInt('hotel_id');
        $titulo = $request->inputString('titulo');
        $descripcion = $request->inputString('descripcion');
        $prioridad = $request->inputString('prioridad', Ticket::PRIORIDAD_NORMAL);
        $habitacionId = $request->inputInt('habitacion_id');

        if ($hotelId === null || $titulo === '' || $descripcion === '') {
            return Response::error('PARAMETROS_INVALIDOS', 'hotel_id, titulo y descripcion son requeridos.', 400);
        }

        try {
            $ticket = $this->svc->crear($hotelId, $titulo, $descripcion, $prioridad, $request->usuario->id, $habitacionId);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ticket' => $ticket->toArray()], 201);
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
        try {
            $ticket = $this->svc->cambiarEstado($id, $estado, $request->usuario->id);
        } catch (TicketException $e) {
            return Response::error($e->codigo, $e->getMessage(), $e->httpStatus);
        }
        return Response::ok(['ticket' => $ticket->toArray()]);
    }
}
