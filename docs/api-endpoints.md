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
| POST | `/api/auth/recuperar` | (ninguno) | Recuperación de clave: envía temporal al email del RUT. Respuesta siempre genérica (anti-enumeración); throttle 3/ventana por rut+ip |
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
| GET | `/api/roles` | `roles.ver` **o** `permisos.asignar_a_rol` **o** `usuarios.asignar_rol` **o** `usuarios.crear` | Lista con sus permisos (los dos últimos la necesitan para elegir el rol de un usuario) |
| POST | `/api/roles` | `roles.crear` | Crear rol |
| PUT | `/api/roles/{id}` | `roles.editar` | Renombrar / descripción |
| DELETE | `/api/roles/{id}` | `roles.eliminar` | Eliminar (si sin usuarios) |
| PUT | `/api/roles/{id}/permisos/{codigo}` | `permisos.asignar_a_rol` | Asignar/desasignar |
| GET | `/api/permisos` | `roles.ver` **o** `permisos.asignar_a_rol` | Catálogo completo |

---

## 5. Habitaciones

Ver [habitaciones.md](habitaciones.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/habitaciones` | `habitaciones.ver_todas` | Lista (filtros hotel/estado/fecha). Desde v7 cada fila trae `revision_vigente` (última inspección pre-entrega de la pieza mientras no haya cambiado de estado, o `null`) para el botón de la tarjeta — ver §19 |
| PUT | `/api/habitaciones/{id}/estructura` | `habitaciones.gestionar_edificios` | Edificio y piso de la pieza (Edificios y Mapeo). Antes de la v7 alcanzaba con `habitaciones.ver_todas` |
| GET | `/api/habitaciones/asignadas` | `habitaciones.ver_asignadas_propias` | Mis asignaciones hoy |
| GET | `/api/habitaciones/{id}` | `habitaciones.ver_todas` o asignada | Detalle. Desde v7 incluye `revision_entrega` (última inspección pre-entrega de Recepción con `vigente`, `relimpiable`, `relimpieza_trabajador`, `relimpieza_iniciada`, o `null`) solo si el usuario tiene `habitaciones.ver_todas` — ver §19 — y `asignado_a_nombre` (a quién está asignada hoy) |
| GET | `/api/habitaciones/{id}/historial` | `habitaciones.ver_historial` | Historial completo |
| POST | `/api/habitaciones/{id}/iniciar` | asignada | Crear ejecución → `en_progreso`. 409 `YA_TIENE_HABITACION_EN_PROGRESO` si ya hay otra en curso. 409 `NO_ES_TU_HABITACION_ACTUAL` si el trabajador (sin `habitaciones.ver_todas`) intenta iniciar una que no es su habitación actual (orden de cola) |
| POST | `/api/habitaciones/{id}/completar` | `habitaciones.marcar_completada` + asignada | → `completada_pendiente_auditoria` |
| POST | `/api/habitaciones/{id}/saltar` | asignada | "No puedo terminar ahora": descarta la ejecución, `→ sucia`, al final de la cola + alerta `habitacion_saltada`. Body: `{ motivo }` |

---

## 6. Asignaciones

Ver [habitaciones.md](habitaciones.md) §6.

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/asignaciones` | `asignaciones.asignar_manual` | Asignar manual (lote). Si alguna pieza está en progreso y asignada hoy, exige además `asignaciones.mover_en_progreso` (403 `HABITACION_EN_PROGRESO`) y no asigna ninguna del lote |
| POST | `/api/asignaciones/auto` | `asignaciones.auto_asignar` | Round-robin |
| POST | `/api/asignaciones/reasignar` | `asignaciones.asignar_manual` | Reasignar. Una pieza en progreso (hoy) exige además `asignaciones.mover_en_progreso` → si no, 403 `HABITACION_EN_PROGRESO` |
| POST | `/api/asignaciones/desasignar` | `asignaciones.asignar_manual` | Desasignar (activa=0, sin nuevo dueño); solo sucia/en_progreso/rechazada, en_progreso vuelve a sucia. Quitar una en progreso (hoy) exige además `asignaciones.mover_en_progreso` → si no, 403 `HABITACION_EN_PROGRESO` |
| PUT | `/api/asignaciones/orden` | `asignaciones.reordenar_cola_trabajador` | Reordenar cola |
| GET | `/api/usuarios/{id}/cola` | propia, o `asignaciones.asignar_manual` | Cola del trabajador. Sin `habitaciones.ver_todas` devuelve solo la **habitación actual** (la que tiene en curso; si no, la primera pendiente); con `?vista=completa` sobre la propia cola, todas las del día (pestaña Habitaciones del trabajador) |

---

## 7. Checklist

Ver [checklist.md](checklist.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/checklists/templates` | `checklists.ver` | Lista templates de tipo **vigentes** (con `version`, `items_count`, `creditos_total`, `heredado`). Query opcional `?hotel=<codigo>` (solo surte efecto con el toggle activo): devuelve, por tipo, el override del hotel o el compartido con `heredado=1`. La respuesta incluye `tipos_por_hotel` (bool) y `hoteles` (`[{codigo, nombre}]`) |
| GET | `/api/checklists/templates/{id}/items` | `checklists.ver` | Ítems de un template (sirve también para ver una versión vieja) |
| GET | `/api/checklists/templates/{id}/historial` | `checklists.ver` | Versiones del checklist, de la más nueva a la más vieja: `{ versiones: [{id, version, nombre, activo, created_at, creado_por, creado_por_nombre, items_count, creditos_total}] }`. Acepta el id de cualquier versión |
| GET | `/api/checklists/config` | `checklists.ver` | `{ tipos_por_hotel: bool, hoteles: [{codigo, nombre}] }` — estado del toggle "separar checklists por hotel" |
| PUT | `/api/checklists/config` | `checklists.editar` | Activa/desactiva el toggle. Body `{ tipos_por_hotel: bool }` → `{ tipos_por_hotel }` |
| POST | `/api/checklists/templates` | `checklists.crear_nuevos` | Crear template *(no implementado en MVP)* |
| PUT | `/api/checklists/templates/{id}` | `checklists.editar` | Editar ítems: descripción, orden, `obligatorio`, peso de `creditos`, `es_cambio_sabanas`. Body `{ nombre?, items: [{id?, descripcion, obligatorio, creditos, es_cambio_sabanas?}], hotel_codigo? }`. Con `hotel_codigo` (y el toggle activo) el guardado crea/actualiza el **override de ese hotel** sin tocar el compartido. **Copy-on-write:** no muta el template enviado — crea la versión siguiente y responde `{ template_id, version, items }` con el id **nuevo** (el cliente debe descartar el viejo) |
| GET | `/api/ejecuciones/{id}` | asignada o `habitaciones.ver_todas` | Estado ejecución: `{ ejecucion, items, progreso, delay_restante_segundos, rechazo }`. En la re-limpieza de un rechazo del mismo ciclo, `rechazo` = `{ comentario, auditor_nombre, items: [item_id] }` (si no, `null`) y cada ítem trae `rechazado_por_supervisora` 0/1 (v6.21, ver [checklist.md](checklist.md) §3.8) |
| PUT | `/api/ejecuciones/{id}/items/{item_id}` | asignada | Tap-a-tap |

---

## 8. Auditoría

Ver [auditoria.md](auditoria.md).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/auditoria/bandeja` | `auditoria.ver_bandeja` | Lista pendientes. Por pieza: `id`, `numero`, `estado`, `es_nochero`, `auditoria_orden`, `se_va_hoy`, `cb_frontdesk_status`, `cloudbeds_room_name`, `hotel_codigo`, `tipo_nombre`, `ejecucion_id`, `trabajador_id` |
| POST | `/api/auditoria/{habitacion_id}` | `auditoria.aprobar` / `.aprobar_con_observacion` / `.rechazar` | Veredicto |
| GET | `/api/auditoria/{id}/historial` | `habitaciones.ver_historial` | Detalle histórico |
| POST | `/api/auditoria/{habitacion_id}/iniciar` | `auditoria.ver_bandeja` | Marca el inicio de la inspección (`ejecuciones_checklist.auditoria_iniciada_at`) de la última ejecución completada; lo dispara el detalle al abrirse. Alimenta el KPI «tiempo por auditación» (v6.4). Devuelve `{ registrado: bool }` |

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

### Apariencia (Ajustes → Colores)

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/ui-config/colores` | `apariencia.editar` | Colores efectivos + defaults + etiquetas (para el editor) |
| PUT | `/api/ui-config/colores` | `apariencia.editar` | Guardar colores `{ colores: { clave: '#rrggbb' } }`. Claves: `color_estado_*` y `color_hotel_*` (ver `UiConfigService::DEFAULTS`) |

---

## 10. Tickets

Ver [tickets.md](tickets.md). Los tickets incluyen `responsables` (lista de `{id, nombre}`; sin email) además de `asignado_a` (responsable principal, por compatibilidad).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| POST | `/api/tickets` | `tickets.crear` | Crear |
| GET | `/api/tickets` | `tickets.ver_todos` | Todos |
| GET | `/api/tickets/mios` | `tickets.ver_propios` | Propios |
| GET | `/api/tickets/{id}` | propietario o `tickets.ver_todos` | Detalle |
| PUT | `/api/tickets/{id}/asignar` | `tickets.ver_todos`; con solo `tickets.ver_propios`, autoasignarse un ticket sin dueño («Tomar») | Asigna uno o varios responsables: `usuario_ids` (array), `usuario_id` o `grupo_rol`. Reemplaza la lista completa (tabla `tickets_asignados`); `asignado_a` conserva al responsable principal si sigue en la lista. Designar a alguien que no es Trabajador exige `tickets.asignar_a_cualquier_perfil` (403 `PERFIL_NO_ASIGNABLE`) |
| PUT | `/api/tickets/{id}/estado` | `tickets.ver_todos` (cualquier transición) o ser **cualquiera de los responsables** (solo `en_progreso` / `resuelto`) | Cambiar estado |

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

## 18. Reportes y KPIs

Ver [kpis-sueldos.md](kpis-sueldos.md) (ficha viva de KPIs para el bono de Aseo). Todos requieren `reportes.ver`. Filtros de período: `desde`, `hasta` (fecha local `YYYY-MM-DD`) y `hotel`.

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/reportes/kpis` | `reportes.ver` | KPIs del equipo y detalle por trabajador para el período |
| GET | `/api/reportes/ficha` | `reportes.ver` | **Ficha de KPIs (v6.4)**: `config` (umbrales σ, min datos, meta cobertura), `trabajadores` (dos etapas A/B, asignadas, cobertura/realización/cumplimiento/calidad, créditos por hab, ritmo), `comparativa` (Δ, z y semáforo vs el grupo) y `supervisoras` (sección vs meta + tendencia, con `por_turno` según el calendario de Turnos del trabajador; inspectoras con tiempo por auditación, aporte a cobertura y, desde v7, `recepcion_aprobadas` / `recepcion_rechazadas` de la inspección pre-entrega). Desde v7 las re-limpiezas por un NO de Recepción no cuentan en ningún KPI de aseo ni de inspección. **`supervisoras` viaja `null` si el usuario no tiene `reportes.ver_supervisoras`** (privacidad jerárquica de tiempos: nadie ve sus propios tiempos, solo el nivel de arriba) |
| GET | `/api/reportes/exportar` | `reportes.ver` | Excel con los KPIs del período (desde v6.16.1 incluye «Habitaciones limpiadas») |
| GET | `/api/reportes/resumen-mensual` | `reportes.ver` | Resumen del mes por trabajador = la ficha del mes: `habitaciones`, `rechazadas`, `creditos`, `creditos_asignados`, `eficiencia_pct` (desde v6.15; antes `creditos_maximos`), `limpiadas` (= `habitaciones` + `rechazadas`). Desde v6.15.1 también `rut`, `jornada`, `dias_trabajados`, `observaciones` (casillas del checklist desmarcadas por el auditor) y `bono` (columnas de la planilla «KPI ASEO» de RRHH: `base`, `act_dia`, `observadas_pct`, `eficacia_pct`, `logro_pct`, `factor_peso`, `resultado_pct`, `extras`), más `corte` del mes (`{valor, mes_origen, propio}`) |
| PUT | `/api/reportes/corte-hab-dia` | `reportes.editar_corte` | Fija el corte de habitaciones diarias (jornada completa) del bono de aseo para un mes: `{anio, mes, valor}` (1–100, un decimal). Los meses siguientes sin valor propio lo heredan. 400 `CORTE_INVALIDO` |
| GET | `/api/reportes/exportar-mensual` | `reportes.ver` | `.xlsx` del mes con dos pestañas: «Trabajadores» (resumen por trabajador del hotel elegido, con RUT, «Hab. limpiadas» y las columnas del bono de aseo de RRHH) y «Supervisores» (inspecciones por inspector, un bloque por hotel y uno con el total, siempre los dos hoteles). Hasta el 04/10/2026 era un CSV solo de trabajadores |
| GET | `/api/reportes/resumen-mensual-auditores` | `reportes.ver` | Resumen del mes de inspecciones por inspector: `total`, `aprobadas`, `aprobadas_observacion`, `rechazadas`, `observaciones` (casillas que desmarcó) y, desde v7, `recepcion_aprobadas` / `recepcion_rechazadas` (de las piezas que aprobó, lo que Recepción aprobó y no aprobó para entregar en el mes) |
| GET | `/api/reportes/auditorias-pendientes` | `reportes.ver` | Piezas limpiadas hoy sin veredicto, por turno |
| GET | `/api/reportes/exportar-auditorias-pendientes` | `reportes.ver` | Excel de las inspecciones pendientes de hoy |
| GET | `/api/reportes/revision-entrega` | `reportes.ver` | Sección «Inspección pre-entrega (Recepción)» (v7): `resumen {total, si, no, pct_no, a_sucia}`, `por_motivo` (solo NO), `historial` (máx. 500, `truncado`) y `filtros`. Lee solo `revisiones_entrega`: ningún KPI cambia. Ignora `usuario_id` |

---

## 19. Inspección pre-entrega (v7; en código «revision_entrega»)

Ver [revision-entrega.md](revision-entrega.md). Recepción revisa una pieza antes de entregarla al huésped. Nunca escribe `auditorias`. La re-limpieza que pide la supervisora por un NO no cuenta en los KPIs de aseo ni de inspección; el NO se ve por supervisora en la columna «Recepción» de Reportes (§18).

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/revision-entrega/formulario` | `revision_entrega.registrar` | Lo que necesita la ventana del NO: `motivos` activos y `no_ensucia` (interruptor). La inspección vigente de cada pieza viaja en `GET /api/habitaciones` (`revision_vigente`) |
| POST | `/api/revision-entrega` | `revision_entrega.registrar` | Multipart (o JSON sin foto): `habitacion_id`, `resultado` (`si`/`no`), `motivo_id` (obligatorio si `no`), `comentario` (≤300), `foto` (opcional, solo con `no`), `idempotency_key` (≤64). 201 `{revision, repetida: false, foto_fallida}`; misma clave → 200 `{repetida: true}` sin re-avisar. La foto nunca hace fallar el NO: si no se guardó, `foto_fallida` dice por qué. Con el interruptor prendido, un NO sobre una pieza aprobada la pasa a `sucia` y avisa `dirty` a Cloudbeds (`revision.paso_a_sucia`). Errores: 400 `PARAMETROS_INVALIDOS`/`RESULTADO_INVALIDO`/`MOTIVO_REQUERIDO`/`COMENTARIO_LARGO`, 404 `HABITACION_NO_ENCONTRADA`/`MOTIVO_NO_ENCONTRADO`, 413 `CUERPO_MUY_GRANDE` (superó `post_max_size`) |
| GET | `/api/revision-entrega/motivos?todos=1` | `revision_entrega.configurar` | Catálogo de motivos (`todos=1` incluye inactivos) |
| POST | `/api/revision-entrega/motivos` | `revision_entrega.configurar` | `{nombre}` (2–60). 201 `{id}`. 409 `MOTIVO_DUPLICADO` (sin distinguir mayúsculas ni «Ñ») |
| PUT | `/api/revision-entrega/motivos/{id}` | `revision_entrega.configurar` | `{nombre?, activo?}`. No hay borrado: un motivo inactivo deja de aparecer en el NO y el historial lo conserva |
| GET | `/api/revision-entrega/config` | `revision_entrega.configurar` | `{no_ensucia: bool}` (default `false`) |
| PUT | `/api/revision-entrega/config` | `revision_entrega.configurar` | `{no_ensucia: bool}`: si un NO devuelve la pieza aprobada a sucia (guardado en `alertas_config` `revision_entrega_no_ensucia`) |
| POST | `/api/revision-entrega/{id}/relimpiar` | `asignaciones.asignar_manual` | «Re-limpiar» de la supervisora: `{trabajador_id, prioridad}` (`prioridad: true` exige además `asignaciones.reordenar_cola_trabajador`, si no 403 `SIN_PERMISO`). Asigna hoy la pieza de un NO vigente sobre una pieza aprobada (como reasignar: pasa a sucia + `dirty` a Cloudbeds, aviso a la trabajadora con el motivo) y, con prioridad, la deja primera en su cola. 200 `{revision}` (con `relimpieza_trabajador`). Errores: 400 `PARAMETROS_INVALIDOS`, 404 `REVISION_NO_ENCONTRADA`/`TRABAJADOR_NO_ENCONTRADO`, 409 `RELIMPIEZA_NO_APLICA` (no es un NO sobre una pieza aprobada)/`REVISION_NO_VIGENTE` (la pieza cambió de estado). La limpieza que la rehace no cuenta en los KPIs |

No hay pantalla aparte: se inspecciona desde la tarjeta de cada pieza en `GET /habitaciones`. Página de configuración: `GET /ajustes/revision-entrega` (`revision_entrega.configurar`, si no → `/ajustes`). Las fotos se sirven por `GET /uploads/revision-entrega/AAAA/MM/{hex16}.webp`. Una clave de idempotencia vale como reintento solo si es la misma pieza, la misma respuesta y sigue siendo la última revisión de esa pieza; si no, la revisión se guarda como nueva.

Edificios (v7): `GET /api/edificios` sigue con `habitaciones.ver_todas` (filtros de Habitaciones); `POST`/`PUT`/`DELETE /api/edificios*` y la página `GET /edificios` exigen `habitaciones.gestionar_edificios` (sin permiso, la página redirige a `/ajustes`). `PUT /api/alertas/config` acepta solo las claves de Ajustes → Alertas (400 `CLAVE_INVALIDA`).

---

## 20. Rate limiting

**Fuera del MVP.** Post-MVP: rate limit por IP:
- 5 intentos de login / 15 min.
- 100 requests / min en endpoints autenticados.

---

## 21. Referencias cruzadas

Cada sección enlaza al doc de detalle correspondiente. Este índice es la fuente maestra — si un endpoint no aparece aquí, no existe en el backend.
