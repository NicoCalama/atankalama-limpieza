<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

use Atankalama\Limpieza\Core\Response;
use Shuchkin\SimpleXLSXGen;

/**
 * Genera un .xlsx descargable a partir de filas planas. Mismo rol que el patrón CSV de
 * ReportesController, pero devolviendo un Excel real (composer require shuchkin/simplexlsxgen,
 * sin dependencias). Response::cuerpo es un string binary-safe: los bytes del xlsx viajan igual
 * que el texto de un CSV.
 */
final class ExcelExport
{
    /**
     * @param list<list<string|int|float|null>> $filas primera fila = encabezados
     */
    public static function responder(array $filas, string $nombreArchivo): Response
    {
        $xlsx = SimpleXLSXGen::fromArray($filas);

        // Nombre saneado: valores como numero de habitacion llegan aqui como texto libre
        // editable por admin y no deben poder romper el parametro filename del header
        // (comillas, backslash, saltos de linea).
        $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombreArchivo) ?? 'archivo.xlsx';

        return (new Response(200, (string) $xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
            ->conHeader('Content-Disposition', "attachment; filename=\"{$nombreSeguro}\"");
    }
}
