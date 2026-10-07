<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\HabitacionesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\CierreDiaService;
use Atankalama\Limpieza\Services\EspacioService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Preasignaciones (hechas antes de su día) que amanecen con la pieza ya aprobada, y «Marcar
 * sucia» sobre lo que aprobó el sistema (v6.17.1). Ver AsignacionService::reconciliarPreasignaciones()
 * y HabitacionesController::marcarSuciaManual().
 *
 * Datos de producción que lo motivaron (07/10/2026): de 31 preasignaciones de áreas comunes entre
 * el 07/09 y el 06/10, solo una se limpió (19 se cancelaron como si no hicieran falta y 7 quedaron
 * en la cola sin poder empezarse); y una habitación del INN aprobada por el cierre de día se quedó
 * en la cola de su trabajadora.
 */
final class PreasignacionesTest extends TestCase
{
    private AsignacionService $asig;
    private string $hoy;
    private int $hotelId;
    private int $tipoId;
    private int $ana;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $this->hotelId = (int) Database::fetchColumn("SELECT id FROM hoteles WHERE codigo = '1_sur'");
        $this->tipoId  = (int) Database::fetchColumn("SELECT id FROM tipos_habitacion WHERE nombre = 'Doble'");
        [$this->ana]   = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        $this->hoy     = date('Y-m-d');
        $this->asig    = new AsignacionService();
    }

    /** @return array<string, array{0: string}> */
    public static function estadosAprobados(): array
    {
        return [
            'aprobada'                 => ['aprobada'],
            'aprobada con observación' => ['aprobada_con_observacion'],
            'aprobada por el sistema'  => ['aprobada_automatica'],
        ];
    }

    #[DataProvider('estadosAprobados')]
    public function testHabitacionPreasignadaQueAmaneceAprobadaSaleDeLaCola(string $estado): void
    {
        $hab = $this->crearHabitacion('305', $estado);
        $asignacionId = $this->preasignar($hab);

        $cola = $this->asig->colaDelTrabajador($this->ana, $this->hoy);

        $this->assertSame([], $cola);
        $this->assertSame(0, $this->activa($asignacionId));
        $this->assertSame($estado, $this->estado($hab), 'una habitación limpia no se ensucia');
        $this->assertSame(1, $this->avisosDeRetiro());
    }

    #[DataProvider('estadosAprobados')]
    public function testAreaComunPreasignadaPasaASuciaYSePuedeEmpezar(string $estado): void
    {
        $area = (new EspacioService())->crear('Baño recepción', '1_sur', ['Limpiar lavamanos']);
        Database::execute('UPDATE habitaciones SET estado = ? WHERE id = ?', [$estado, $area]);
        $asignacionId = $this->preasignar($area);

        $cola = $this->asig->colaDelTrabajador($this->ana, $this->hoy);

        $this->assertSame('sucia', $this->estado($area));
        $this->assertSame(1, $this->activa($asignacionId));
        $this->assertSame(0, $this->avisosDeRetiro());
        $this->assertCount(1, $cola);
        $this->assertSame('sucia', $cola[0]['estado']);
        $actual = AsignacionService::elegirHabitacionActual($cola);
        $this->assertNotNull($actual);
        $this->assertSame($area, (int) $actual['habitacion_id']);

        // Y quien la tiene asignada la puede empezar: la limpieza cuelga de la preasignación.
        $ejecucion = (new ChecklistService())->iniciarEjecucion($area, $this->ana, $this->hoy);
        $this->assertSame(
            $asignacionId,
            (int) Database::fetchColumn('SELECT asignacion_id FROM ejecuciones_checklist WHERE id = ?', [$ejecucion->id])
        );
    }

    public function testAreaComunYaLimpiadaHoyNoSeVuelveAEnsuciar(): void
    {
        $area = (new EspacioService())->crear('Pasillo 2º piso', '1_sur', ['Barrer']);
        $asignacionId = $this->preasignar($area);
        $this->asig->colaDelTrabajador($this->ana, $this->hoy); // llega el día: queda sucia
        [$sofia] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');

        // «Marcar limpia» liga su limpieza a la asignación activa, y el cierre de día la aprueba.
        (new ChecklistService())->marcarLimpiaManual($area, $sofia);
        (new CierreDiaService())->aprobarPendientes($sofia);
        $this->assertSame('aprobada_automatica', $this->estado($area));

        $this->asig->colaDelTrabajador($this->ana, $this->hoy); // otra carga del mismo día

        $this->assertSame('aprobada_automatica', $this->estado($area));
        $this->assertSame(1, $this->activa($asignacionId));
    }

    #[DataProvider('estadosAprobados')]
    public function testMarcarSuciaAceptaCualquierAprobada(string $estado): void
    {
        $hab = $this->crearHabitacion('410', $estado); // sin cloudbeds_room_id: no le escribe a Cloudbeds
        [$sofia] = TestDatabase::crearUsuario('22222222-2', 'Sofía', 'Supervisora');
        $request = new Request(
            metodo: 'POST',
            path: "/api/habitaciones/{$hab}/marcar-sucia",
            cuerpo: [],
            ruta: ['id' => (string) $hab],
            query: [],
            cookies: [],
            headers: [],
        );
        $request->usuario = new Usuario(
            id: $sofia,
            rut: '22222222-2',
            nombre: 'Sofía',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['habitaciones.marcar_limpia_manual'],
            roles: ['Supervisora'],
        );

        $respuesta = (new HabitacionesController())->marcarSuciaManual($request);

        $this->assertSame(200, $respuesta->status, $respuesta->cuerpo);
        $this->assertSame('sucia', $this->estado($hab));
    }

    private function crearHabitacion(string $numero, string $estado): int
    {
        Database::execute(
            'INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, ?)',
            [$this->hotelId, $numero, $this->tipoId, $estado]
        );
        return Database::lastInsertId();
    }

    /**
     * Asignación planificada para un día que todavía no llega (asignarManual no toca el estado de
     * la pieza), y después «llega el día».
     */
    private function preasignar(int $habitacionId): int
    {
        $manana = date('Y-m-d', strtotime('+1 day'));
        $asignacion = $this->asig->asignarManual($habitacionId, $this->ana, $manana);
        Database::execute('UPDATE asignaciones SET fecha = ? WHERE id = ?', [$this->hoy, $asignacion->id]);
        return $asignacion->id;
    }

    private function estado(int $habitacionId): string
    {
        return (string) Database::fetchColumn('SELECT estado FROM habitaciones WHERE id = ?', [$habitacionId]);
    }

    private function activa(int $asignacionId): int
    {
        return (int) Database::fetchColumn('SELECT activa FROM asignaciones WHERE id = ?', [$asignacionId]);
    }

    private function avisosDeRetiro(): int
    {
        return (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND titulo = 'Habitación retirada de tu cola'",
            [$this->ana]
        );
    }
}
