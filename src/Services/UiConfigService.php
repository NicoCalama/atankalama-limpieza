<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Colores;

/**
 * Apariencia configurable de la UI (Ajustes → Colores): colores de las tarjetas
 * por estado de habitación y por hotel. Key-value en #__ui_config (mismo patrón
 * que alertas_config). La supervisora elige un color base; las variantes
 * claro/oscuro se derivan en Colores y se inyectan como CSS custom properties
 * desde el layout — las vistas usan clases semánticas (.chip-estado-*,
 * .hotel-accent-*) definidas en custom.css.
 */
final class UiConfigService
{
    /** Colores por defecto = paleta Tailwind usada hasta ahora. */
    public const DEFAULTS = [
        'color_estado_sucia' => '#eab308',                          // yellow-500
        'color_estado_en_progreso' => '#3b82f6',                    // blue-500
        'color_estado_completada_pendiente_auditoria' => '#6366f1', // indigo-500
        'color_estado_aprobada' => '#22c55e',                       // green-500
        'color_estado_aprobada_con_observacion' => '#22c55e',       // green-500
        'color_estado_rechazada' => '#ef4444',                      // red-500
        'color_hotel_1_sur' => '#14b8a6',                           // teal-500
        'color_hotel_inn' => '#8b5cf6',                             // violet-500
    ];

    /** Etiquetas legibles para el editor de Ajustes. */
    public const ETIQUETAS = [
        'color_estado_sucia' => 'Pendiente (sucia)',
        'color_estado_en_progreso' => 'En progreso',
        'color_estado_completada_pendiente_auditoria' => 'Por auditar',
        'color_estado_aprobada' => 'Aprobada',
        'color_estado_aprobada_con_observacion' => 'Aprobada con observación',
        'color_estado_rechazada' => 'Rechazada',
        'color_hotel_1_sur' => 'Atankalama (1 Sur)',
        'color_hotel_inn' => 'Atankalama INN',
    ];

    /**
     * Colores efectivos: defaults pisados por lo guardado en BD.
     * Resiliente: si la tabla aún no existe (deploy sin migración), devuelve
     * los defaults en vez de romper el layout de toda la app.
     *
     * @return array<string, string> clave => hex
     */
    public function colores(): array
    {
        $colores = self::DEFAULTS;
        try {
            $filas = Database::fetchAll('SELECT clave, valor FROM #__ui_config');
            foreach ($filas as $f) {
                $clave = (string) $f['clave'];
                $valor = (string) $f['valor'];
                if (isset($colores[$clave]) && Colores::validarHex($valor)) {
                    $colores[$clave] = strtolower($valor);
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('ui_config', 'No se pudo leer ui_config (¿falta la migración?); usando defaults', [
                'error' => $e->getMessage(),
            ]);
        }
        return $colores;
    }

    /**
     * Guarda un mapa parcial de colores. Solo claves conocidas; valores hex #rrggbb.
     *
     * @param array<string, string> $colores
     */
    public function guardarColores(array $colores, ?int $actorId = null): void
    {
        $limpios = [];
        foreach ($colores as $clave => $valor) {
            if (!isset(self::DEFAULTS[$clave])) {
                throw new UiConfigException('CLAVE_INVALIDA', "Clave de color desconocida: {$clave}.", 400);
            }
            if (!is_string($valor) || !Colores::validarHex($valor)) {
                throw new UiConfigException('COLOR_INVALIDO', 'Los colores deben ser hex de 6 dígitos, ej: #eab308.', 400);
            }
            $limpios[$clave] = strtolower($valor);
        }
        if ($limpios === []) {
            throw new UiConfigException('SIN_CAMBIOS', 'No llegó ningún color para guardar.', 400);
        }

        $upsert = Database::onConflictUpdate(['clave'], ['valor', 'updated_at', 'updated_by']);
        foreach ($limpios as $clave => $valor) {
            Database::execute(
                "INSERT INTO #__ui_config (clave, valor, updated_at, updated_by)
                 VALUES (?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), ?) {$upsert}",
                [$clave, $valor, $actorId]
            );
        }

        Logger::audit($actorId, 'ui_config.editar_colores', 'ui_config', null, ['claves' => array_keys($limpios)]);
    }

    /**
     * Bloque CSS con las custom properties de color (claro en :root, oscuro en
     * .dark). Lo inyecta views/layout.php en el <head> de cada página.
     */
    public function cssVars(): string
    {
        $colores = $this->colores();

        $claro = [];
        $oscuro = [];

        foreach ($colores as $clave => $hex) {
            if (str_starts_with($clave, 'color_estado_')) {
                $slug = substr($clave, strlen('color_estado_'));
                $v = Colores::variantes($hex);
                $claro[] = "--ce-{$slug}-bg: {$v['bg']}; --ce-{$slug}-fg: {$v['fg']};";
                $oscuro[] = "--ce-{$slug}-bg: {$v['bgDark']}; --ce-{$slug}-fg: {$v['fgDark']};";
            } else { // color_hotel_*
                $slug = substr($clave, strlen('color_hotel_'));
                $a = Colores::variantesAcento($hex);
                $claro[] = "--ch-{$slug}-borde: {$a['borde']}; --ch-{$slug}-tinte: {$a['tinte']}; --ch-{$slug}-texto: {$a['texto']};";
                $oscuro[] = "--ch-{$slug}-tinte: {$a['tinteDark']}; --ch-{$slug}-texto: {$a['textoDark']};";
            }
        }

        return ":root {\n    " . implode("\n    ", $claro) . "\n}\n.dark {\n    " . implode("\n    ", $oscuro) . "\n}";
    }
}
