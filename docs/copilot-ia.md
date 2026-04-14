# Copilot IA

**Versión:** 1.0 — 2026-04-14

Documenta el copilot conversacional basado en Claude API con tool use, permisos por rol, UI (FAB + panel), soporte de voz y persistencia.

---

## 1. Filosofía

El copilot es una interfaz conversacional (texto + voz) para que cualquier usuario interactúe con la app. Dos niveles:

- **Nivel 1 — consultas** (read-only): "¿Cuántas habitaciones me quedan?", "Muéstrame las rechazadas hoy".
- **Nivel 2 — acciones** (writes): "Reasigna la habitación 203 a Carmen", "Aprueba la 105".

Las capacidades del copilot para cada usuario se derivan **dinámicamente** de sus permisos RBAC. Si el usuario no tiene `asignaciones.asignar_manual`, la tool "reasignar" no está disponible para su copilot.

---

## 2. Stack

- **Claude API** (messages endpoint con tool use).
- Modelo: `claude-sonnet-4-6` (default). Configurable.
- **Timeout:** 30s por request.
- **Reintentos:** 3 con backoff 1s/2s/4s.
- **Variables de entorno:**
  ```
  ANTHROPIC_API_KEY=<secret>
  ANTHROPIC_MODEL=claude-sonnet-4-6
  ```

---

## 3. Permisos

| Permiso | Habilita |
|---|---|
| `copilot.usar_nivel_1_consultas` | Envíar mensajes, recibir respuestas de datos |
| `copilot.usar_nivel_2_acciones` | El copilot puede ejecutar tools que modifican estado |
| `copilot.ver_historial_propio` | Panel con conversaciones pasadas propias |
| `copilot.ver_historial_todos` | Admin puede auditar conversaciones de otros |

**Sin `copilot.usar_nivel_1_consultas`**: el FAB no se muestra.

---

## 4. Definición de tools

Las tools disponibles en cada mensaje se filtran por los permisos del usuario. Catálogo base (MVP):

### Nivel 1 (consultas)
- `listar_mis_habitaciones` → requiere `habitaciones.ver_asignadas_propias`
- `listar_habitaciones_hotel` → requiere `habitaciones.ver_todas`
- `ver_kpis_personales` → requiere `kpis.ver_propios`
- `ver_kpis_equipo` → requiere `kpis.ver_operativas`
- `listar_alertas_activas` → requiere `alertas.recibir_predictivas`
- `listar_tickets` → requiere `tickets.ver_todos` o `tickets.ver_propios`
- `ver_salud_sistema` → requiere `sistema.ver_salud`

### Nivel 2 (acciones — requieren `copilot.usar_nivel_2_acciones`)
- `asignar_habitacion` → requiere `asignaciones.asignar_manual`
- `reasignar_habitacion` → requiere `asignaciones.asignar_manual`
- `auditar_habitacion` → requiere `auditoria.aprobar` / `.aprobar_con_observacion` / `.rechazar`
- `crear_ticket` → requiere `tickets.crear`
- `marcar_disponible` → requiere `disponibilidad.notificar_supervisora`
- `completar_habitacion` → requiere `habitaciones.marcar_completada` + asignada a mí

### Filtro dinámico

En cada request al copilot, el backend construye el array `tools` incluyendo solo las que el usuario puede usar según sus permisos efectivos. El LLM ve únicamente las tools autorizadas.

---

## 5. Prompts del sistema por rol

Prompt base (común):
```
Eres un asistente para el equipo de limpieza hotelera de Atankalama (2 hoteles en Calama, Chile). Respondes en español chileno, de forma amable y breve. Tienes acceso a herramientas para consultar y (si corresponde) modificar el sistema. Usa las herramientas disponibles siempre que la pregunta requiera datos reales. Nunca inventes datos.
```

Prompt adicional por rol (concatenado):
- **Trabajador:** "El usuario es un trabajador de limpieza. Ayúdalo a consultar sus habitaciones, reportar tickets, marcarse disponible."
- **Supervisora:** "El usuario es supervisora. Puede reasignar, ver carga de equipo, atender alertas."
- **Recepción:** "El usuario es recepcionista. Se enfoca en auditoría de habitaciones."
- **Admin:** "El usuario es administrador. Tiene acceso total — KPIs, salud del sistema, gestión de usuarios."

---

## 6. UI

### 6.1 FAB

- Icono: Lucide `sparkles`.
- Posición: `fixed bottom-20 right-4` (móvil, sobre tab bar) / `fixed bottom-6 right-6` (desktop).
- Visible en todas las Homes si el usuario tiene `copilot.usar_nivel_1_consultas`.

### 6.2 Panel

- **Móvil:** slide-up modal (ocupa 80% viewport height). Handle superior arrastrable para cerrar.
- **Desktop:** panel lateral derecho `w-96`, altura completa, con backdrop semitransparente.

### 6.3 Componentes del panel

- Header: título "Asistente", botón cerrar, botón nueva conversación.
- Lista de mensajes (scroll).
- Input: textarea + botón micrófono (voz) + botón enviar.
- Indicador de typing cuando el copilot responde.
- Botones de "Ver historial" (requiere `copilot.ver_historial_propio`).

---

## 7. Voz

Web Speech API nativa del navegador:

- **STT (Speech-to-Text):** `SpeechRecognition` con `lang='es-CL'`. Presionar y mantener el botón mic para grabar.
- **TTS (Text-to-Speech):** `SpeechSynthesisUtterance` con voz en español. Activable desde preferencias del usuario (default: off).

Fallback: si el navegador no soporta Web Speech API, el botón mic se oculta.

---

## 8. Persistencia

### 8.1 `copilot_conversaciones`

Una conversación por sesión lógica (usuario abre → hace preguntas → cierra). Nueva conversación se crea cuando:
- Usuario presiona "Nueva conversación".
- Han pasado > 1 hora desde el último mensaje.

`titulo` se genera automáticamente del primer mensaje del usuario (truncado a 60 chars).

### 8.2 `copilot_mensajes`

Una fila por cada mensaje (user, assistant, tool_use, tool_result). Permite reconstruir la conversación completa para el historial.

Campos de interés:
- `tokens_input`, `tokens_output` — tracking de costo API.
- `tool_name`, `tool_payload_json` — traza de acciones ejecutadas.

---

## 9. Auditoría de acciones del copilot

**Regla:** toda tool de nivel 2 que el copilot ejecute queda en `audit_log` con:
- `usuario_id` — quien conversó.
- `accion` — código de la tool (ej: `copilot.reasignar_habitacion`).
- `origen = 'copilot'`.
- `detalles_json` — payload de la tool + resultado.

Esto permite al Admin, en "Auditoría de acciones IA", revisar qué hizo el copilot por cada usuario.

---

## 10. Validación de permisos antes de cada tool call

Flujo (backend):
1. El LLM responde con `tool_use` para una tool X.
2. Antes de ejecutarla, el backend re-valida: ¿el usuario actual tiene los permisos de X?
3. Si NO → responde al LLM con `tool_result` de error: `{ "error": "No tienes permiso para esta acción" }`. El LLM explica al usuario.
4. Si SÍ → ejecuta, retorna resultado.

**Nunca** confiar solo en el filtro de tools del paso 4 — siempre re-validar en cada ejecución (defensa en profundidad).

---

## 11. Endpoints

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/copilot/mensaje` | `copilot.usar_nivel_1_consultas` | Envía mensaje, recibe respuesta |
| GET | `/api/copilot/conversaciones` | `copilot.ver_historial_propio` | Lista propias |
| GET | `/api/copilot/conversaciones/{id}` | `copilot.ver_historial_propio` (propia) | Detalle con mensajes |
| GET | `/api/copilot/conversaciones/todas` | `copilot.ver_historial_todos` | Todas (admin) |
| DELETE | `/api/copilot/conversaciones/{id}` | propia | Borrar historial |

---

## 12. Referencias cruzadas

- [roles-permisos.md](roles-permisos.md) §2.9
- [logs.md](logs.md) — audit_log con `origen=copilot`
- [database-schema.sql](database-schema.sql) — `copilot_conversaciones`, `copilot_mensajes`
- [CLAUDE.md](../CLAUDE.md) §"Seguridad" — nunca loggear API key
- [home-trabajador.md](home-trabajador.md), [home-supervisora.md](home-supervisora.md), [home-recepcion.md](home-recepcion.md), [home-admin.md](home-admin.md) — FAB
