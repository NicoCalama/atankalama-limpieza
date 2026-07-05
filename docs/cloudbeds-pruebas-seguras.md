# Pruebas seguras de la integración con Cloudbeds

> **Objetivo:** probar la conexión local ↔ Cloudbeds sin riesgo de romper la
> plataforma de producción ni afectar la operación diaria del hotel.
> Última actualización: 2026-06-30.

## Punto de partida: el riesgo real es bajo

La app hace **solo 3 llamadas** a Cloudbeds (ver `src/Services/CloudbedsClient.php`):

| Llamada | Tipo | Riesgo |
|---|---|---|
| `GET /getRooms` (`obtenerHabitaciones`) | Lectura | Ninguno — no puede alterar nada |
| `GET /getHousekeepingStatus` (`obtenerEstadosHabitaciones`) | Lectura | Ninguno |
| `POST /postHousekeepingStatus` (`actualizarEstadoHabitacion`) | Escritura | Solo cambia el **flag de limpieza** (clean/dirty) de una pieza. Reversible desde recepción. |

**No toca** reservas, tarifas, disponibilidad, huéspedes ni pagos. El peor caso de
un bug es una habitación con el estado de limpieza equivocado — recepción lo revierte.

## Capas de seguridad (combinables)

1. **Solo lecturas (riesgo cero de escritura).** Llamar únicamente a los dos `GET`.
   Valida credenciales, `propertyID`, red/auth/parseo y mapeo de habitaciones sin
   poder modificar nada. Cubre ~80-90% del "¿funciona la conexión?".
   Implementado en `scripts/cloudbeds-read-test.php`.
2. **Modo simulación (`CLOUDBEDS_DRY_RUN`).** Flag que hace que
   `actualizarEstadoHabitacion()` **loggee el payload exacto que enviaría, sin
   enviarlo**. Permite correr el flujo completo y verificar la escritura (roomID,
   propertyID, estado) antes de disparar una real. *(Implementado el 30/06/2026;
   default `false` en `.env.example`. Las lecturas no se ven afectadas.)*
3. **Cuenta de prueba / sandbox de Cloudbeds.** Cloudbeds da cuentas con datos
   ficticios. Se piden a `integrations@cloudbeds.com` o vía "Request your sandbox".
   El `.env` de testing apunta a esa propiedad → hasta las escrituras reales caen
   en una propiedad desechable. **Estándar de oro para probar escrituras.**
4. **Credencial de solo-lectura.** Crear la API key (Manage → Apps & Marketplace →
   API Credentials) bajo un usuario con rol de solo lectura → garantía a nivel
   plataforma de que ni un bug puede escribir. (Confirmar scopes en la UI.)
5. **Una sola pieza desechable (si hay que escribir en prod).** Escribir solo a un
   `roomID` fuera de servicio / no vendido esa noche. Marcar y revertir. Coordinado
   con recepción.

### Barandas operativas
- **Rate limit: 5 req/s** por propiedad — exceder puede suspender la credencial.
  El cliente ya tiene backoff 1s/2s/4s; evitar loops que martillen.
- La app **ya loggea toda llamada** (sin header Authorization) → auditoría y reversa.
- Probar en horario de baja ocupación.

## Plan recomendado (paso a paso)

1. ✅ **Implementar `CLOUDBEDS_DRY_RUN`** (capa 2) — hecho el 30/06/2026.
2. ✅ **API key + propertyID reales en `.env` local** — hecho (3ª sesión del 30/06).
3. ✅ **Test de lecturas** contra la propiedad real (capa 1) — hecho. Corre limpio en
   ambas propiedades (`php scripts/cloudbeds-read-test.php`).
4. **Flujo completo en dry-run** → revisar los payloads logueados. *(Listo para correr:
   poner `CLOUDBEDS_DRY_RUN=true` y ejercitar aprobar-auditoría → ver el log `cloudbeds`
   con "DRY-RUN: escritura simulada".)*
5. En paralelo, **pedir la cuenta de prueba** a Cloudbeds.
6. Con (4) impecable y/o sobre la cuenta de prueba, **habilitar una escritura real**
   (o en prod, sobre una sola pieza desechable).

## Hallazgos de la validación de lectura (30/06/2026)

- **`getRoomsStatus` no existe (404).** El endpoint real para leer estados de
  limpieza es **`getHousekeepingStatus`**. Corregido en `obtenerEstadosHabitaciones()`.
  El cliente enmascaraba el 404 (`json()` sobre HTML → `[]`), así que el sync entrante
  reportaba "éxito / 0 registros" en silencio. Endurecido: el sync ahora exige
  `success=true` o cuenta error + alerta P0.
- **`getRooms` está paginado** (`count=20`, `total=99` en 209760): la app solo conocía
  20 de las **99 reales** (1_sur) / 57 (inn), todas `isPrivate=true` y no virtuales.
  **`obtenerHabitaciones()` ya pagina** (itera `pageNumber`/`pageSize` hasta `total`);
  el read-test reporta 99/99 y 57/57. **Pendiente aparte — import de inventario:** la app
  sigue con inventario demo (20 piezas ficticias, `cloudbeds_room_id=NULL`); traer las 156
  reales necesita un pipeline nuevo con decisiones de producto → ver
  `docs/cloudbeds-import-inventario.md`.

## Notas de implementación

- La auth actual es **API key (Bearer) sobre v1.1**. Cloudbeds también ofrece OAuth
  2.0 con scopes para integraciones nuevas; para keys propias de la propiedad, el
  camino es la UI de API Credentials.
- Dos keys distintas, una por propiedad: `CLOUDBEDS_API_KEY_INN` y
  `CLOUDBEDS_API_KEY_PRINCIPAL`. Nunca mezclar keys entre propiedades.

## Fuentes
- https://developers.cloudbeds.com/
- https://developers.cloudbeds.com/docs/housekeeping-staff-management
- https://developers.cloudbeds.com/docs/faq
- https://developers.cloudbeds.com/docs/property-and-group-account-api-access
- https://developers.cloudbeds.com/docs/about-cloudbeds-api
