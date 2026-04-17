<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Copilot;

use Atankalama\Limpieza\Models\Usuario;

/**
 * Catálogo de tools del copilot, filtradas dinámicamente por permisos del usuario.
 *
 * Cada tool define: nombre, descripción, esquema de input (JSON Schema),
 * permisos requeridos y si es nivel 2 (acción).
 */
final class CopilotToolRegistry
{
    /**
     * @return list<array{name:string,description:string,input_schema:array,permisos:list<string>,nivel2:bool}>
     */
    public static function catalogo(): array
    {
        return [
            // --- Nivel 1: consultas ---
            [
                'name' => 'listar_mis_habitaciones',
                'description' => 'Lista las habitaciones asignadas al usuario actual para hoy.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
                'permisos' => ['habitaciones.ver_asignadas_propias'],
                'nivel2' => false,
            ],
            [
                'name' => 'listar_habitaciones_hotel',
                'description' => 'Lista todas las habitaciones de un hotel con su estado actual.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hotel' => ['type' => 'string', 'description' => 'Código del hotel: 1_sur, inn o ambos', 'enum' => ['1_sur', 'inn', 'ambos']],
                    ],
                    'required' => ['hotel'],
                ],
                'permisos' => ['habitaciones.ver_todas'],
                'nivel2' => false,
            ],
            [
                'name' => 'listar_alertas_activas',
                'description' => 'Lista las alertas activas ordenadas por prioridad.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hotel' => ['type' => 'string', 'description' => 'Filtro opcional por hotel', 'enum' => ['1_sur', 'inn']],
                    ],
                    'required' => [],
                ],
                'permisos' => ['alertas.recibir_predictivas'],
                'nivel2' => false,
            ],
            [
                'name' => 'listar_tickets',
                'description' => 'Lista tickets de mantenimiento, opcionalmente filtrados por estado.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'estado' => ['type' => 'string', 'description' => 'Filtro por estado', 'enum' => ['abierto', 'en_progreso', 'resuelto', 'cerrado']],
                    ],
                    'required' => [],
                ],
                'permisos' => ['tickets.ver_propios'],
                'nivel2' => false,
            ],
            [
                'name' => 'ver_estado_equipo',
                'description' => 'Muestra el estado del equipo: trabajadores en turno, habitaciones completadas vs pendientes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'fecha' => ['type' => 'string', 'description' => 'Fecha YYYY-MM-DD (default hoy)'],
                    ],
                    'required' => [],
                ],
                'permisos' => ['turnos.ver'],
                'nivel2' => false,
            ],
            // --- Nivel 2: acciones ---
            [
                'name' => 'asignar_habitacion',
                'description' => 'Asigna una habitación a un trabajador específico.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'habitacion_id' => ['type' => 'integer', 'description' => 'ID de la habitación'],
                        'usuario_id' => ['type' => 'integer', 'description' => 'ID del trabajador'],
                    ],
                    'required' => ['habitacion_id', 'usuario_id'],
                ],
                'permisos' => ['asignaciones.asignar_manual'],
                'nivel2' => true,
            ],
            [
                'name' => 'crear_ticket',
                'description' => 'Crea un ticket de mantenimiento para una habitación.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'hotel_id' => ['type' => 'integer', 'description' => 'ID del hotel'],
                        'titulo' => ['type' => 'string', 'description' => 'Título breve del problema'],
                        'descripcion' => ['type' => 'string', 'description' => 'Descripción del problema'],
                        'prioridad' => ['type' => 'string', 'enum' => ['baja', 'normal', 'alta', 'urgente']],
                        'habitacion_id' => ['type' => 'integer', 'description' => 'ID de la habitación (opcional)'],
                    ],
                    'required' => ['hotel_id', 'titulo', 'descripcion'],
                ],
                'permisos' => ['tickets.crear'],
                'nivel2' => true,
            ],
            [
                'name' => 'completar_habitacion',
                'description' => 'Marca una habitación como terminada (completar limpieza).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'habitacion_id' => ['type' => 'integer', 'description' => 'ID de la habitación'],
                    ],
                    'required' => ['habitacion_id'],
                ],
                'permisos' => ['habitaciones.marcar_completada'],
                'nivel2' => true,
            ],
        ];
    }

    /**
     * Filtra las tools disponibles para un usuario según sus permisos.
     * Si no tiene copilot.usar_nivel_2_acciones, excluye tools nivel 2.
     *
     * @return list<array{name:string,description:string,input_schema:array}>
     */
    public static function toolsParaUsuario(Usuario $usuario): array
    {
        $nivel2Habilitado = $usuario->tienePermiso('copilot.usar_nivel_2_acciones');
        $resultado = [];

        foreach (self::catalogo() as $tool) {
            if ($tool['nivel2'] && !$nivel2Habilitado) {
                continue;
            }
            $tienePermisos = true;
            foreach ($tool['permisos'] as $p) {
                if (!$usuario->tienePermiso($p)) {
                    $tienePermisos = false;
                    break;
                }
            }
            if ($tienePermisos) {
                $resultado[] = [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['input_schema'],
                ];
            }
        }

        return $resultado;
    }

    /**
     * Busca la definición de una tool por nombre.
     * @return array{name:string,description:string,input_schema:array,permisos:list<string>,nivel2:bool}|null
     */
    public static function buscarPorNombre(string $nombre): ?array
    {
        foreach (self::catalogo() as $tool) {
            if ($tool['name'] === $nombre) {
                return $tool;
            }
        }
        return null;
    }
}
