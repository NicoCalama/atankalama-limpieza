<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

/**
 * Lector mínimo de .xlsx (primera hoja), sin dependencias externas: ZipArchive +
 * SimpleXML nativos de PHP. Devuelve cada fila como [columna_letra => valor_texto],
 * la fila 0 incluida (el encabezado; cada llamador decide si la salta).
 *
 * Extraído de los importadores de usuarios y turnos: ambos leían el mismo .xlsx
 * crudo y solo diferían en qué columnas interpretaban.
 *
 * Nota SimpleXML: registerXPathNamespace() NO se hereda a los nodos hijos que
 * devuelve xpath() — cada nodo necesita su propio registro antes de correr xpath()
 * sobre sí mismo. Sin este registro repetido, xpath() devuelve 0 resultados en
 * silencio (con un warning "Undefined namespace prefix").
 */
final class XlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return list<array<string, string>>
     */
    public static function leerFilas(string $rutaArchivo): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($rutaArchivo) !== true) {
            throw new \RuntimeException('No se pudo leer el archivo .xlsx.');
        }

        $sharedStrings = self::leerSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('El archivo .xlsx no tiene hojas legibles.');
        }

        $root = simplexml_load_string($sheetXml);
        if ($root === false) {
            throw new \RuntimeException('El archivo .xlsx está corrupto.');
        }
        $root->registerXPathNamespace('m', self::NS);

        $filas = [];
        foreach ($root->xpath('//m:row') ?: [] as $row) {
            $row->registerXPathNamespace('m', self::NS);
            $celdas = [];
            foreach ($row->xpath('m:c') ?: [] as $c) {
                $columna = preg_replace('/[0-9]+/', '', (string) $c['r']);
                $tipo = (string) $c['t'];
                $valor = isset($c->v) ? (string) $c->v : '';
                if ($tipo === 's' && $valor !== '') {
                    $valor = $sharedStrings[(int) $valor] ?? '';
                }
                $celdas[$columna] = trim($valor);
            }
            $filas[] = $celdas;
        }

        return $filas;
    }

    /** @return list<string> índice => texto */
    private static function leerSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $root = simplexml_load_string($xml);
        if ($root === false) {
            return [];
        }
        $root->registerXPathNamespace('m', self::NS);

        $strings = [];
        foreach ($root->xpath('//m:si') ?: [] as $si) {
            $texto = '';
            $si->registerXPathNamespace('m', self::NS);
            foreach ($si->xpath('.//m:t') ?: [] as $t) {
                $texto .= (string) $t;
            }
            $strings[] = $texto;
        }
        return $strings;
    }
}
