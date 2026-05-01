<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\PasswordService;

Config::load(dirname(__DIR__));

$seedDir = dirname(__DIR__) . '/database/seeds';

echo "Cargando seeders...\n\n";

Database::transaction(function () use ($seedDir) {
    seedPermisos($seedDir);
    seedRoles($seedDir);
    seedCatalogos($seedDir);
    seedChecklistTemplates($seedDir);
    seedAdminInicial();
});

echo "\nSeeders completados.\n";

function seedPermisos(string $seedDir): void
{
    $permisos = require $seedDir . '/permisos.php';
    $sql = 'INSERT OR IGNORE INTO permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)';
    foreach ($permisos as [$codigo, $descripcion, $categoria, $scope]) {
        Database::execute($sql, [$codigo, $descripcion, $categoria, $scope]);
    }
    echo "  permisos: " . count($permisos) . " insertados (INSERT OR IGNORE)\n";
}

function seedRoles(string $seedDir): void
{
    $roles = require $seedDir . '/roles.php';
    $todosLosPermisos = Database::fetchAll('SELECT codigo FROM permisos');
    $codigosTodos = array_column($todosLosPermisos, 'codigo');

    foreach ($roles as $rol) {
        $existente = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', [$rol['nombre']]);

        if ($existente === null) {
            Database::execute(
                'INSERT INTO roles (nombre, descripcion, es_sistema) VALUES (?, ?, ?)',
                [$rol['nombre'], $rol['descripcion'], $rol['es_sistema']]
            );
            $rolId = Database::lastInsertId();
            echo "  rol creado: {$rol['nombre']} (id={$rolId})\n";
        } else {
            $rolId = (int) $existente['id'];
            echo "  rol ya existía: {$rol['nombre']} (id={$rolId})\n";
        }

        $permisos = $rol['permisos'] === '__ALL__' ? $codigosTodos : $rol['permisos'];
        foreach ($permisos as $codigo) {
            Database::execute(
                'INSERT OR IGNORE INTO rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
                [$rolId, $codigo]
            );
        }
        echo "    permisos asignados: " . count($permisos) . "\n";
    }
}

function seedCatalogos(string $seedDir): void
{
    $c = require $seedDir . '/catalogos.php';

    foreach ($c['hoteles'] as $hotel) {
        Database::execute(
            'INSERT OR IGNORE INTO hoteles (codigo, nombre, cloudbeds_property_id) VALUES (?, ?, ?)',
            [$hotel['codigo'], $hotel['nombre'], $hotel['cloudbeds_property_id']]
        );
    }
    echo "  hoteles: " . count($c['hoteles']) . "\n";

    foreach ($c['turnos'] as $turno) {
        Database::execute(
            'INSERT OR IGNORE INTO turnos (nombre, hora_inicio, hora_fin) VALUES (?, ?, ?)',
            [$turno['nombre'], $turno['hora_inicio'], $turno['hora_fin']]
        );
    }
    echo "  turnos: " . count($c['turnos']) . "\n";

    foreach ($c['tipos_habitacion'] as $tipo) {
        Database::execute(
            'INSERT OR IGNORE INTO tipos_habitacion (nombre, descripcion) VALUES (?, ?)',
            [$tipo['nombre'], $tipo['descripcion']]
        );
    }
    echo "  tipos_habitacion: " . count($c['tipos_habitacion']) . "\n";

    foreach ($c['alertas_config'] as $cfg) {
        Database::execute(
            'INSERT OR IGNORE INTO alertas_config (clave, valor, descripcion) VALUES (?, ?, ?)',
            [$cfg['clave'], $cfg['valor'], $cfg['descripcion']]
        );
    }
    echo "  alertas_config: " . count($c['alertas_config']) . "\n";

    foreach ($c['cloudbeds_config'] as $cfg) {
        Database::execute(
            'INSERT OR IGNORE INTO cloudbeds_config (clave, valor, descripcion) VALUES (?, ?, ?)',
            [$cfg['clave'], $cfg['valor'], $cfg['descripcion']]
        );
    }
    echo "  cloudbeds_config: " . count($c['cloudbeds_config']) . "\n";
}

function seedChecklistTemplates(string $seedDir): void
{
    $checklists = require $seedDir . '/checklists.php';
    $items = $checklists['template_default_items'];

    $tipos = Database::fetchAll('SELECT id, nombre FROM tipos_habitacion ORDER BY id');
    $creados = 0;
    $reusados = 0;

    foreach ($tipos as $tipo) {
        $existente = Database::fetchOne(
            'SELECT id FROM checklists_template WHERE tipo_habitacion_id = ? AND activo = 1',
            [$tipo['id']]
        );

        if ($existente !== null) {
            $reusados++;
            continue;
        }

        Database::execute(
            'INSERT INTO checklists_template (tipo_habitacion_id, nombre) VALUES (?, ?)',
            [$tipo['id'], 'Checklist estándar — ' . $tipo['nombre']]
        );
        $templateId = Database::lastInsertId();

        foreach ($items as $item) {
            Database::execute(
                'INSERT INTO items_checklist (template_id, orden, descripcion, obligatorio) VALUES (?, ?, ?, ?)',
                [$templateId, $item['orden'], $item['descripcion'], $item['obligatorio']]
            );
        }
        $creados++;
    }

    echo "  checklists_template: {$creados} creados, {$reusados} ya existían (" . count($items) . " items c/u)\n";
}

function seedAdminInicial(): void
{
    $rutAdmin = '11111111-1';
    $existe = Database::fetchOne('SELECT id FROM usuarios WHERE rut = ?', [$rutAdmin]);
    if ($existe !== null) {
        echo "  admin inicial ya existía (rut={$rutAdmin}, id={$existe['id']})\n";
        return;
    }

    $passwordService  = new PasswordService();
    $passwordTemporal = 'Admin2025!';
    $hash             = $passwordService->hash($passwordTemporal);

    Database::execute(
        'INSERT INTO usuarios (rut, nombre, email, password_hash, requiere_cambio_pwd, activo, hotel_default) VALUES (?, ?, ?, ?, 1, 1, ?)',
        [$rutAdmin, 'Nicolás Campos', 'nicolas@atankalama.cl', $hash, 'ambos']
    );
    $usuarioId = Database::lastInsertId();

    $rolAdmin = Database::fetchOne('SELECT id FROM roles WHERE nombre = ?', ['Admin']);
    Database::execute(
        'INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (?, ?)',
        [$usuarioId, (int) $rolAdmin['id']]
    );

    Database::execute(
        'INSERT INTO contrasenas_temporales (usuario_id, motivo) VALUES (?, ?)',
        [$usuarioId, 'creacion']
    );

    echo "  admin inicial creado:\n";
    echo "    RUT: {$rutAdmin}\n";
    echo "    Contraseña temporal: {$passwordTemporal}\n";
    echo "    (cámbiala en el primer login)\n";
}
