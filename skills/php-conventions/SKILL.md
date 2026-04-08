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

## Reglas

- Siempre `declare(strict_types=1);`
- Siempre `final class` salvo herencia justificada
- Siempre `readonly` en propiedades inyectadas
- Siempre prepared statements con PDO
- Siempre tipar parámetros y retornos
- Errores con excepciones, no `return false`
- Excepciones del dominio en `src/Exceptions/`
- **NUNCA** chequear rol directamente, SIEMPRE chequear permisos con `$usuario->tienePermiso('codigo')`
