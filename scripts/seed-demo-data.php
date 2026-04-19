<?php

declare(strict_types=1);

/**
 * Seeder de datos de demo realistas para presentar el MVP.
 *
 * Pobla la BD con usuarios (trabajadoras, supervisoras, recepción), habitaciones,
 * turnos de la semana actual, asignaciones de HOY, ejecuciones de checklist en
 * varios estados, auditorías (3 veredictos) y tickets.
 *
 * Uso:
 *   php scripts/seed-demo-data.php            → idempotente (INSERT OR IGNORE)
 *   php scripts/seed-demo-data.php --reset    → limpia datos demo previos antes de sembrar
 *
 * Requiere que `scripts/seed.php` ya haya corrido (permisos, roles, catálogos, admin, templates).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Services\PasswordService;

/**
 * Orquesta el seed demo completo. Llamable desde CLI o desde tests.
 */
function ejecutarSeedDemo(bool $reset, bool $silencioso = false): void
{
    $log = static function (string $msg) use ($silencioso): void {
        if (!$silencioso) echo $msg;
    };

    $passwordService = new PasswordService();
    $hashDemo = $passwordService->hash('Demo1234!');

    mt_srand(42);

    $log("Seeding demo data" . ($reset ? " (con --reset)" : "") . "...\n\n");

    Database::transaction(function () use ($reset, $hashDemo, $log) {
        if ($reset) {
            resetearDatosDemo();
            $log("  [reset] datos demo previos eliminados\n");
        }

        $usuarios = seedUsuariosDemo($hashDemo, $log);
        $habitaciones = seedHabitacionesDemo($log);
        seedTurnosDemo($usuarios, $log);
        $asignaciones = seedAsignacionesDemo($usuarios, $habitaciones, $log);
        $ejecuciones = seedEjecucionesDemo($asignaciones, $log);
        seedAuditoriasDemo($usuarios, $ejecuciones, $log);
        seedTicketsDemo($usuarios, $habitaciones, $log);
    });

    $log("\nDemo data listos. Contraseña para todos los usuarios demo: Demo1234!\n");
}

// --- CLI entry point ---
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    Config::load(dirname(__DIR__));
    ejecutarSeedDemo(in_array('--reset', $argv, true));
}

// -----------------------------------------------------------------------------
// Reset
// -----------------------------------------------------------------------------

function resetearDatosDemo(): void
{
    // Orden importa por FK
    Database::execute('DELETE FROM tickets');
    Database::execute('DELETE FROM auditorias');
    Database::execute('DELETE FROM ejecuciones_items');
    Database::execute('DELETE FROM ejecuciones_checklist');
    Database::execute('DELETE FROM asignaciones');
    Database::execute('DELETE FROM usuarios_turnos');
    Database::execute('DELETE FROM habitaciones');
    // Usuarios: conservar admin original (rut 11111111-1)
    Database::execute("DELETE FROM usuarios_roles WHERE usuario_id IN (SELECT id FROM usuarios WHERE rut <> '11111111-1')");
    Database::execute("DELETE FROM contrasenas_temporales WHERE usuario_id IN (SELECT id FROM usuarios WHERE rut <> '11111111-1')");
    Database::execute("DELETE FROM usuarios WHERE rut <> '11111111-1'");
}

// -----------------------------------------------------------------------------
// Usuarios
// -----------------------------------------------------------------------------

/**
 * @param callable(string):void $log
 * @return array{trabajadoras: list<int>, supervisoras: list<int>, recepcion: list<int>}
 */
function seedUsuariosDemo(string $hashDemo, callable $log): array
{
    $trabajadoras = [
        ['Valentina Soto Araya',        'valentina.soto@atankalama.cl',    '18502341', '1_sur'],
        ['Camila Rojas Morales',        'camila.rojas@atankalama.cl',      '19234512', '1_sur'],
        ['Sofía Fernández Pino',        'sofia.fernandez@atankalama.cl',   '17834901', '1_sur'],
        ['María Pérez González',        'maria.perez@atankalama.cl',       '16543210', '1_sur'],
        ['Isidora Muñoz Leiva',         'isidora.munoz@atankalama.cl',     '19876543', 'inn'],
        ['Catalina López Vergara',      'catalina.lopez@atankalama.cl',    '18123456', 'inn'],
        ['Javiera Torres Núñez',        'javiera.torres@atankalama.cl',    '17654321', 'inn'],
        ['Antonia Díaz Reyes',          'antonia.diaz@atankalama.cl',      '20345678', 'ambos'],
        ['Constanza Álvarez Salas',     'constanza.alvarez@atankalama.cl', '19112233', 'ambos'],
        ['Francisca Ortiz Bravo',       'francisca.ortiz@atankalama.cl',   '18445566', '1_sur'],
    ];

    $supervisoras = [
        ['Paola Henríquez Castro',      'paola.henriquez@atankalama.cl',   '15234567', 'ambos'],
        ['Claudia Morales Sepúlveda',   'claudia.morales@atankalama.cl',   '14987654', 'ambos'],
    ];

    $recepcion = [
        ['Daniela Contreras Cerda',     'daniela.contreras@atankalama.cl', '16789012', '1_sur'],
        ['Andrea Silva Peña',           'andrea.silva@atankalama.cl',      '17345678', 'inn'],
    ];

    $rolTrabajador = (int) Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Trabajador'])['id'];
    $rolSupervisora = (int) Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Supervisora'])['id'];
    $rolRecepcion = (int) Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Recepción'])['id'];

    $idsT = insertarUsuarios($trabajadoras, $hashDemo, $rolTrabajador);
    $idsS = insertarUsuarios($supervisoras, $hashDemo, $rolSupervisora);
    $idsR = insertarUsuarios($recepcion, $hashDemo, $rolRecepcion);

    $log("  usuarios: " . count($idsT) . " trabajadoras, " . count($idsS) . " supervisoras, " . count($idsR) . " recepción\n");

    return ['trabajadoras' => $idsT, 'supervisoras' => $idsS, 'recepcion' => $idsR];
}

/**
 * @param list<array{0:string,1:string,2:string,3:string}> $datos  [nombre, email, rutBody, hotel]
 * @return list<int>
 */
function insertarUsuarios(array $datos, string $hashDemo, int $rolId): array
{
    $ids = [];
    foreach ($datos as [$nombre, $email, $rutBody, $hotelDefault]) {
        $dv = Rut::calcularDigitoVerificador($rutBody);
        $rut = $rutBody . '-' . $dv;

        $existente = Database::fetchOne('SELECT id FROM usuarios WHERE rut = ?', [$rut]);
        if ($existente !== null) {
            $ids[] = (int) $existente['id'];
            continue;
        }

        Database::execute(
            'INSERT INTO usuarios (rut, nombre, email, password_hash, requiere_cambio_pwd, activo, hotel_default) VALUES (?, ?, ?, ?, 0, 1, ?)',
            [$rut, $nombre, $email, $hashDemo, $hotelDefault]
        );
        $id = Database::lastInsertId();

        Database::execute(
            'INSERT OR IGNORE INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
            [$id, $rolId]
        );

        $ids[] = $id;
    }
    return $ids;
}

// -----------------------------------------------------------------------------
// Habitaciones
// -----------------------------------------------------------------------------

/**
 * @param callable(string):void $log
 * @return array{por_hotel: array<int, list<int>>, todas: list<int>}
 */
function seedHabitacionesDemo(callable $log): array
{
    $hoteles = [];
    foreach (Database::fetchAll('SELECT id, codigo FROM hoteles') as $h) {
        $hoteles[$h['codigo']] = (int) $h['id'];
    }

    $tipos = [];
    foreach (Database::fetchAll('SELECT id, nombre FROM tipos_habitacion') as $t) {
        $tipos[$t['nombre']] = (int) $t['id'];
    }

    // 12 en 1_sur, 8 en inn; mix tipos; estado inicial 'sucia'
    $habitaciones = [
        // 1 Sur
        ['1_sur', '101', 'Singular'],
        ['1_sur', '102', 'Doble'],
        ['1_sur', '103', 'Doble'],
        ['1_sur', '104', 'Matrimonial'],
        ['1_sur', '201', 'Singular'],
        ['1_sur', '202', 'Doble'],
        ['1_sur', '203', 'Matrimonial'],
        ['1_sur', '204', 'Suite'],
        ['1_sur', '301', 'Doble'],
        ['1_sur', '302', 'Matrimonial'],
        ['1_sur', '303', 'Singular'],
        ['1_sur', '304', 'Doble'],
        // Inn
        ['inn',   '10',  'Singular'],
        ['inn',   '11',  'Doble'],
        ['inn',   '12',  'Doble'],
        ['inn',   '13',  'Matrimonial'],
        ['inn',   '14',  'Suite'],
        ['inn',   '20',  'Singular'],
        ['inn',   '21',  'Doble'],
        ['inn',   '22',  'Matrimonial'],
    ];

    $idsPorHotel = [];
    $todas = [];

    foreach ($habitaciones as [$hotelCodigo, $numero, $tipoNombre]) {
        $hotelId = $hoteles[$hotelCodigo];
        $tipoId = $tipos[$tipoNombre];

        $existente = Database::fetchOne(
            'SELECT id FROM habitaciones WHERE hotel_id = ? AND numero = ?',
            [$hotelId, $numero]
        );
        if ($existente !== null) {
            $id = (int) $existente['id'];
        } else {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
                [$hotelId, $numero, $tipoId]
            );
            $id = Database::lastInsertId();
        }
        $idsPorHotel[$hotelId][] = $id;
        $todas[] = $id;
    }

    $log("  habitaciones: " . count($todas) . " (" . count($idsPorHotel[$hoteles['1_sur']] ?? []) . " en 1_sur, " . count($idsPorHotel[$hoteles['inn']] ?? []) . " en inn)\n");

    return ['por_hotel' => $idsPorHotel, 'todas' => $todas];
}

// -----------------------------------------------------------------------------
// Turnos de la semana
// -----------------------------------------------------------------------------

/**
 * @param callable(string):void $log
 */
function seedTurnosDemo(array $usuarios, callable $log): void
{
    $turnoManana = (int) Database::fetchOne('SELECT id FROM turnos WHERE nombre = ?', ['mañana'])['id'];
    $turnoTarde = (int) Database::fetchOne('SELECT id FROM turnos WHERE nombre = ?', ['tarde'])['id'];

    $hoy = new DateTimeImmutable('today');
    // Lunes de esta semana (o hoy si es lunes). En PHP N=1=Lun, 7=Dom.
    $diaSemana = (int) $hoy->format('N');
    $lunes = $hoy->modify('-' . ($diaSemana - 1) . ' days');

    $total = 0;
    for ($d = 0; $d < 7; $d++) {
        $fecha = $lunes->modify("+{$d} days")->format('Y-m-d');

        // Trabajadoras: mitad mañana, mitad tarde, rotando por día
        foreach ($usuarios['trabajadoras'] as $i => $uid) {
            $turnoId = (($i + $d) % 2 === 0) ? $turnoManana : $turnoTarde;
            $ins = Database::execute(
                'INSERT OR IGNORE INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
                [$uid, $turnoId, $fecha]
            );
            $total += $ins;
        }
        // Supervisoras siempre mañana
        foreach ($usuarios['supervisoras'] as $uid) {
            $ins = Database::execute(
                'INSERT OR IGNORE INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
                [$uid, $turnoManana, $fecha]
            );
            $total += $ins;
        }
        // Recepción siempre mañana
        foreach ($usuarios['recepcion'] as $uid) {
            $ins = Database::execute(
                'INSERT OR IGNORE INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
                [$uid, $turnoManana, $fecha]
            );
            $total += $ins;
        }
    }

    $log("  usuarios_turnos: {$total} filas insertadas para semana {$lunes->format('Y-m-d')} a " . $lunes->modify('+6 days')->format('Y-m-d') . "\n");
}

// -----------------------------------------------------------------------------
// Asignaciones de HOY
// -----------------------------------------------------------------------------

/**
 * @param callable(string):void $log
 * @return list<array{id:int, habitacion_id:int, usuario_id:int, tipo_habitacion_id:int}>
 */
function seedAsignacionesDemo(array $usuarios, array $habitaciones, callable $log): array
{
    $hoy = date('Y-m-d');
    $trabajadoras = $usuarios['trabajadoras'];
    $rooms = $habitaciones['todas'];

    // Distribuir rooms: primeros 14 rooms asignados, últimos quedan sin asignar
    $roomsAsignar = array_slice($rooms, 0, 14);
    $asignacionesCreadas = [];
    $supervisoraId = $usuarios['supervisoras'][0] ?? null;

    foreach ($roomsAsignar as $idx => $roomId) {
        $workerId = $trabajadoras[$idx % count($trabajadoras)];
        $ordenCola = intdiv($idx, count($trabajadoras));

        $existente = Database::fetchOne(
            'SELECT id FROM asignaciones WHERE habitacion_id = ? AND fecha = ? AND activa = 1',
            [$roomId, $hoy]
        );
        if ($existente !== null) {
            $asignacionId = (int) $existente['id'];
        } else {
            Database::execute(
                'INSERT INTO asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, activa) VALUES (?, ?, ?, ?, ?, 1)',
                [$roomId, $workerId, $supervisoraId, $ordenCola, $hoy]
            );
            $asignacionId = Database::lastInsertId();
        }

        $tipo = Database::fetchOne('SELECT tipo_habitacion_id FROM habitaciones WHERE id = ?', [$roomId]);
        $asignacionesCreadas[] = [
            'id' => $asignacionId,
            'habitacion_id' => $roomId,
            'usuario_id' => $workerId,
            'tipo_habitacion_id' => (int) $tipo['tipo_habitacion_id'],
        ];
    }

    $log("  asignaciones: " . count($asignacionesCreadas) . " para hoy ({$hoy})\n");
    return $asignacionesCreadas;
}

// -----------------------------------------------------------------------------
// Ejecuciones de checklist
// -----------------------------------------------------------------------------

/**
 * Estados a sembrar:
 *   - 4 completadas (timestamp_fin seteado, estado='completada' si aún no auditada)
 *   - 2 en_progreso (algunos items marcados, timestamp_fin = null)
 *   - resto de asignaciones: sin ejecución todavía
 *
 * @param callable(string):void $log
 * @return array{completadas: list<array{id:int, habitacion_id:int, usuario_id:int, asignacion_id:int}>, en_progreso: list<int>}
 */
function seedEjecucionesDemo(array $asignaciones, callable $log): array
{
    $completadas = [];
    $enProgreso = [];

    // Primeras 4 asignaciones → completadas
    for ($i = 0; $i < 4 && $i < count($asignaciones); $i++) {
        $a = $asignaciones[$i];
        $templateId = (int) Database::fetchOne(
            'SELECT id FROM checklists_template WHERE tipo_habitacion_id = ? AND activo = 1',
            [$a['tipo_habitacion_id']]
        )['id'];

        $existente = Database::fetchOne(
            'SELECT id FROM ejecuciones_checklist WHERE asignacion_id = ?',
            [$a['id']]
        );
        if ($existente !== null) {
            $ejecId = (int) $existente['id'];
        } else {
            // Inicio hace 2h, fin hace 1h (varía un poco por room)
            $inicio = (new DateTimeImmutable('now'))->modify('-' . (120 + $i * 5) . ' minutes');
            $fin = $inicio->modify('+' . (45 + $i * 3) . ' minutes');

            Database::execute(
                "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin) VALUES (?, ?, ?, ?, 'completada', ?, ?)",
                [$a['habitacion_id'], $a['id'], $a['usuario_id'], $templateId, $inicio->format('Y-m-d\TH:i:s.v\Z'), $fin->format('Y-m-d\TH:i:s.v\Z')]
            );
            $ejecId = Database::lastInsertId();

            // Marcar todos los items
            $items = Database::fetchAll('SELECT id FROM items_checklist WHERE template_id = ? ORDER BY orden', [$templateId]);
            foreach ($items as $item) {
                Database::execute(
                    'INSERT OR IGNORE INTO ejecuciones_items (ejecucion_id, item_id, marcado) VALUES (?, ?, 1)',
                    [$ejecId, $item['id']]
                );
            }

            // Habitación → completada_pendiente_auditoria (luego auditorías la avanzan)
            Database::execute(
                "UPDATE habitaciones SET estado = 'completada_pendiente_auditoria' WHERE id = ?",
                [$a['habitacion_id']]
            );
        }

        $completadas[] = [
            'id' => $ejecId,
            'habitacion_id' => $a['habitacion_id'],
            'usuario_id' => $a['usuario_id'],
            'asignacion_id' => $a['id'],
        ];
    }

    // Asignaciones 4 y 5 → en_progreso
    for ($i = 4; $i < 6 && $i < count($asignaciones); $i++) {
        $a = $asignaciones[$i];
        $templateId = (int) Database::fetchOne(
            'SELECT id FROM checklists_template WHERE tipo_habitacion_id = ? AND activo = 1',
            [$a['tipo_habitacion_id']]
        )['id'];

        $existente = Database::fetchOne(
            'SELECT id FROM ejecuciones_checklist WHERE asignacion_id = ?',
            [$a['id']]
        );
        if ($existente !== null) {
            $enProgreso[] = (int) $existente['id'];
            continue;
        }

        $inicio = (new DateTimeImmutable('now'))->modify('-25 minutes');
        Database::execute(
            "INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio) VALUES (?, ?, ?, ?, 'en_progreso', ?)",
            [$a['habitacion_id'], $a['id'], $a['usuario_id'], $templateId, $inicio->format('Y-m-d\TH:i:s.v\Z')]
        );
        $ejecId = Database::lastInsertId();

        // Marcar solo primeros 4 items
        $items = Database::fetchAll('SELECT id FROM items_checklist WHERE template_id = ? ORDER BY orden LIMIT 4', [$templateId]);
        foreach ($items as $item) {
            Database::execute(
                'INSERT OR IGNORE INTO ejecuciones_items (ejecucion_id, item_id, marcado) VALUES (?, ?, 1)',
                [$ejecId, $item['id']]
            );
        }

        Database::execute(
            "UPDATE habitaciones SET estado = 'en_progreso' WHERE id = ?",
            [$a['habitacion_id']]
        );

        $enProgreso[] = $ejecId;
    }

    $log("  ejecuciones_checklist: " . count($completadas) . " completadas, " . count($enProgreso) . " en progreso\n");
    return ['completadas' => $completadas, 'en_progreso' => $enProgreso];
}

// -----------------------------------------------------------------------------
// Auditorías
// -----------------------------------------------------------------------------

/**
 * @param callable(string):void $log
 */
function seedAuditoriasDemo(array $usuarios, array $ejecuciones, callable $log): void
{
    $completadas = $ejecuciones['completadas'];
    $auditor = $usuarios['supervisoras'][0] ?? null;
    if ($auditor === null || count($completadas) < 4) {
        $log("  auditorias: omitidas (sin supervisora o menos de 4 completadas)\n");
        return;
    }

    // Mapa: índice → veredicto
    $veredictos = [
        0 => ['aprobado',                   'Todo perfecto. Buen trabajo.',                              null],
        1 => ['aprobado',                   'Aprobada sin observaciones.',                              null],
        2 => ['aprobado_con_observacion',   'Baño requirió repaso en espejo, lo resolví en sitio.',     'espejo'],
        3 => ['rechazado',                  'Falta aspirar bajo la cama y reponer shampoo. Se reasigna.','rechazo'],
    ];

    foreach ($veredictos as $idx => [$veredicto, $comentario, $tipo]) {
        $ejec = $completadas[$idx];

        $existente = Database::fetchOne('SELECT id FROM auditorias WHERE ejecucion_id = ?', [$ejec['id']]);
        if ($existente !== null) continue;

        $itemsDesmarcadosJson = null;
        if ($tipo === 'espejo') {
            $itemDesmarcado = Database::fetchOne(
                'SELECT ei.item_id FROM ejecuciones_items ei WHERE ei.ejecucion_id = ? LIMIT 1',
                [$ejec['id']]
            );
            if ($itemDesmarcado !== null) {
                Database::execute(
                    'UPDATE ejecuciones_items SET desmarcado_por_auditor = 1 WHERE ejecucion_id = ? AND item_id = ?',
                    [$ejec['id'], $itemDesmarcado['item_id']]
                );
                $itemsDesmarcadosJson = json_encode([(int) $itemDesmarcado['item_id']]);
            }
        }

        Database::execute(
            'INSERT INTO auditorias (ejecucion_id, habitacion_id, auditor_id, veredicto, comentario, items_desmarcados_json) VALUES (?, ?, ?, ?, ?, ?)',
            [$ejec['id'], $ejec['habitacion_id'], $auditor, $veredicto, $comentario, $itemsDesmarcadosJson]
        );

        // Avanzar estado de habitación y ejecución según veredicto
        $estadoHab = match ($veredicto) {
            'aprobado' => 'aprobada',
            'aprobado_con_observacion' => 'aprobada_con_observacion',
            'rechazado' => 'rechazada',
        };
        Database::execute('UPDATE habitaciones SET estado = ? WHERE id = ?', [$estadoHab, $ejec['habitacion_id']]);
        Database::execute("UPDATE ejecuciones_checklist SET estado = 'auditada' WHERE id = ?", [$ejec['id']]);
    }

    $log("  auditorias: 4 creadas (2 aprobado, 1 observación, 1 rechazado)\n");
}

// -----------------------------------------------------------------------------
// Tickets de mantenimiento
// -----------------------------------------------------------------------------

/**
 * @param callable(string):void $log
 */
function seedTicketsDemo(array $usuarios, array $habitaciones, callable $log): void
{
    $trabajadoras = $usuarios['trabajadoras'];
    $supervisoras = $usuarios['supervisoras'];
    $recepcion = $usuarios['recepcion'];
    $rooms = $habitaciones['todas'];
    $hotelPorRoom = [];
    foreach (Database::fetchAll('SELECT id, hotel_id FROM habitaciones') as $r) {
        $hotelPorRoom[(int) $r['id']] = (int) $r['hotel_id'];
    }

    $tickets = [
        [
            'habitacion_id' => $rooms[6] ?? null,
            'titulo' => 'Ducha con goteo persistente',
            'descripcion' => 'La ducha de la 203 gotea aunque esté cerrada. Requiere revisión de mantención.',
            'prioridad' => 'normal',
            'estado' => 'abierto',
            'levantado_por' => $trabajadoras[2],
            'asignado_a' => null,
        ],
        [
            'habitacion_id' => $rooms[9] ?? null,
            'titulo' => 'Bombilla del velador quemada',
            'descripcion' => 'La luz de la mesa de noche no enciende.',
            'prioridad' => 'baja',
            'estado' => 'resuelto',
            'levantado_por' => $trabajadoras[5],
            'asignado_a' => $supervisoras[0],
        ],
        [
            'habitacion_id' => $rooms[11] ?? null,
            'titulo' => 'Puerta de clóset no cierra',
            'descripcion' => 'La puerta del clóset quedó desalineada. Huésped se queja al entrar.',
            'prioridad' => 'alta',
            'estado' => 'en_progreso',
            'levantado_por' => $recepcion[0],
            'asignado_a' => $supervisoras[1],
        ],
        [
            'habitacion_id' => $rooms[14] ?? null,
            'titulo' => 'Filtración de agua desde el techo',
            'descripcion' => 'Mancha húmeda creciendo en esquina del techo, cerca del baño. Urgente — afecta estructura.',
            'prioridad' => 'urgente',
            'estado' => 'abierto',
            'levantado_por' => $supervisoras[0],
            'asignado_a' => null,
        ],
        [
            'habitacion_id' => null,
            'titulo' => 'Aspiradora del Inn hace ruido raro',
            'descripcion' => 'La aspiradora industrial del Inn está emitiendo ruido metálico. Probable rodamiento. Revisar antes de daño mayor.',
            'prioridad' => 'normal',
            'estado' => 'en_progreso',
            'levantado_por' => $trabajadoras[4],
            'asignado_a' => $supervisoras[1],
        ],
    ];

    $creados = 0;
    foreach ($tickets as $t) {
        $hotelId = $t['habitacion_id'] !== null
            ? ($hotelPorRoom[$t['habitacion_id']] ?? 1)
            : 2;

        $existente = Database::fetchOne(
            'SELECT id FROM tickets WHERE titulo = ? AND levantado_por = ?',
            [$t['titulo'], $t['levantado_por']]
        );
        if ($existente !== null) continue;

        $resueltoAt = $t['estado'] === 'resuelto'
            ? (new DateTimeImmutable('-3 hours'))->format('Y-m-d\TH:i:s.v\Z')
            : null;

        Database::execute(
            'INSERT INTO tickets (habitacion_id, hotel_id, titulo, descripcion, prioridad, estado, levantado_por, asignado_a, resuelto_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$t['habitacion_id'], $hotelId, $t['titulo'], $t['descripcion'], $t['prioridad'], $t['estado'], $t['levantado_por'], $t['asignado_a'], $resueltoAt]
        );
        $creados++;
    }

    $log("  tickets: {$creados} creados (1 urgente, 1 alta, 2 normal, 1 baja; mix estados)\n");
}
