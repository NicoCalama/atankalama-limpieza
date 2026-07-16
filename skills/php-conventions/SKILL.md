# Convenciones PHP 8.2 — Atankalama Limpieza

## Cuándo usar esta skill

Cuando escribas código PHP en este proyecto.

## Estructura de un Controller

```php
<?php
declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\{Request, Response};
use Atankalama\Limpieza\Services\HabitacionService;

final class HabitacionesController
{
    public function __construct(
        private readonly HabitacionService $habitaciones,
    ) {}

    public function listar(Request $req): Response
    {
        $hotelId = $req->query('hotel_id');
        $estado = $req->query('estado');

        $resultado = $this->habitaciones->listar($hotelId, $estado);

        return Response::json(['ok' => true, 'data' => $resultado]);
    }
}
```

## Estructura de un Service

```php
<?php
declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

final class HabitacionService
{
    public function __construct(
        private readonly \PDO $db,
    ) {}

    public function listar(?int $hotelId, ?string $estado): array
    {
        $sql = 'SELECT * FROM habitaciones WHERE 1=1';
        $params = [];
        if ($hotelId !== null) {
            $sql .= ' AND hotel_id = :hotel_id';
            $params['hotel_id'] = $hotelId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
```

## Respuestas JSON estandarizadas

### Éxito
```json
{ "ok": true, "data": { ... } }
```

### Error de validación
```json
{
  "ok": false,
  "error": {
    "codigo": "VALIDATION_ERROR",
    "mensaje": "El RUT no es válido",
    "campos": { "rut": "Dígito verificador incorrecto" }
  }
}
```

### Error de permisos
```json
{
  "ok": false,
  "error": {
    "codigo": "FORBIDDEN",
    "mensaje": "No tienes permiso para realizar esta acción"
  }
}
```

### Error interno
```json
{
  "ok": false,
  "error": {
    "codigo": "INTERNAL_ERROR",
    "mensaje": "Ocurrió un error inesperado, intenta nuevamente"
  }
}
```

## Middleware de permisos dinámicos

Ejemplo de uso:
```php
// En la definición de rutas
$router->post('/api/habitaciones/{id}/asignar', [
    'middleware' => ['auth', 'permission:habitaciones.asignar'],
    'handler' => [AsignacionController::class, 'asignar'],
]);
```

El middleware `PermissionCheck` lee el permiso del parámetro y chequea `$usuario->tienePermiso('habitaciones.asignar')`.

## Fechas: la BD habla UTC, el negocio habla Santiago

Los timestamps (`timestamp_inicio`, `created_at`, …) se guardan en **UTC** con formato
ISO `YYYY-MM-DDTHH:MM:SS.sssZ`. Las fechas del negocio (el filtro "hoy" de un reporte,
`asignaciones.fecha`) son **locales de Santiago**. Mezclarlas silenciosamente es un bug
real que ya pasó una vez: el trabajo del turno de tarde (termina 22:00, que en UTC ya es
el día siguiente) se acreditaba al día equivocado.

```php
// ❌ NUNCA: DATE(col) es el día UTC; compararlo con una fecha local desfasa 4 horas
"WHERE DATE(ec.timestamp_inicio) BETWEEN ? AND ?", [$desde, $hasta]

// ✅ SIEMPRE: convertí el RANGO en PHP y compará el timestamp crudo
[$desde, $hasta] = Fechas::rangoUtc($desde, $hasta);   // o rangoUtcDelDia($fecha)
"WHERE ec.timestamp_inicio >= ? AND ec.timestamp_inicio < ?", [$desde, $hasta]
```

- El límite superior es **exclusivo** (`<`, no `BETWEEN`): si no, se pierden los últimos
  milisegundos del día.
- La conversión va en PHP porque `DateTimeZone` conoce el horario de verano (Chile alterna
  UTC-4 / UTC-3); un offset fijo en SQL se rompe dos veces al año, y SQLite y MariaDB no
  comparten una función de conversión portable.
- Para **agrupar** por día (`COUNT(DISTINCT DATE(...))`) no alcanza el rango: usá
  `Fechas::fechaLocalDeUtc()` sobre las filas en PHP.
- Ojo con las columnas que **ya son fechas locales** (`asignaciones.fecha`): esas se
  comparan directo, sin convertir.

## Reglas

- Siempre `declare(strict_types=1);`
- Siempre `final class` salvo herencia justificada
- Siempre `readonly` en propiedades inyectadas
- Siempre prepared statements con PDO
- Siempre tipar parámetros y retornos
- Errores con excepciones, no `return false`
- Excepciones del dominio en `src/Exceptions/`
- **NUNCA** chequear rol directamente, SIEMPRE chequear permisos con `$usuario->tienePermiso('codigo')`
- **NUNCA** comparar `DATE(<columna_timestamp>)` contra una fecha local: convertí el rango con `Helpers\Fechas`
