---
name: iterate
description: Modo iteracion — aplicar cambios, mejoras o corregir bugs sobre un proyecto ya en marcha.
disable-model-invocation: true
---

# Modo iteracion

El usuario solicita un cambio sobre un proyecto existente.

## Antes de cualquier cambio
1. Lee con cuidado la peticion del usuario en la conversacion.
2. Lee el estado actual del proyecto (memoria / handoff / plan / work_log, segun existan).
3. Lee el `CLAUDE.md` del proyecto para reglas y convenciones.
4. Clasifica el cambio: correccion de bug, mejora, nueva funcionalidad o hueco de planificacion.

## Correccion de bug
Identifica la causa raiz -> evalua riesgo de regresion -> corrige -> prueba la correccion -> verifica que el flujo afectado siga funcionando de punta a punta -> registra el cambio.

## Mejora
Identifica el flujo afectado -> indica los cambios necesarios (backend/frontend/ambos) -> evalua riesgo de regresion -> actualiza docs de diseno si hace falta -> implementa -> verifica de punta a punta -> registra.

## Nueva funcionalidad
Define con criterios de aceptacion -> revisa impacto en lo existente -> presenta el alcance al usuario para aprobacion -> implementa siguiendo el flujo estandar del proyecto.

## Hueco de planificacion
Identifica lo que falto -> definelo como tarea -> implementalo siguiendo el flujo estandar (ya esta dentro del alcance).

## Siempre
- Aplica las comprobaciones de seguridad del proyecto a todo codigo modificado.
- Verifica que la funcionalidad existente sigue funcionando (regresion).
- Actualiza la documentacion de estado/diseno si cambio el esquema, los endpoints o la UI.

## Nunca
- Cambiar comportamiento existente sin documentar por que.
- Saltar la verificacion de regresion.
- Modificar el esquema de BD o anadir endpoints sin actualizar la doc correspondiente.
