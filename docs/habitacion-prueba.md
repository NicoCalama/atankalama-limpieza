# Habitación de prueba (v7.5)

Pedido de Nicolás (10/10/2026): una habitación para hacer los smoke tests en producción sin afectar a
Cloudbeds ni al proceso del hotel. Antes se probaba con piezas reales (aprobar una escribía `clean` en
Cloudbeds) o con piezas «test» de Cloudbeds que ya no existen.

## Qué es

- Una habitación **«PRUEBA» por hotel** (`habitaciones.es_prueba = 1`), de huésped (`es_espacio_comun = 0`),
  **sin `cloudbeds_room_id`**, con el tipo de una pieza real y por lo tanto con su checklist.
- La crea la migración (`scripts/migrate-add-habitacion-prueba.php`) o el SQL del runbook (§11.31). No hay
  pantalla para crearla ni borrarla: es una por hotel y queda fija.
- Nace «Aprobada». Al asignarla pasa a sucia como cualquier pieza (`AsignacionService::asignarManual`).

## Decisiones de Nicolás (10/10/2026)

1. **La ve solo quien tiene `habitaciones.ver_prueba`** (Admin por defecto; se le puede dar a otro rol desde
   Roles y permisos, p. ej. para una capacitación). Para el resto no existe.
2. **Avisos silenciados:** no avisa a nadie más que a quien hace la prueba. Sus tickets nunca llegan a
   Novedades.
3. **Una por hotel**, para probar los filtros de cada uno.

## Comportamiento

**Cloudbeds.** Nunca se le escribe: no tiene `cloudbeds_room_id`, y además
`CloudbedsSyncService::escribirEstadoRoomCondition` corta por `es_prueba` (con un INFO, sin la advertencia de
«escritura omitida»). El sync la ignora (busca por roomID). El import de inventario nunca la vincula a una
pieza de Cloudbeds: si apareciera una con el mismo número, se reporta como `colision_saltada`.

**Quién la ve** (sin el permiso no aparece):

| Dónde | Código |
|---|---|
| Habitaciones (y el buscador de «Nuevo ticket», Edificios) | `HabitacionService::listar(..., incluirPrueba)` |
| Asignaciones: pool, «Volver a limpiar» y colas | `AsignacionService::vistaConsolidada(..., incluirPrueba)` |
| Cola de una trabajadora vista por la supervisora; equipo del Inicio de la supervisora | `HabitacionesPrueba::filtrar` |
| Bandeja de Inspección e Inicio de Recepción | `AuditoriaService::bandejaPendientes(..., incluirPrueba)` |
| Tickets de esa pieza | `TicketService::listar(['incluir_prueba' => …])` |

**A quién se le asigna:** solo a quien tiene el permiso (`AsignacionService::asignarManual` → 403
`HABITACION_DE_PRUEBA`). Así su aviso de asignación, la nota para la mucama o el «vuelve a tu cola» del NO de
Recepción solo le llegan a quien prueba.

**Fuera de lo que mide o reparte:** auto-asignar; todos los KPIs de Reportes, la ficha, el bono, el Excel, las
inspectoras, la «Calidad» de supervisoras y el correo de las 23:50 (`ReportesService::hotelCond` y
`hotelCondCreditos`); la producción de Recepción y la sección «Inspección pre-entrega»
(`RevisionEntregaService::filtroPeriodo`); los contadores, tiempos y eficiencia del Inicio (`HomeService`); las
alertas predictivas (piezas restantes y tiempo promedio personal).

**Sin avisos a nadie más:** el rechazo no levanta la alerta P1 ni el push a supervisoras (sí le avisa a quien la
limpió); «No puedo limpiarla» no levanta la P2; un NO de Recepción no avisa a las supervisoras; un ticket no
levanta la P2, no avisa a sus responsables ni a supervisión al comentar, y **no va a Novedades** ni al crearlo
ni al cerrarlo; el aviso nocturno de «limpieza sin terminar» la deja fuera.

**Lo que sigue igual:** el cierre de las 15:50 y de las 23:55 la aprueba sola si quedó por inspeccionar (sin
efecto afuera); la historia de la pieza, la auditoría y los registros se guardan como en cualquier pieza.

## Para hacer una prueba

1. Abrir «PRUEBA» desde Habitaciones y tocar **«Asignármela para probar»** (quien tiene el permiso y puede asignar;
   no hace falta turno: así no se le da turno al Admin, que dispararía alertas predictivas). Queda sucia y en la
   cola de hoy. También se puede asignar desde Asignaciones a otra persona con el permiso y turno ese día.
2. Limpiarla con el checklist (rige el mínimo de 3 minutos de cualquier limpieza), inspeccionarla, hacer el NO de
   Recepción, dejarle la nota, levantar un ticket, saltarla o reasignarla, según lo que se quiera probar.
3. Para repetir, «Asignármela para probar» de nuevo: si quedó aprobada o rechazada, pasa a sucia.

Ver `src/Services/HabitacionesPrueba.php` y `tests/Integration/HabitacionPruebaTest.php`.
