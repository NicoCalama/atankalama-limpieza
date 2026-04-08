# Cloudbeds API — Skill para Atankalama Limpieza

## Cuándo usar esta skill

Cuando trabajes en cualquier código que se conecte con la API de Cloudbeds.

## Autenticación

La API usa API keys por propiedad, en header `Authorization: Bearer <key>`. Hay DOS keys distintas:
- `CLOUDBEDS_API_KEY_INN` para Hotel Atankalama Inn
- `CLOUDBEDS_API_KEY_PRINCIPAL` para Hotel Atankalama (1 Sur 858)

Cada key tiene su `propertyId` asociado en `.env`. NUNCA mezclar keys entre propiedades.

## Endpoints clave para el MVP

### Listar habitaciones con estado de limpieza
- `GET /housekeeping/rooms?propertyID={id}&status=dirty`

### Actualizar estado de habitación
- `PUT /housekeeping/rooms/{roomId}` con body `{ "status": "clean" }` o `{ "status": "dirty" }`
- Respuesta exitosa: 200 con el objeto actualizado
- Errores: 401 (key inválida), 404 (habitación no existe), 422 (estado inválido), 429 (rate limit), 5xx

## Manejo de errores obligatorio

- Timeout 10s por request
- 3 reintentos con backoff exponencial (1s, 2s, 4s)
- Si tras los reintentos sigue fallando: encolar en `cloudbeds_sync_queue` y retornar error al caller
- Loggear TODA llamada en `logs_sistema` (sin headers Authorization)

## Estructura del cliente PHP

Vive en `src/Services/Cloudbeds/CloudbedsClient.php`. Debe:
- Recibir el `propertyKey` ('inn' o 'principal') por constructor
- Métodos: `listarHabitacionesSucias()`, `marcarComoLimpia(roomId)`, `marcarComoSucia(roomId)`
- Dependencias por constructor (DI), no globales

## Reglas

- NUNCA hardcodear API keys
- NUNCA loggear headers Authorization
- SIEMPRE validar respuestas antes de usarlas
- SIEMPRE registrar la operación en logs
