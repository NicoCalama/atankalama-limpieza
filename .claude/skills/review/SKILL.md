---
name: review
description: Lanza una revision independiente (subagente con contexto limpio) del ultimo bloque de trabajo.
disable-model-invocation: true
---

# Revision independiente

Lanza un subagente con contexto limpio para revisar el ultimo bloque de trabajo completado.

El subagente debe:
1. Identificar que se completo recientemente: revisar git (`git log`, `git diff`) y/o los documentos de progreso del proyecto (work_log, task_tracker, handoff).
2. Localizar los criterios de aceptacion / checklist del proyecto (p. ej. `docs/checklist.md`, requisitos, o el `CLAUDE.md`) y verificar el trabajo contra ellos.
3. Para cada cambio relevante:
   - Verificar que la funcionalidad este completa de punta a punta (backend + frontend + verificacion, segun aplique).
   - Verificar que se aplicaron las comprobaciones de seguridad del proyecto (auth, autorizacion, secretos, validacion de entrada, queries parametrizadas, escape XSS).
   - Buscar huecos funcionales (estados de error, estados vacios, enlaces de navegacion faltantes).
   - Comprobar que existen tests para los caminos criticos y que pasan.
4. Escribir los hallazgos donde el proyecto registre el trabajo (work_log o un resumen claro en el chat si no hay uno).
5. Marcar cualquier problema critico que deba corregirse antes de continuar.

Si hay problemas criticos, detente y corrigelos antes de avanzar a trabajo nuevo.
