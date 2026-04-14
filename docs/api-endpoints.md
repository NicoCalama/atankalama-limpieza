# API Endpoints — referencia maestra

**Versión:** 1.0 — 2026-04-14

Índice completo de todos los endpoints REST del backend. Cada fila remite al doc de detalle.

---

## 1. Convenciones

### 1.1 Autenticación

Todas las rutas bajo `/api/*` (salvo `POST /api/auth/login`) requieren sesión válida (cookie `session` vía middleware `AuthCheck`).

### 1.2 Respuestas estandarizadas

Éxito:
```json
{ "ok": true, "data": { ... } }
```

Error:
```json
{ "ok": false, "error": { "codigo": "CODIGO_ERROR", "mensaje": "Descripción amigable" } }
```

### 1.3 HTTP Status Codes

- `200` — OK (GET, PUT, DELETE)
- `201` — Created (POST que crea recurso)
- `400` — Request inválido (validación)
- `401` — No autenticado
- `403` — Autenticado pero sin permiso
- `404` — Recurso no encontrado
- `409` — Conflicto (inmutabilidad auditoría, último admin, etc.)
- `500` — Error interno

### 1.4 Paginación

Endpoints que devuelven listas aceptan:
- `?pagina=1` (default 1)
- `?por_pagina=20` (default 20, max 100)

Respuesta incluye `data.total`, `data.pagina`, `data.por_pagina`.

### 1.5 Permiso "propio"

Cuando un permiso es scope `propio`, el endpoint filtra por el `usuario_id` de la sesión. Ejemplo: `GET /api/habitaciones/asignadas` trae solo las del usuario actual.

---

## 2. Auth

Ver [auth.md](auth.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/auth/login` | (ninguno) | Login RUT + pwd |
| POST | `/api/auth/logout` | sesión | Logout |
| POST | `/api/auth/cambiar-contrasena` | `usuarios.cambiar_propia_contrasena` | Cambio propio |
| POST | `/api/auth/reset-temporal` | `usuarios.resetear_password` | Admin resetea a otro |
| GET | `/api/auth/me` | sesión | Usuario actual + permisos |

---

## 3. Usuarios

Ver [usuarios.md](usuarios.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/usuarios` | `usuarios.ver` | Lista paginada |
| GET | `/api/usuarios/{id}` | `usuarios.ver` o propio | Detalle |
| POST | `/api/usuarios` | `usuarios.crear` | Crear |
| PUT | `/api/usuarios/{id}` | `usuarios.editar` | Editar datos base |
| PUT | `/api/usuarios/{id}/activo` | `usuarios.activar_desactivar` | Toggle activo |
| PUT | `/api/usuarios/{id}/roles` | `usuarios.asignar_rol` | Asignar roles |
| PUT | `/api/usuarios/me` | sesión | Editar mis datos |

---

## 4. Roles y permisos

Ver [roles-permisos.md](roles-permisos.md), [ajustes.md](ajustes.md) §3.

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/roles` | `roles.ver` | Lista con sus permisos |
| POST | `/api/roles` | `roles.crear` | Crear rol |
| PUT | `/api/roles/{id}` | `roles.editar` | Renombrar / descripción |
| DELETE | `/api/roles/{id}` | `roles.eliminar` | Eliminar (si sin usuarios) |
| PUT | `/api/roles/{id}/permisos/{codigo}` | `permisos.asignar_a_rol` | Asignar/desasignar |
| GET | `/api/permisos` | `roles.ver` | Catálogo completo |

---

## 5. Habitaciones

Ver [habitaciones.md](habitaciones.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/habitaciones` | `habitaciones.ver_todas` | Lista (filtros hotel/estado/fecha) |
| GET | `/api/habitaciones/asignadas` | `habitaciones.ver_asignadas_propias` | Mis asignaciones hoy |
| GET | `/api/habitaciones/{id}` | `habitaciones.ver_todas` o asignada | Detalle |
| GET | `/api/habitaciones/{id}/historial` | `habitaciones.ver_historial` | Historial completo |
| POST | `/api/habitaciones/{id}/iniciar` | asignada | Crear ejecución → `en_progreso` |
| POST | `/api/habitaciones/{id}/completar` | `habitaciones.marcar_completada` + asignada | → `completada_pendiente_auditoria` |

---

## 6. Asignaciones

Ver [habitaciones.md](habitaciones.md) §6.

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/asignaciones` | `asignaciones.asignar_manual` | Asignar manual |
| POST | `/api/asignaciones/auto` | `asignaciones.auto_asignar` | Round-robin |
| POST | `/api/asignaciones/reasignar` | `asignaciones.asignar_manual` | Reasignar |
| PUT | `/api/asignaciones/orden` | `asignaciones.reordenar_cola_trabajador` | Reordenar cola |

---

## 7. Checklist

Ver [checklist.md](checklist.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/checklists/templates` | `checklists.ver` | Lista templates |
| POST | `/api/checklists/templates` | `checklists.crear_nuevos` | Crear template |
| PUT | `/api/checklists/templates/{id}` | `checklists.editar` | Editar |
| GET | `/api/ejecuciones/{id}` | asignada o `habitaciones.ver_todas` | Estado ejecución |
| PUT | `/api/ejecuciones/{id}/items/{item_id}` | asignada | Tap-a-tap |

---

## 8. Auditoría

Ver [auditoria.md](auditoria.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/auditoria/bandeja` | `auditoria.ver_bandeja` | Lista pendientes |
| POST | `/api/auditoria/{habitacion_id}` | `auditoria.aprobar` / `.aprobar_con_observacion` / `.rechazar` | Veredicto |
| GET | `/api/auditoria/{id}/historial` | `habitaciones.ver_historial` | Detalle histórico |

---

## 9. Alertas

Ver [alertas-predictivas.md](alertas-predictivas.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/alertas/activas` | `alertas.recibir_predictivas` | Top + total |
| GET | `/api/alertas` | `alertas.recibir_predictivas` | Paginado |
| POST | `/api/alertas/{id}/accion` | según acción | Ejecuta botón |
| GET | `/api/alertas/bitacora` | `alertas.recibir_predictivas` | Histórico |
| PUT | `/api/alertas/config` | `alertas.configurar_umbrales` | Editar umbrales |

---

## 10. Tickets

Ver [tickets.md](tickets.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/tickets` | `tickets.crear` | Crear |
| GET | `/api/tickets` | `tickets.ver_todos` | Todos |
| GET | `/api/tickets/mios` | `tickets.ver_propios` | Propios |
| GET | `/api/tickets/{id}` | propietario o `tickets.ver_todos` | Detalle |
| PUT | `/api/tickets/{id}/asignar` | `tickets.ver_todos` | Asignar a usuario |
| PUT | `/api/tickets/{id}/estado` | `tickets.ver_todos` | Cambiar estado |

---

## 11. Turnos

Ver [turnos.md](turnos.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/turnos` | `turnos.ver` | Catálogo |
| POST | `/api/turnos` | `turnos.crear_editar` | Crear |
| PUT | `/api/turnos/{id}` | `turnos.crear_editar` | Editar |
| GET | `/api/usuarios-turnos` | `turnos.ver` | Asignaciones por rango |
| POST | `/api/usuarios-turnos` | `turnos.asignar_a_usuario` | Asignar turno |
| DELETE | `/api/usuarios-turnos/{id}` | `turnos.asignar_a_usuario` | Quitar |
| POST | `/api/usuarios-turnos/copiar-semana` | `turnos.asignar_a_usuario` | Copiar semana |

---

## 12. Cloudbeds

Ver [cloudbeds.md](cloudbeds.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/cloudbeds/estado` | `cloudbeds.ver_estado_sincronizacion` | Health |
| POST | `/api/cloudbeds/sync` | `cloudbeds.forzar_sincronizacion` | Sync manual |
| GET | `/api/cloudbeds/historial` | `cloudbeds.ver_estado_sincronizacion` | Histórico |
| PUT | `/api/cloudbeds/config` | `cloudbeds.configurar_credenciales` | Editar `cloudbeds_config` |

---

## 13. Copilot

Ver [copilot-ia.md](copilot-ia.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/copilot/mensaje` | `copilot.usar_nivel_1_consultas` | Enviar mensaje |
| GET | `/api/copilot/conversaciones` | `copilot.ver_historial_propio` | Propias |
| GET | `/api/copilot/conversaciones/{id}` | propia | Detalle |
| GET | `/api/copilot/conversaciones/todas` | `copilot.ver_historial_todos` | Todas (admin) |
| DELETE | `/api/copilot/conversaciones/{id}` | propia | Borrar |

---

## 14. Homes (agregados)

Endpoints optimizados para cada Home (agrupan datos para evitar N queries).

| Método | Endpoint | Permiso | Descripción | Doc |
|---|---|---|---|---|
| GET | `/api/home/trabajador` | `habitaciones.ver_asignadas_propias` | Datos Home Trabajador | [home-trabajador.md](home-trabajador.md) |
| GET | `/api/home/supervisora` | `habitaciones.ver_todas` OR `alertas.recibir_predictivas` OR `auditoria.ver_bandeja` | Datos Home Supervisora | [home-supervisora.md](home-supervisora.md) |
| GET | `/api/home/recepcion` | `auditoria.ver_bandeja` | Datos Home Recepción | [home-recepcion.md](home-recepcion.md) |
| GET | `/api/home/admin` | `alertas.recibir_predictivas` OR `kpis.ver_operativas` OR `sistema.ver_salud` OR `ajustes.acceder` | Datos Home Admin | [home-admin.md](home-admin.md) |

---

## 15. Sistema

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/sistema/salud` | `sistema.ver_salud` | Health check (BD, Cloudbeds, usuarios activos, versión) |

---

## 16. Logs

Ver [logs.md](logs.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/logs/eventos` | `logs.ver` | `logs_eventos` con filtros |
| GET | `/api/logs/audit` | `logs.ver` | `audit_log` con filtros |

---

## 17. Disponibilidad / Notificaciones

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/disponibilidad/notificar` | `disponibilidad.notificar_supervisora` | Me marco disponible → alerta P2 |
| GET | `/api/notificaciones` | `notificaciones.ver` | Centro de notificaciones |

---

## 18. Rate limiting

**Fuera del MVP.** Post-MVP: rate limit por IP:
- 5 intentos de login / 15 min.
- 100 requests / min en endpoints autenticados.

---

## 19. Referencias cruzadas

Cada sección enlaza al doc de detalle correspondiente. Este índice es la fuente maestra — si un endpoint no aparece aquí, no existe en el backend.
