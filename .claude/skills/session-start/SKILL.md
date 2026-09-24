---
name: session-start
description: Inicio de sesion — lee la memoria/estado del proyecto actual y muestra desde donde retomar.
disable-model-invocation: true
---

# Inicio de sesion

0. Detecta donde corres: `echo "$CLAUDE_CODE_REMOTE"`. Si dice `true` estas en una **sesion en la nube**: el repo se clono en una maquina virtual y NO tenes la memoria automatica del proyecto (es local de cada maquina y no viaja; si ves una carpeta de memoria, arranca vacia). El estado mas fresco puede estar solo en la memoria del PC de Nicolas, asi que todo lo que sigue sale de los documentos del repo — y hay que decirlo en el resumen.
1. Lee el `CLAUDE.md` del proyecto (si existe) para entender reglas y convenciones.
2. Localiza y lee los documentos de estado/memoria del proyecto. Busca y lee los que existan, en este orden de prioridad:
   - Solo en local: la memoria automatica del proyecto (indice `MEMORY.md` y los checkpoints mas recientes a los que apunta)
   - `docs/project_memory.md`, `docs/work_log.md`, `docs/handoff*.md` (el mas reciente primero)
   - `plan.md`, `ARCHITECTURE_MAP.md`, `README.md`
   - `implementation/task_tracker.md`, `implementation/user_journeys.md`
   - Cualquier `TODO.md`, `CHANGELOG.md` o nota de progreso reciente
   - En este proyecto, ademas: las ultimas filas de la seccion 11 de `docs/deploy-cpanel.md` (historial de deploys: que version esta en produccion y que quedo abierto) y los `docs/incidente-*.md` recientes
3. Si hay git, revisa el estado real: ultimos commits (`git log --oneline -10`) y cambios sin commitear (`git status`).
4. Indica explicitamente el punto de retome:

## Resumen de inicio de sesion
- **Estado actual**: [que esta hecho / que esta en progreso]
- **Ultimo avance**: [tarea o commit mas reciente]
- **Siguiente tarea**: [que sigue]
- **Bloqueos**: [lista o ninguno]
- **Fuente del estado**: [memoria local + repo, o SOLO el repo si es una sesion en la nube — en ese caso, avisar que puede faltar lo mas reciente]

No empieces a cambiar codigo hasta confirmar con el usuario desde donde retomar.
