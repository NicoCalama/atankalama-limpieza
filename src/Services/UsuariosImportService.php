<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Helpers\Rut;
use Atankalama\Limpieza\Helpers\XlsxReader;

/**
 * Importa usuarios en lote desde el calendario semanal .xlsx (columnas A=DNI, B=Nombre;
 * el resto de columnas son turnos por fecha y se ignoran acá — eso es alcance de
 * TurnosImportService, no de este servicio).
 */
final class UsuariosImportService
{
    public function __construct(
        private readonly UsuarioService $usuarios = new UsuarioService(),
    ) {
    }

    /**
     * Lee columnas A (DNI) y B (Nombre) de la primera hoja de un .xlsx.
     *
     * @return list<array{rut:string, nombre:string}>
     */
    public function parsearXlsx(string $rutaArchivo): array
    {
        $todas = XlsxReader::leerFilas($rutaArchivo);
        $filas = [];
        foreach (array_slice($todas, 1) as $celdas) { // salta encabezado (DNI, Nombre, fecha1, fecha2, ...)
            $rut = trim($celdas['A'] ?? '');
            $nombre = trim($celdas['B'] ?? '');
            if ($rut === '' && $nombre === '') {
                continue;
            }
            $filas[] = ['rut' => $rut, 'nombre' => $nombre];
        }
        return $filas;
    }

    /**
     * Separa las filas leídas en: nuevas a crear, omitidas por RUT ya existente,
     * omitidas por RUT inválido/nombre vacío. Duplicados dentro del propio archivo
     * cuentan una sola vez.
     *
     * @param list<array{rut:string, nombre:string}> $filas
     * @return array{a_crear:int, usuarios_nuevos:list<array{rut:string,nombre:string}>, omitidos_duplicado:list<array{rut:string,nombre:string}>, omitidos_invalido:list<array{rut:string,nombre:string}>}
     */
    public function preview(array $filas): array
    {
        $nuevos = [];
        $omitidosDuplicado = [];
        $omitidosInvalido = [];
        $vistos = [];

        foreach ($filas as $fila) {
            $rutNorm = Rut::normalizar($fila['rut']);
            $nombre = trim($fila['nombre']);

            if (!Rut::validar($rutNorm) || $nombre === '') {
                $omitidosInvalido[] = ['rut' => $fila['rut'], 'nombre' => $nombre];
                continue;
            }
            if (isset($vistos[$rutNorm])) {
                continue;
            }
            $vistos[$rutNorm] = true;

            if ($this->usuarios->buscarPorRut($rutNorm) !== null) {
                $omitidosDuplicado[] = ['rut' => $rutNorm, 'nombre' => $nombre];
                continue;
            }

            $nuevos[] = ['rut' => $rutNorm, 'nombre' => $nombre];
        }

        return [
            'a_crear' => count($nuevos),
            'usuarios_nuevos' => $nuevos,
            'omitidos_duplicado' => $omitidosDuplicado,
            'omitidos_invalido' => $omitidosInvalido,
        ];
    }

    /**
     * Crea cada usuario nuevo con el rol único elegido, reusando UsuarioService::crear()
     * (misma validación, generación de password temporal y auditoría que el alta manual).
     * Un error en una fila no aborta el lote: se reporta y se sigue con las demás.
     *
     * @param list<array{rut:string, nombre:string}> $usuariosNuevos
     * @return array{creados:list<array{id:int,rut:string,nombre:string,password_temporal:string}>, errores:list<string>}
     */
    public function importar(array $usuariosNuevos, int $rolId, int $actorId, PasswordService $passwords): array
    {
        $creados = [];
        $errores = [];

        foreach ($usuariosNuevos as $fila) {
            try {
                $resultado = $this->usuarios->crear([
                    'rut' => $fila['rut'],
                    'nombre' => $fila['nombre'],
                    'roles' => [$rolId],
                ], $actorId, $passwords);

                $creados[] = [
                    'id' => $resultado['usuario']->id,
                    'rut' => $resultado['usuario']->rut,
                    'nombre' => $resultado['usuario']->nombre,
                    'password_temporal' => $resultado['password_temporal'],
                ];
            } catch (UsuarioException $e) {
                $errores[] = "RUT {$fila['rut']} ({$fila['nombre']}): " . $e->getMessage();
            }
        }

        Logger::audit($actorId, 'usuarios.importar_excel', 'usuario', null, [
            'creados' => count($creados), 'errores' => count($errores), 'rol_id' => $rolId,
        ]);

        return ['creados' => $creados, 'errores' => $errores];
    }
}
