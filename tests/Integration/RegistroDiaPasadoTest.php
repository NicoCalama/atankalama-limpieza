<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Controllers\AsignacionesController;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;
use Atankalama\Limpieza\Models\Usuario;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Services\ReportesService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * v7.20 (reunión del 08/11/2026): un día pasado en Asignaciones muestra lo que limpió cada persona, leyendo las
 * limpiezas y no las tarjetas, y queda solo para ver. Nace del caso de Rafael Rodas: reasignar en la tarde apaga
 * la tarjeta de la mañana y esa limpieza desaparecía de la pantalla.
 */
final class RegistroDiaPasadoTest extends TestCase
{
    private AsignacionService $asig;
    private ChecklistService $chk;
    private AuditoriaService $aud;
    private ReportesService $reportes;
    private int $hab101;
    private int $hab102;
    private int $area;
    private int $ana;
    private int $berta;
    private int $sofia;
    private string $hoy;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', 'Atankalama')");
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Doble')");
        TestDatabase::sembrarChecklistTemplates();
        $tipo = (int) Database::fetchColumn('SELECT id FROM tipos_habitacion LIMIT 1');
        $crear = static function (string $numero, int $area) use ($tipo): int {
            Database::execute(
                "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado, es_espacio_comun) VALUES (1, ?, ?, 'sucia', ?)",
                [$numero, $tipo, $area]
            );
            return Database::lastInsertId();
        };
        $this->hab101 = $crear('101', 0);
        $this->hab102 = $crear('102', 0);
        $this->area = $crear('Piscina', 1);

        [$this->ana] = TestDatabase::crearUsuario('16000001-5', 'Ana', 'Aseo');
        [$this->berta] = TestDatabase::crearUsuario('17000001-3', 'Berta', 'Aseo');
        [$this->sofia] = TestDatabase::crearUsuario('15000001-7', 'Sofía', 'Supervisora');

        $this->hoy = date('Y-m-d');
        $this->asig = new AsignacionService();
        $this->chk = new ChecklistService();
        $this->aud = new AuditoriaService();
        $this->reportes = new ReportesService();
    }

    private function limpiar(int $habitacion, int $persona): void
    {
        $this->asig->asignarManual($habitacion, $persona, $this->hoy);
        $ejec = $this->chk->iniciarEjecucion($habitacion, $persona, $this->hoy);
        foreach ($this->chk->estadoEjecucion($ejec->id)['items'] as $item) {
            if ((int) $item['obligatorio'] === 1) {
                $this->chk->marcarItem($ejec->id, (int) $item['id'], true, $persona);
            }
        }
        $this->chk->completar($ejec->id, $persona);
    }

    private function aprobar(int $habitacion): void
    {
        $this->aud->emitirVeredicto($habitacion, $this->sofia, 'aprobado');
    }

    private function rechazar(int $habitacion): void
    {
        $ejecucion = (int) Database::fetchColumn('SELECT MAX(id) FROM ejecuciones_checklist WHERE habitacion_id = ?', [$habitacion]);
        $item = (int) Database::fetchColumn('SELECT item_id FROM ejecuciones_items WHERE ejecucion_id = ? LIMIT 1', [$ejecucion]);
        $this->aud->emitirVeredicto($habitacion, $this->sofia, 'rechazado', 'Cama mal hecha', [$item]);
    }

    /** @return array<string, mixed> */
    private function fila(string $nombre): array
    {
        foreach ($this->reportes->registroDelDia($this->hoy, 'ambos') as $f) {
            if ($f['nombre'] === $nombre) {
                return $f;
            }
        }
        $this->fail("{$nombre} no aparece en el registro");
    }

    public function testDosPersonasEnLaMismaPiezaCadaUnaVeLaSuya(): void
    {
        $this->limpiar($this->hab101, $this->ana);
        $this->aprobar($this->hab101);
        $this->limpiar($this->hab101, $this->berta);
        $this->rechazar($this->hab101);

        $ana = $this->fila('Ana');
        $berta = $this->fila('Berta');
        $this->assertSame(['aprobada'], array_column($ana['limpiezas'], 'estado'), 'cada una con el estado de su limpieza');
        $this->assertSame(['rechazada'], array_column($berta['limpiezas'], 'estado'));
        $this->assertSame(1, $ana['totales']['piezas']);
        $this->assertSame(1, $berta['totales']['piezas']);
    }

    public function testLaLimpiezaNoDesapareceAlReasignarLaTarjeta(): void
    {
        $this->limpiar($this->hab101, $this->ana);
        $this->aprobar($this->hab101);
        $this->asig->asignarManual($this->hab101, $this->berta, $this->hoy);

        $this->assertSame(0, (int) Database::fetchColumn('SELECT activa FROM asignaciones WHERE usuario_id = ?', [$this->ana]));
        $ana = $this->fila('Ana');
        $this->assertSame(['101'], array_column($ana['limpiezas'], 'numero'), 'la tarjeta se apagó, la limpieza sigue');
        $this->assertSame([], $ana['sin_limpiar']);

        $berta = $this->fila('Berta');
        $this->assertSame([], $berta['limpiezas']);
        $this->assertSame(['101'], array_column($berta['sin_limpiar'], 'numero'), 'quien la recibió y no la limpió');
    }

    public function testElTotalDePiezasCalzaConLimpiadasDeLaFicha(): void
    {
        $this->limpiar($this->hab101, $this->ana);
        $this->rechazar($this->hab101);
        $this->limpiar($this->hab101, $this->ana); // la misma persona la rehace: sigue siendo una
        $this->aprobar($this->hab101);
        $this->limpiar($this->hab102, $this->ana);
        $this->aprobar($this->hab102);
        $this->limpiar($this->area, $this->ana);
        $this->aprobar($this->area);

        $ana = $this->fila('Ana');
        $this->assertCount(4, $ana['limpiezas'], 'se listan todas las limpiezas');
        $this->assertSame(['piezas' => 2, 'areas' => 1], $ana['totales']);

        [$anio, $mes] = array_map('intval', explode('-', substr($this->hoy, 0, 7)));
        $mensual = array_values(array_filter(
            $this->reportes->resumenMensual($anio, $mes, 'ambos'),
            fn (array $f): bool => $f['usuario_id'] === $this->ana
        ));
        $this->assertSame($mensual[0]['limpiadas'], $ana['totales']['piezas']);
    }

    public function testRotulaLaPasadaPorFranjaOPorLaHoraDeInicio(): void
    {
        $this->limpiar($this->hab101, $this->ana);
        $this->aprobar($this->hab101);
        $utc = new \DateTimeZone('UTC');
        $local = new \DateTimeZone(date_default_timezone_get());
        $aUtc = fn (string $hora): string => (new \DateTimeImmutable("{$this->hoy} {$hora}", $local))->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');

        Database::execute('UPDATE ejecuciones_checklist SET timestamp_inicio = ?, timestamp_fin = ?', [$aUtc('15:59:00'), $aUtc('16:20:00')]);
        $limpieza = $this->fila('Ana')['limpiezas'][0];
        $this->assertSame('Mañana', $limpieza['pasada'], 'cuenta la hora de inicio');
        $this->assertSame('16:20', $limpieza['hora_fin']);

        Database::execute('UPDATE ejecuciones_checklist SET timestamp_inicio = ?', [$aUtc('16:00:00')]);
        $this->assertSame('Tarde', $this->fila('Ana')['limpiezas'][0]['pasada']);

        Database::execute("UPDATE asignaciones SET franja = 'noche'");
        $this->assertSame('Noche', $this->fila('Ana')['limpiezas'][0]['pasada'], 'la franja elegida manda');
    }

    public function testUnaLimpiezaSinInspeccionarLoDice(): void
    {
        $this->limpiar($this->hab101, $this->ana);

        $this->assertSame(['sin_inspeccionar'], array_column($this->fila('Ana')['limpiezas'], 'estado'));
    }

    /** @param array<string, mixed> $cuerpo */
    private function req(array $cuerpo, array $query = []): Request
    {
        $r = new Request(metodo: 'POST', path: '/api', cuerpo: $cuerpo, ruta: [], query: $query, cookies: [], headers: []);
        $r->usuario = new Usuario(
            id: $this->sofia,
            rut: '15000001-7',
            nombre: 'Sofía',
            email: null,
            activo: true,
            requiereCambioPwd: false,
            hotelDefault: null,
            temaPreferido: 'claro',
            permisos: ['asignaciones.asignar_manual', 'asignaciones.auto_asignar', 'asignaciones.reordenar_cola_trabajador'],
            roles: ['Supervisora'],
        );
        return $r;
    }

    private function codigo(Response $r): ?string
    {
        return json_decode($r->cuerpo, true)['error']['codigo'] ?? null;
    }

    public function testUnDiaPasadoNoSePuedeModificar(): void
    {
        $ayer = date('Y-m-d', strtotime($this->hoy . ' -1 day'));
        $ctrl = new AsignacionesController();

        foreach ([
            $ctrl->crear($this->req(['habitacion_ids' => [$this->hab101], 'usuario_id' => $this->ana, 'fecha' => $ayer])),
            $ctrl->auto($this->req(['hotel' => 'ambos', 'fecha' => $ayer])),
            $ctrl->reasignar($this->req(['habitacion_id' => $this->hab101, 'usuario_id' => $this->ana, 'fecha' => $ayer])),
            $ctrl->desasignar($this->req(['habitacion_id' => $this->hab101, 'fecha' => $ayer])),
            $ctrl->reordenar($this->req(['usuario_id' => $this->ana, 'fecha' => $ayer, 'orden' => [$this->hab101]])),
        ] as $respuesta) {
            $this->assertSame(409, $respuesta->status, $respuesta->cuerpo);
            $this->assertSame('DIA_CERRADO', $this->codigo($respuesta));
        }
        $this->assertSame(0, (int) Database::fetchColumn('SELECT COUNT(*) FROM asignaciones'));
    }

    public function testHoySeSigueAsignandoYMuestraElTablero(): void
    {
        $ctrl = new AsignacionesController();

        $creada = $ctrl->crear($this->req(['habitacion_ids' => [$this->hab101], 'usuario_id' => $this->ana, 'fecha' => $this->hoy]));
        $this->assertSame(201, $creada->status, $creada->cuerpo);

        $vista = json_decode($ctrl->vista($this->req([], ['fecha' => $this->hoy]))->cuerpo, true)['data'];
        $this->assertArrayNotHasKey('registro', $vista);
    }

    public function testUnDiaPasadoTraeElRegistroConElTableroVacio(): void
    {
        $ayer = date('Y-m-d', strtotime($this->hoy . ' -1 day'));

        $vista = json_decode((new AsignacionesController())->vista($this->req([], ['fecha' => $ayer]))->cuerpo, true)['data'];

        $this->assertSame([], $vista['registro']);
        $this->assertSame([], $vista['sin_asignar']);
        $this->assertSame([], $vista['trabajadores']);
    }
}
