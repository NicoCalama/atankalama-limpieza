<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Tests\Integration;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\AsignacionService;
use Atankalama\Limpieza\Services\ChecklistService;
use Atankalama\Limpieza\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Checklist por TIPO real + override opcional por hotel + red anti-500. Ver docs/checklist.md.
 */
final class ChecklistPorHotelTest extends TestCase
{
    private ChecklistService $svc;
    private int $hotel1SurId;
    private int $hotelInnId;

    protected function setUp(): void
    {
        TestDatabase::recrear();
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('1_sur', '1 Sur')");
        Database::execute("INSERT INTO hoteles (codigo, nombre) VALUES ('inn', 'INN')");
        $this->hotel1SurId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='1_sur'")['id'];
        $this->hotelInnId = (int) Database::fetchOne("SELECT id FROM hoteles WHERE codigo='inn'")['id'];
        $this->svc = new ChecklistService();
    }

    /** Crea un tipo con su checklist default compartido. @return array{0:int,1:int} [tipoId, templateId] */
    private function tipoConTemplate(string $nombre): array
    {
        Database::execute('INSERT INTO tipos_habitacion (nombre) VALUES (?)', [$nombre]);
        $tipoId = Database::lastInsertId();
        $tid = $this->svc->crearTemplateDefaultParaTipo($tipoId, $nombre, null, null);
        return [$tipoId, $tid];
    }

    private function habitacion(int $hotelId, string $numero, int $tipoId): int
    {
        Database::execute(
            "INSERT INTO habitaciones (hotel_id, numero, tipo_habitacion_id, estado) VALUES (?, ?, ?, 'sucia')",
            [$hotelId, $numero, $tipoId]
        );
        return Database::lastInsertId();
    }

    /** Payload de edición SIN ids (los ítems se envían como nuevos). @return list<array<string,mixed>> */
    private function payloadDe(int $templateId): array
    {
        $p = [];
        foreach ($this->svc->itemsDelTemplate($templateId) as $i) {
            $p[] = [
                'descripcion' => $i['descripcion'],
                'obligatorio' => (int) $i['obligatorio'] === 1,
                'creditos' => (int) $i['creditos'],
            ];
        }
        return $p;
    }

    // -----------------------------------------------------------------------

    public function testToggleSeGuardaYSeLee(): void
    {
        $this->assertFalse($this->svc->tiposPorHotelActivo());
        $this->svc->setTiposPorHotel(true);
        $this->assertTrue($this->svc->tiposPorHotelActivo());
        $this->svc->setTiposPorHotel(false);
        $this->assertFalse($this->svc->tiposPorHotelActivo());
    }

    public function testIniciarSinTemplateCreaElDefaultYNoTira500(): void
    {
        // Tipo real recién detectado, todavía sin checklist (aún no se corrió el import).
        Database::execute("INSERT INTO tipos_habitacion (nombre) VALUES ('Sin checklist')");
        $tipoId = Database::lastInsertId();
        $habId = $this->habitacion($this->hotel1SurId, '101', $tipoId);

        [$usuarioId] = TestDatabase::crearUsuario('11111111-1', 'Ana', 'Trabajador');
        (new AsignacionService())->asignarManual($habId, $usuarioId, '2026-07-22');

        $this->assertNull($this->svc->templateParaTipo($tipoId), 'el tipo no debería tener checklist todavía');

        $ejec = $this->svc->iniciarEjecucion($habId, $usuarioId, '2026-07-22');
        $this->assertSame('en_progreso', $ejec->estado);

        // La red anti-500 creó el checklist default compartido del tipo.
        $this->assertNotNull($this->svc->templateParaTipo($tipoId));
        $this->assertGreaterThan(0, count($this->svc->itemsDelTemplate($ejec->templateId)));
    }

    public function testOverridePorHotelSoloAplicaConElToggleActivo(): void
    {
        [$tipoId, $sharedTid] = $this->tipoConTemplate('Premium doble');

        // Editar bajo el ámbito de INN crea el override sin tocar el compartido.
        $this->svc->setTiposPorHotel(true);
        $creada = $this->svc->editarTemplate($sharedTid, null, $this->payloadDe($sharedTid), null, 'inn');
        $overrideTid = $creada['template_id'];
        $this->assertNotSame($sharedTid, $overrideTid);

        // El compartido sigue vigente (sin hotel → siempre el compartido).
        $this->assertSame($sharedTid, $this->svc->templateParaTipo($tipoId));

        // Toggle ON: INN resuelve al override; 1_sur cae al compartido.
        $this->assertSame($overrideTid, $this->svc->templateParaTipo($tipoId, $this->hotelInnId));
        $this->assertSame($sharedTid, $this->svc->templateParaTipo($tipoId, $this->hotel1SurId));

        // Toggle OFF: el override se ignora, todos comparten (no-destructivo).
        $this->svc->setTiposPorHotel(false);
        $this->assertSame($sharedTid, $this->svc->templateParaTipo($tipoId, $this->hotelInnId));
        $this->assertSame($sharedTid, $this->svc->templateParaTipo($tipoId, $this->hotel1SurId));
    }

    public function testSegundoGuardadoBajoHotelVersionaElOverrideNoDuplica(): void
    {
        [$tipoId, $sharedTid] = $this->tipoConTemplate('Premium triple');
        $this->svc->setTiposPorHotel(true);

        $override = $this->svc->editarTemplate($sharedTid, null, $this->payloadDe($sharedTid), null, 'inn');
        $override2 = $this->svc->editarTemplate($override['template_id'], null, $this->payloadDe($override['template_id']), null, 'inn');

        $this->assertSame(2, $override2['version']);
        $activos = (int) Database::fetchOne(
            'SELECT COUNT(*) c FROM checklists_template WHERE tipo_habitacion_id = ? AND hotel_id = ? AND activo = 1',
            [$tipoId, $this->hotelInnId]
        )['c'];
        $this->assertSame(1, $activos, 'debe quedar un solo override activo por (tipo, hotel)');
    }

    public function testListarPorHotelMarcaHeredadoYLuegoElOverride(): void
    {
        [, $sharedTid] = $this->tipoConTemplate('Premium suite');
        $this->svc->setTiposPorHotel(true);

        // Sin override: se muestra el compartido como base, marcado heredado.
        $lista = $this->svc->listarTemplates('inn');
        $this->assertCount(1, $lista);
        $this->assertSame(1, (int) $lista[0]['heredado']);
        $this->assertSame($sharedTid, (int) $lista[0]['id']);

        // Tras crear el override, la vista de INN muestra el override (no heredado).
        $creada = $this->svc->editarTemplate($sharedTid, null, $this->payloadDe($sharedTid), null, 'inn');
        $lista2 = $this->svc->listarTemplates('inn');
        $this->assertSame(0, (int) $lista2[0]['heredado']);
        $this->assertSame($creada['template_id'], (int) $lista2[0]['id']);

        // La vista compartida (sin hotel) siempre muestra el compartido.
        $compartida = $this->svc->listarTemplates(null);
        $this->assertSame($sharedTid, (int) $compartida[0]['id']);
        $this->assertSame(0, (int) $compartida[0]['heredado']);
    }
}
