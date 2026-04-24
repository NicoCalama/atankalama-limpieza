<?php
/**
 * Prepara la BD para grabar el video de demo.
 *
 * Qué hace:
 *  - Asigna 3 habitaciones a Catalina para HOY (1 en_progreso + 2 sucias en cola)
 *  - Pone 5 habitaciones en completada_pendiente_auditoria (bandeja de auditoría rica)
 *  - Deja el resto de la BD intacto
 *
 * Uso: php scripts/prepare-demo-video.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Services\PasswordService;

Config::load(dirname(__DIR__));

$pdo = Database::pdo();
$hoy = date('Y-m-d');
$ahora = date('Y-m-d H:i:s');

$pdo->beginTransaction();

try {
    // ─── IDs fijos del seeder demo ─────────────────────────────────────────
    $catalina   = 49;   // Catalina López Vergara  18123456-3
    $isidora    = 48;   // Isidora Muñoz Leiva     (usada para completar otras rooms)
    $javiera    = 50;   // Javiera Torres Núñez
    $admin      = 1;    // Nicolás Campos (asignador)

    // Habitaciones
    $hab202 = 66;   // #202  1_sur  Doble       (ya en_progreso para Catalina)
    $hab301 = 69;   // #301  1_sur  Doble       (sucia)
    $hab204 = 68;   // #204  1_sur  Suite       (sucia)
    // Para bandeja de auditoría
    $hab10  = 73;   // #10   inn    Singular
    $hab11  = 74;   // #11   inn    Doble
    $hab13  = 76;   // #13   inn    Matrimonial
    $hab303 = 71;   // #303  1_sur  Singular
    $hab304 = 72;   // #304  1_sur  Doble

    // Templates según tipo
    $tplSingular    = 1;
    $tplDoble       = 2;
    $tplMatrimonial = 3;
    $tplSuite       = 4;

    echo "=== Preparando demo de hoy ({$hoy}) ===\n\n";

    // ─── 1. Asignaciones de Catalina hoy ──────────────────────────────────
    // Eliminar asignaciones previas de Catalina para hoy (idempotente)
    $pdo->prepare("DELETE FROM asignaciones WHERE usuario_id=? AND fecha=?")->execute([$catalina, $hoy]);

    // #202 en_progreso (ya tiene ejecucion id=25) → orden 1
    $pdo->prepare("INSERT INTO asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, activa, created_at)
                   VALUES (?, ?, ?, 1, ?, 1, ?)")->execute([$hab202, $catalina, $admin, $hoy, $ahora]);
    echo "  [OK] Asignada #202 (en_progreso, 4/10 items) a Catalina — orden 1\n";

    // #301 sucia → orden 2
    $pdo->prepare("UPDATE habitaciones SET estado='sucia' WHERE id=?")->execute([$hab301]);
    $pdo->prepare("INSERT INTO asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, activa, created_at)
                   VALUES (?, ?, ?, 2, ?, 1, ?)")->execute([$hab301, $catalina, $admin, $hoy, $ahora]);
    echo "  [OK] Asignada #301 (sucia) a Catalina — orden 2\n";

    // #204 sucia (suite) → orden 3
    $pdo->prepare("UPDATE habitaciones SET estado='sucia' WHERE id=?")->execute([$hab204]);
    $pdo->prepare("INSERT INTO asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, activa, created_at)
                   VALUES (?, ?, ?, 3, ?, 1, ?)")->execute([$hab204, $catalina, $admin, $hoy, $ahora]);
    echo "  [OK] Asignada #204 (sucia, suite) a Catalina — orden 3\n";

    // ─── 2. Habitaciones para bandeja de auditoría ─────────────────────────
    $bandejaHabs = [
        ['id' => $hab10,  'num' => 10,  'tpl' => $tplSingular,    'uid' => $isidora, 'hotel' => 'INN'],
        ['id' => $hab11,  'num' => 11,  'tpl' => $tplDoble,       'uid' => $javiera, 'hotel' => 'INN'],
        ['id' => $hab13,  'num' => 13,  'tpl' => $tplMatrimonial, 'uid' => $isidora, 'hotel' => 'INN'],
        ['id' => $hab303, 'num' => 303, 'tpl' => $tplSingular,    'uid' => $javiera, 'hotel' => '1_SUR'],
        ['id' => $hab304, 'num' => 304, 'tpl' => $tplDoble,       'uid' => $isidora, 'hotel' => '1_SUR'],
    ];

    foreach ($bandejaHabs as $h) {
        // Estado de la habitación
        $pdo->prepare("UPDATE habitaciones SET estado='completada_pendiente_auditoria' WHERE id=?")->execute([$h['id']]);

        // Crear ejecucion completada si no existe aún
        $exists = $pdo->prepare("SELECT id FROM ejecuciones_checklist WHERE habitacion_id=? AND estado='completada'")->execute([$h['id']]);
        $row = $pdo->prepare("SELECT id FROM ejecuciones_checklist WHERE habitacion_id=? AND estado='completada'")->execute([$h['id']]);
        $existingEc = $pdo->query("SELECT id FROM ejecuciones_checklist WHERE habitacion_id={$h['id']} AND estado='completada'")->fetch();

        if (!$existingEc) {
            // Crear asignacion de soporte
            $pdo->prepare("INSERT OR IGNORE INTO asignaciones (habitacion_id, usuario_id, asignado_por, orden_cola, fecha, activa, created_at)
                           VALUES (?, ?, ?, 1, ?, 1, ?)")->execute([$h['id'], $h['uid'], $admin, $hoy, $ahora]);
            $asigId = (int)$pdo->lastInsertId();

            // Ejecución completada
            $tsInicio = date('Y-m-d H:i:s', strtotime($ahora) - rand(1800, 3600));
            $tsFin    = date('Y-m-d H:i:s', strtotime($ahora) - rand(60, 1800));
            $pdo->prepare("INSERT INTO ejecuciones_checklist (habitacion_id, asignacion_id, usuario_id, template_id, estado, timestamp_inicio, timestamp_fin, created_at)
                           VALUES (?, ?, ?, ?, 'completada', ?, ?, ?)")->execute([$h['id'], $asigId, $h['uid'], $h['tpl'], $tsInicio, $tsFin, $ahora]);
            $ecId = (int)$pdo->lastInsertId();

            // Marcar todos los items
            $items = $pdo->query("SELECT id FROM items_checklist WHERE template_id={$h['tpl']} AND activo=1 ORDER BY orden")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($items as $itemId) {
                $pdo->prepare("INSERT OR IGNORE INTO ejecuciones_items (ejecucion_id, item_id, marcado, desmarcado_por_auditor, updated_at)
                               VALUES (?, ?, 1, 0, ?)")->execute([$ecId, $itemId, $ahora]);
            }
            echo "  [OK] #{$h['num']} ({$h['hotel']}) → completada_pendiente_auditoria (ec creada, todos items marcados)\n";
        } else {
            echo "  [OK] #{$h['num']} ({$h['hotel']}) → completada_pendiente_auditoria (ec ya existía)\n";
        }
    }

    // ─── 3. Asegurar que habitación #202 sigue en_progreso ─────────────────
    $pdo->prepare("UPDATE habitaciones SET estado='en_progreso' WHERE id=?")->execute([$hab202]);
    echo "\n  [OK] #202 confirmada en estado en_progreso\n";

    // ─── 4. Igualar contraseña del admin a Demo1234! ───────────────────────
    $hash = (new PasswordService())->hash('Demo1234!');
    $pdo->prepare("UPDATE usuarios SET password_hash=?, requiere_cambio_pwd=0 WHERE rut='11111111-1'")->execute([$hash]);
    echo "  [OK] Contraseña admin igualada a Demo1234!\n";

    $pdo->commit();
    echo "\n✅ Demo lista. Resumen:\n";
    echo "   Catalina (#202 en_progreso, #301 sucia, #204 sucia)\n";
    echo "   Bandeja auditoría: #10, #11, #13 (INN) + #303, #304 (Atankalama)\n";
    echo "   Contraseña todos los usuarios: Demo1234!\n";

} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
