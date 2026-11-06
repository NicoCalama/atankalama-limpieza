<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\EspaciosController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\PersonalAsignableService;
use Atankalama\Limpieza\Services\UsuarioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.14 (pedido de Nicolás, 06/11/2026): a quién se le pueden asignar piezas. Pestaña «Aseo» = personal
 * de aseo con turno; pestaña «Otros» = gente de otras áreas que limpia (Apoyo…), con o sin turno.
 * La misma lista sale en Asignaciones y en «Pedir limpieza» de Áreas comunes.
 */
final class PersonalAsignableTest extends TestCase
{
    private string $hoy;
    private int $turnoId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        $this->hoy = date('Y-m-d');
        Database::execute("INSERT INTO turnos (nombre, hora_inicio, hora_fin) VALUES ('mañana', '08:00', '16:00')");
        $this->turnoId = Database::lastInsertId();
    }

    public function testAseoConTurnoYOtrosQueLimpianConOSinTurno(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        TestDatabase::crearUsuario('22222222-2', 'Bea', 'Aseo'); // aseo sin turno: no sale
        [$pia] = TestDatabase::crearUsuario('33333333-3', 'Pía', 'Apoyo'); // otros sin turno: sale
        [$rosa] = TestDatabase::crearUsuario('44444444-4', 'Rosa', 'Apoyo');
        $this->darTurno($ana);
        $this->darTurno($rosa);

        $lista = $this->porNombre((new PersonalAsignableService())->listar($this->hoy));

        $this->assertSame(['Ana', 'Pía', 'Rosa'], array_keys($lista));
        $this->assertSame(['grupo' => 'aseo', 'con_turno' => true], $this->grupoYTurno($lista['Ana']));
        $this->assertSame(['grupo' => 'otros', 'con_turno' => false], $this->grupoYTurno($lista['Pía']));
        $this->assertSame(['grupo' => 'otros', 'con_turno' => true], $this->grupoYTurno($lista['Rosa']));
        $this->assertSame($pia, $lista['Pía']['id']);
    }

    public function testQuienNoLimpiaOReparteNoSaleAunqueTengaTurno(): void
    {
        [$sup] = TestDatabase::crearUsuario('11111111-1', 'Sofía', 'Supervisora');
        [$adm] = TestDatabase::crearUsuario('22222222-2', 'Admin', 'Admin');
        [$rec] = TestDatabase::crearUsuario('33333333-3', 'Rita', 'Recepción'); // no ve piezas asignadas
        foreach ([$sup, $adm, $rec] as $id) {
            $this->darTurno($id);
        }

        $this->assertSame([], (new PersonalAsignableService())->listar($this->hoy));
    }

    public function testQuienYaTienePiezasEseDiaSaleAunqueNoCalce(): void
    {
        [$adm] = TestDatabase::crearUsuario('11111111-1', 'Admin', 'Admin');
        [$bea] = TestDatabase::crearUsuario('22222222-2', 'Bea', 'Aseo'); // aseo sin turno
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        foreach (['101', '102'] as $numero) {
            Database::execute("INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, ?, 1, 'sucia')", [$numero]);
        }
        $asig = new AsignacionService();
        $asig->asignarManual(1, $adm, $this->hoy);
        $asig->asignarManual(2, $bea, $this->hoy);

        $lista = (new PersonalAsignableService())->listar($this->hoy);

        // Sus piezas no quedan escondidas del tablero: salen, cada una en su grupo.
        $this->assertSame([['Admin', 'otros'], ['Bea', 'aseo']], array_map(fn(array $p): array => [$p['nombre'], $p['grupo']], $lista));
        // Otro día, sin piezas, no salen.
        $this->assertSame([], (new PersonalAsignableService())->listar(date('Y-m-d', strtotime('+1 day'))));
    }

    public function testRecepcionConElRolApoyoSaleEnOtros(): void
    {
        [$rec] = TestDatabase::crearUsuario('11111111-1', 'Rita', 'Recepción');
        Database::execute(
            "INSERT INTO usuarios_roles (usuario_id, rol_id) SELECT ?, id FROM roles WHERE nombre = 'Apoyo'",
            [$rec]
        );

        $lista = (new PersonalAsignableService())->listar($this->hoy);

        $this->assertSame([['Rita', 'otros']], array_map(fn(array $p): array => [$p['nombre'], $p['grupo']], $lista));
    }

    public function testInactivosYOtroHotelNoSalen(): void
    {
        TestDatabase::crearUsuario('11111111-1', 'Pía', 'Apoyo', activo: false);
        [$inn] = TestDatabase::crearUsuario('22222222-2', 'Inés', 'Apoyo');
        TestDatabase::crearUsuario('33333333-3', 'Amalia', 'Apoyo'); // hotel_default 'ambos'
        Database::execute("UPDATE usuarios SET hotel_default = 'inn' WHERE id = ?", [$inn]);

        $nombres = fn(?string $hotel): array => array_column((new PersonalAsignableService())->listar($this->hoy, $hotel), 'nombre');

        $this->assertSame(['Amalia'], $nombres('1_sur'));
        $this->assertSame(['Amalia', 'Inés'], $nombres('inn'));
        $this->assertSame(['Amalia', 'Inés'], $nombres('ambos'));
        $this->assertSame(['Amalia', 'Inés'], $nombres(null));
    }

    public function testElTableroYPedirLimpiezaUsanLaMismaLista(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        TestDatabase::crearUsuario('22222222-2', 'Pía', 'Apoyo');
        [$sup] = TestDatabase::crearUsuario('33333333-3', 'Sofía', 'Supervisora');
        $this->darTurno($ana);

        $vista = (new AsignacionService())->vistaConsolidada('ambos', $this->hoy);
        $tablero = array_map(fn(array $t): array => [$t['usuario']['nombre'], $t['usuario']['grupo']], $vista['trabajadores']);
        $this->assertSame([['Ana', 'aseo'], ['Pía', 'otros']], $tablero);

        $req = new Request(metodo: 'GET', path: '/api/espacios', query: ['fecha' => $this->hoy]);
        $req->usuario = (new UsuarioService())->buscarPorId($sup);
        $espacios = json_decode((new EspaciosController())->listar($req)->cuerpo, true);
        $pedir = array_map(fn(array $t): array => [$t['nombre'], $t['grupo']], $espacios['data']['trabajadores']);
        $this->assertSame($tablero, $pedir);
    }

    public function testElAutoAsignarSigueSoloConAseoConTurno(): void
    {
        [$ana] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Aseo');
        [$pia] = TestDatabase::crearUsuario('22222222-2', 'Pía', 'Apoyo');
        $this->darTurno($ana);
        $this->darTurno($pia);
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        foreach (['101', '102'] as $numero) {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (1, ?, 1, 'sucia')",
                [$numero]
            );
        }

        (new AsignacionService())->autoAsignar('ambos', $this->hoy);

        $this->assertSame(
            [$ana],
            array_map('intval', array_column(Database::fetchAll('SELECT DISTINCT usuario_id FROM asignaciones WHERE activa = 1'), 'usuario_id'))
        );
    }

    // --- Helpers -----------------------------------------------------------------------

    private function darTurno(int $usuarioId): void
    {
        Database::execute(
            'INSERT INTO usuarios_turnos (usuario_id, turno_id, fecha) VALUES (?, ?, ?)',
            [$usuarioId, $this->turnoId, $this->hoy]
        );
    }

    /**
     * @param list<array<string, mixed>> $lista
     * @return array<string, array<string, mixed>>
     */
    private function porNombre(array $lista): array
    {
        return array_column($lista, null, 'nombre');
    }

    /**
     * @param array<string, mixed> $persona
     * @return array{grupo: mixed, con_turno: mixed}
     */
    private function grupoYTurno(array $persona): array
    {
        return ['grupo' => $persona['grupo'], 'con_turno' => $persona['con_turno']];
    }
}
