# Backlog / Trabajo futuro

Ideas y mejoras acordadas pero no incluidas en el MVP actual. Cada entrada
tiene fecha, contexto y estado, para no perder decisiones tomadas en el camino.

---

## Modo de orden automático de la cola del trabajador

**Fecha:** 2026-07-05
**Estado:** 📌 Pendiente (post-MVP)
**Origen:** Conversación con Nicolás al implementar el flujo "una habitación a la vez".

### Contexto

Se implementó el flujo en que el trabajador de limpieza ya **no ve la lista
completa** de sus habitaciones, sino solo la **habitación actual**, una a la vez.
Cuando cierra una habitación, el sistema le revela la siguiente. Esto resuelve el
problema de calidad de que los trabajadores aceptaban todas de golpe ("aceptar,
aceptar, aceptar") o las hacían en grupos.

En esta primera versión, **la supervisora sigue controlando manualmente el orden
de la cola** (`orden_en_cola` en las asignaciones) y ve la lista completa. El
trabajador solo consume la punta de la cola.

### Qué se quiere a futuro

Un **modo de orden automático**: que el sistema ordene por sí solo qué habitación
le toca a cada trabajador, en base a las habitaciones que la supervisora les
asigna, sin que ella tenga que ordenar la cola a mano.

Puntos a definir cuando se retome:

- Criterios de orden automático (prioridad de check-in/check-out de Cloudbeds,
  cercanía física entre habitaciones/pisos, tiempo promedio del trabajador,
  balance de carga entre trabajadores, urgencia por reservas entrantes).
- Si la supervisora puede **override** manual sobre el orden automático (recomendado: sí).
- Interacción con la válvula de escape "No puedo terminar ahora" (una habitación
  saltada debe reordenarse de forma inteligente, no solo ir al final).
- Si el orden se recalcula en vivo a medida que entran/salen reservas.

### Relación con lo ya construido

- La lógica de "habitación actual" ya vive en `HomeController::trabajador()`.
- El orden de la cola ya se apoya en `orden_en_cola` (asignaciones) →
  ver `AsignacionService::colaDelTrabajador()`.
- El modo automático reemplazaría/complementaría ese ordenamiento manual.
