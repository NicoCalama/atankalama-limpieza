# ARCHITECTURE_MAP.md — Mapa de navegación rápida

> Referencia de secciones y líneas para los archivos grandes del proyecto.
> Usa esto para saltar directamente a la sección que necesitas sin leer archivos completos.
> Actualizado: 14 abril 2026.

---

## plan.md (737 líneas, v3.1)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-15 | Header | Versión, fecha, metadatos |
| 16-34 | §1 Resumen ejecutivo | Qué es la app, para quién, qué reemplaza |
| 35-48 | §2 Objetivos del MVP | 8 objetivos concretos |
| 49-78 | §3 Stack tecnológico | Backend, frontend, IA, herramientas |
| 79-104 | §4 Filosofía de diseño | Mobile-first, línea visual, UX sin ansiedad, idioma |
| 105-225 | §5 Roles y permisos (RBAC) | Decisión arquitectónica, catálogo de permisos, roles default |
| 226-255 | §6 Gestión de turnos | Mañana/tarde, horarios, lógica |
| 256-297 | §7 Flujo general del sistema | Diagrama del flujo diario completo |
| 258-297 | §8 Módulos de la app | Todos los módulos (auth, home, checklist, auditoría, etc.) |
| 258-266 | §8.1 Autenticación | Login con RUT, contraseña temporal, cambio forzado |
| 267-297 | §8.2 Home/Dashboard | 4 versiones por rol. Supervisora y trabajador diseñadas |
| 298-315 | §8.3 Checklist | Persistencia tap-a-tap, tracking tiempo oculto |
| 316-344 | §8.4 Auditoría (3 estados) | Aprobado, con observación, rechazado + inmutabilidad |
| 345-395 | §8.5-8.10 Otros módulos | Habitaciones, asignación, tickets, usuarios, ajustes |
| 395-415 | §8.11 Copilot IA | Claude API, tool use, voz, prompts por rol |
| 416-476 | §9 Alertas predictivas | Algoritmo, 6 tipos con prioridades P0-P3, guiños Fase 2 |
| 477-502 | §10 Pantallas principales | Esqueleto visual de cada pantalla |
| 503-572 | §11-12 Cloudbeds + BD | Credenciales, sync, manejo errores, schema SQLite, bitacora_alertas |
| 573-588 | §13 Seguridad | Lineamientos generales MVP |
| 589-645 | §14-15 Fases + próximos pasos | Fase 0/1/2, checklist de próximos pasos |
| 646-737 | §16-17 Preparación Claude Code | Autonomía híbrida, MCPs, skills, consideraciones abiertas |

---

## claude-code-setup.md (1176 líneas, v2.1)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-12 | Header | Versión, fecha, metadatos del entorno |
| 13-28 | Tabla de contenidos | Índice de secciones |
| 29-57 | §1 Estado actual del setup | Qué está hecho vs pendiente |
| 58-73 | §2 Pre-requisitos Windows | Git, Node, PHP, VS Code, versiones |
| 74-189 | §3 Estructura de carpetas | Árbol completo del proyecto con status ✅/🚧 |
| 190-307 | §4 Archivos base de seguridad | .gitignore completo, .env.example completo, .gitkeep |
| 308-339 | §5 gitleaks | Instalación, hook pre-commit, prueba |
| 340-614 | §6 CLAUDE.md raíz (embebido) | Copia completa de CLAUDE.md dentro del setup |
| 340-365 | §6 → Sobre el proyecto + docs | Descripción y documentación obligatoria |
| 366-384 | §6 → Modo de trabajo + stack | Autonomía híbrida, stack obligatorio |
| 385-420 | §6 → Frontend + RBAC | Decisiones frontend, RBAC dinámico |
| 421-457 | §6 → Auditoría + checklist + tiempo | 3 estados, persistencia, tracking oculto |
| 458-482 | §6 → Alertas predictivas | Tipos P0-P3, bitacora_alertas |
| 483-524 | §6 → Convenciones PHP + seguridad | Código, JSON, reglas NO NEGOCIABLES |
| 525-614 | §6 → Defaults, comandos, commits, testing | Razonables, útiles, convención, tests |
| 615-651 | §7 MCPs instalados | 5 MCPs: filesystem, github, sqlite, context7, playwright |
| 652-974 | §8 Skills personalizadas | Contenido completo de las 3 skills |
| 656-704 | §8.1 cloudbeds-api | Endpoints, auth, manejo errores, cliente PHP |
| 705-837 | §8.2 php-conventions | Controller, Service, JSON responses, middleware |
| 838-974 | §8.3 ui-components | Filosofía, imports, paleta, componentes base |
| 975-1006 | §9 Flujo de trabajo | Sesión típica, qué hacer cuando algo sale mal |
| 1007-1100 | §10 Orden de codificación | Etapas A-I con ítems numerados 1-61 |
| 1011-1017 | §10 → Etapa A Fundación | Ítems 1-4: router, DB, seeders, servicios base |
| 1018-1026 | §10 → Etapa B RBAC/Auth | Ítems 5-10: usuario, RBAC, middleware, auth, tests |
| 1027-1034 | §10 → Etapa C Habitaciones | Ítems 11-15: Cloudbeds, modelos, endpoints, sync |
| 1035-1045 | §10 → Etapa D Checklists/Auditoría | Ítems 16-23: checklist, asignación, auditoría 3 estados |
| 1046-1058 | §10 → Etapa E Alertas | Ítems 24-33: predictivas, AlertasService, bitacora, 409 |
| 1059-1065 | §10 → Etapa F Tickets/Usuarios | Ítems 34-37: tickets, usuarios, turnos, reset pwd |
| 1066-1072 | §10 → Etapa G Copilot IA | Ítems 38-41: CopilotService, tools, endpoint, persistencia |
| 1073-1092 | §10 → Etapa H Frontend (SUPERVISIÓN) | Ítems 42-58: layout, login, 4 homes, checklist, auditoría, RBAC UI |
| 1093-1100 | §10 → Etapa I Pulido | Ítems 59-61: demo data, README, deploy |
| 1101-1176 | §11 Prompts de ejemplo | Templates para autonomía, supervisión, bugs, setup |

---

## CLAUDE.md (264 líneas)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-8 | Header + sobre el proyecto | Descripción general |
| 9-18 | Documentación obligatoria | 5 fuentes a consultar antes de codificar |
| 19-27 | Modo de trabajo | Autonomía total vs supervisión por módulo |
| 28-50 | Stack + frontend | Stack obligatorio, decisiones frontend clave |
| 51-74 | RBAC Dinámico | Regla de NUNCA chequear roles, SIEMPRE permisos |
| 75-94 | Auditoría 3 estados | Aprobado, con observación, rechazado |
| 85-94 | Inmutabilidad post-auditoría | NO NEGOCIABLE: no re-auditar, 409 Conflict |
| 95-111 | Checklist + tiempo oculto | Persistencia tap-a-tap, tracking invisible |
| 112-136 | Alertas predictivas | Tipos P0-P3, bitacora_alertas, reglas de visibilidad |
| 137-164 | Convenciones PHP + JSON | Namespace, PSR-4/12, tipado, respuestas estándar |
| 165-177 | Seguridad (NO NEGOCIABLES) | 10 reglas de seguridad |
| 178-195 | Defaults razonables | Mensajes, spinners, estados vacíos, timeouts, fechas |
| 196-217 | Comandos útiles | php -S, init-db, seed, sync, tests |
| 218-251 | Commits + testing | Convención de commits, tests unitarios requeridos |
| 252-264 | Post-módulo + troubleshooting | Pasos al terminar, qué hacer si algo falla |

---

## docs/home-supervisora.md (818 líneas, v2.1)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-13 | Header | Versión, fecha, status, resumen |
| 14-42 | §1 Contexto y propósito | Quién usa, qué responde, filosofía UX, dispositivo |
| 43-60 | §2 Permisos requeridos | Tabla de permisos necesarios para cada feature |
| 61-91 | §3 Layout general | Estructura de la pantalla, scroll, secciones |
| 92-152 | §4 Header | Layout, elementos (saludo, hotel, modo oscuro, avatar), estilo |
| 153-250 | §5 Alertas urgentes | Propósito, jerarquía P0-P3, tarjetas, flujos de resolución |
| 204-211 | §5.5 → Trabajador en riesgo | Flujo P1 |
| 212-219 | §5.5 → Habitación rechazada | Flujo P1 |
| 220-228 | §5.5 → Trabajador disponible | Flujo P2 |
| 229-238 | §5.5 → Sync Cloudbeds falló | Flujo P0 |
| 239-250 | §5.5 → Ticket mantenimiento | Flujo P2 |
| 251-343 | §6 Estado del equipo | Barra progreso, lista trabajadores, tarjetas, filtrado hotel |
| 344-431 | §7-8 FAB Copilot + Tab Bar | Elemento flotante, panel, tabs (Home/Auditoría/Copilot/Más) |
| 432-520 | §9 Módulo de Auditoría | Acceso, selector hotel, lista habitaciones, auditoría detallada |
| 479-520 | §9.4-9.6 Acciones auditoría | 3 botones, modal rechazo, inmutabilidad, permisos |
| 521-595 | §10-11 Comportamientos + estados | Refresco auto, pull-to-refresh, carga, error, offline |
| 596-651 | §12-13 Críticos + vinculación | Reglas NO NEGOCIABLES, conexión con otros módulos |
| 652-718 | §14 Notas para Claude Code | Modo codificación, archivos sugeridos, tests |

---

## docs/home-trabajador.md (801 líneas, v1.0)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-13 | Header | Versión, fecha, status, resumen |
| 14-42 | §1 Contexto y propósito | Quién usa, qué responde, UX sin ansiedad, dispositivo |
| 43-53 | §2 Permisos requeridos | Tabla de permisos |
| 54-87 | §3 Layout general | Estructura, scroll, secciones móvil/desktop |
| 88-146 | §4 Header | Layout, elementos (saludo, hotel, modo oscuro), estilo |
| 147-233 | §5 Tarjeta de progreso | Barra segmentada, elementos, estados especiales, estilo |
| 234-366 | §6 Habitación actual | Concepto, layout, elementos, estado vacío, badges |
| 367-438 | §7 Lista resto habitaciones | Layout, elementos, estados, orden |
| 439-515 | §8 Tab bar + FAB copilot | Bottom nav, FAB, padding inferior |
| 516-551 | §9 Responsive | Móvil, tablet/desktop, breakpoints |
| 552-581 | §10-11 Modo día/noche + accesibilidad | Toggle, colores, contraste, touch targets |
| 582-645 | §12 Datos del backend | Endpoints, payload JSON esperado, campos |
| 646-689 | §13 Estados de carga y error | Carga inicial, error, sin internet |
| 690-727 | §14 Refresco de datos | Auto (30s), pull-to-refresh, al volver del checklist |
| 728-768 | §15-16 Críticos + vinculación | Reglas, conexión con otros módulos |
| 769-801 | §17 Notas para Claude Code | Modo codificación, archivos, tests |

---

## docs/home-recepcion.md (696 líneas, v1.0)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-13 | Header | Versión, fecha, status, resumen |
| 14-40 | §1 Contexto y propósito | Foco en auditoría; Cloudbeds es la herramienta principal |
| 42-59 | §2 Permisos requeridos | auditoria.*, habitaciones.ver_todas (contexto) |
| 61-85 | §3 Layout general | Grid vertical de pendientes, sin buscador |
| 87-143 | §4 Header | Avatar, saludo, selector hotel |
| 145-213 | §5 Grid de habitaciones pendientes | Tarjetas tappables, prefijos por hotel |
| 215-259 | §6 Estados especiales | Sin pendientes / error / offline |
| 261-288 | §7-8 Tab Bar + FAB | Sparkles icon, posición |
| 290-331 | §9 Refresco de datos | 5 min auto, pull-to-refresh, post-auditoría |
| 333-368 | §10 Flujo de auditoría | 3 botones + inmutabilidad post-auditoría visual |
| 370-387 | §11 Selector de hotel | ATAN / INN / Ambos; persistencia |
| 389-469 | §12 Datos del backend | Endpoint GET /api/home/recepcion + shape JSON |
| 471-494 | §13 Estados de carga/error | Skeleton, error, offline |
| 496-522 | §14-15 Modo día/noche + accesibilidad | Dark mode, targets 44px |
| 524-551 | §16 Comportamientos críticos | Checklist del módulo |
| 553-574 | §17 Vinculación con otros módulos | Dependencias y conexiones |
| 576-647 | §18 Notas para Claude Code | Archivos sugeridos, tests, decisiones |
| 649-696 | §19-20 Anotaciones + resumen | Pendientes y decisiones principales |

---

## docs/home-admin.md (1009 líneas, v1.0)

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-12 | Header | Versión, fecha, status |
| 14-22 | §0 Decisiones marco | P0.1 dashboard ejecutivo, P0.2 propia, P0.3 "Ambos hoteles" default |
| 24-54 | §1 Contexto y propósito | Admin IT, control total, mobile-first |
| 56-73 | §2 Permisos requeridos | alertas.recibir_predictivas, kpis.ver_operativas, sistema.ver_salud |
| 75-107 | §3 Layout general | Móvil una columna, desktop 2 columnas |
| 109-178 | §4 Header | Avatar con iniciales (NC), selector hotel, indicador 🟢/🟡/🔴, campana |
| 180-249 | §5 Tab "Inicio" — Alertas técnicas | Jerarquía P0-P1, tabla compacta de flujos de resolución |
| 251-388 | §6 Tab "Operativas" — Métricas | 4 contadores + 3 KPIs (tiempo, rechazo, eficiencia) |
| 390-488 | §7 Tab "Técnicas" — Salud sistema | Cloudbeds, errores, BD, usuarios, versión |
| 490-513 | §8 Bottom Tab Bar | 4 tabs; Ajustes navega a módulo separado |
| 515-533 | §9 FAB Copilot | Lucide sparkles estandarizado, bottom-20/md:bottom-6 |
| 535-549 | §10 Refresco de datos | 30 min auto, pull-to-refresh móvil |
| 551-588 | §11 Responsive | Móvil vs desktop, 2 columnas equilibradas |
| 590-611 | §12-13 Día/noche + Accesibilidad | Dark mode, WCAG AA, targets 44px |
| 613-803 | §14 Datos del backend | Endpoint GET /api/home/admin + schema JSON completo |
| 805-824 | §15 Checklist comportamientos críticos | 10 críticos + link a QA checklist completa |
| 826-895 | §16 Vinculaciones con otros módulos | Dependencias, accede, comparte, monitorea |
| 897-981 | §17 Notas para Claude Code | Archivos, tests, decisiones autónomas |
| 983-1009 | §18 Formato y estructura de entrega | Estado, próximos pasos, Fase 2 |

---

## docs/home-admin-qa-checklist.md (complementario)

Checklist exhaustiva (~140 líneas, 75+ items) organizada por áreas: cálculos, filtrado por hotel, estados de KPI, refresco, alertas técnicas, indicador de estado, permisos dinámicos, responsive, header, FAB, UI/UX, tab bar, seguridad, performance.

---

## docs/roles-permisos.md (392 líneas, v1.0)

Catálogo canónico RBAC — fuente de verdad para permisos.

| Líneas | Sección | Contenido clave |
|--------|---------|-----------------|
| 1-8 | Header | Versión, estado, propósito |
| 10-37 | §1 Filosofía RBAC dinámico | Regla de oro, principios, no hay home.ver_* |
| 39-180 | §2 Catálogo completo (50 permisos) | Tablas por categoría (17 categorías) |
| 182-245 | §3 Roles por defecto (4) | Trabajador, Supervisora, Recepción, Admin con asignación |
| 247-301 | §4 Matriz visual permisos × roles | Tabla con ✅ / ⚪ por cada permiso × rol |
| 303-322 | §5 Reglas de herencia | Múltiples roles (unión), no hay permisos negativos, safeguards |
| 324-343 | §6 Cómo agregar un permiso nuevo | Flujo seeder → rol_permisos → middleware → UI |
| 345-370 | §7 Vinculación con schema | Tablas permisos/roles/rol_permisos/usuarios_roles + query |
| 372-392 | §8 Referencias cruzadas | Links a Homes, auth, schema |

---

## docs/database-schema.sql (511 líneas)

DDL completo SQLite. 26 tablas en 8 bloques.

| Líneas | Bloque | Tablas |
|--------|--------|--------|
| 1-30 | Header + PRAGMA | Convenciones, FK ON, WAL |
| 32-100 | Bloque 1 RBAC/Auth | permisos, roles, rol_permisos, usuarios, usuarios_roles, sesiones, contrasenas_temporales |
| 102-230 | Bloque 2 Operación | hoteles, tipos_habitacion, habitaciones, turnos, usuarios_turnos, asignaciones, checklists_template, items_checklist, ejecuciones_checklist, ejecuciones_items |
| 232-260 | Bloque 3 Auditoría | auditorias (con UNIQUE(ejecucion_id) para inmutabilidad) |
| 262-325 | Bloque 4 Alertas | alertas_activas, bitacora_alertas, alertas_config |
| 327-380 | Bloque 5 Cloudbeds | cloudbeds_sync_historial, cloudbeds_config |
| 382-410 | Bloque 6 Tickets | tickets |
| 412-460 | Bloque 7 Logs | logs_eventos, audit_log |
| 462-500 | Bloque 8 Copilot | copilot_conversaciones, copilot_mensajes |
| 502-511 | Fin | Total 26 tablas |

---

## docs/auth.md (160 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §1 Login | Formulario, validación RUT (módulo 11), endpoint POST /api/auth/login, decisión de home_target |
| §2 Logout | DELETE sesión |
| §3 Cambio contraseña | Validaciones (8 chars, letra+número), endpoint |
| §4 Reset admin | Pwd temporal sin caracteres ambiguos, audit log |
| §5 Creación | Pwd temporal al crear usuario |
| §6 AuthCheck | Middleware, sliding window 8h |
| §7 PermissionCheck | Middleware con OR múltiple |
| §8 Script rescate | scripts/reset-admin-password.php |
| §9 Seguridad | bcrypt, no loggear tokens, HTTPS, rate limit |

---

## docs/habitaciones.md (120 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 Estados (6) | sucia, en_progreso, completada_pendiente_auditoria, aprobada, aprobada_con_observacion, rechazada |
| §3 Transiciones | Diagrama + prohibidas + EstadoHabitacionService |
| §4 Sync Cloudbeds | Entrada 2x/día + salida on-aprobación |
| §5 Filtrado hotel | 1_sur / inn / ambos, persistencia |
| §6 Asignación | Manual, round-robin, reasignar, reordenar cola |
| §7 Endpoints REST | Resumen tabla |

---

## docs/checklist.md (160 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 Templates | Por tipo de habitación, items ordenados, obligatorio flag |
| §3 Ejecución | Inicio, persistencia tap-a-tap (PUT /api/ejecuciones/{id}/items/{item_id}) |
| §3.3 Offline | Cola localStorage, reintento, badge sincronizando |
| §3.4 Reanudar | Tras cerrar app |
| §3.5 Botón terminada | Gate 100% obligatorios |
| §4 Tracking oculto | timestamp_inicio/fin nunca visible al trabajador |
| §5 Items desmarcados por auditor | Flujo + efectos |

---

## docs/auditoria.md (180 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 3 estados | aprobado, aprobado_con_observacion, rechazado |
| §3 Inmutabilidad | 409 Conflict + UNIQUE(ejecucion_id) + UI opacidad 50% |
| §4.1-4.3 Flujo por veredicto | Modal, items_desmarcados, alerta rechazado |
| §5 Post-auditoría | Solo lectura, detalle histórico |
| §6 Endpoint POST /api/auditoria/{habitacion_id} | Request, errores 409/404/403/400 |

---

## docs/cloudbeds.md (140 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 Credenciales .env | CLOUDBEDS_API_KEY, rotación, nunca loggear |
| §3 Endpoints Cloudbeds | getRooms, getRoomStatuses, postHousekeepingStatus |
| §4 Sync entrante | Cron 2x/día (07:00/15:00) + manual |
| §5 Sync saliente | Cola reintentos 1s/2s/4s, alerta P0 al fallar |
| §5.2 401 inválida | No reintenta, alerta P0 inmediata |
| §6 Sanitización | LogSanitizer.sanitize() obligatoria |
| §7 Config | cloudbeds_config (schedule, timeout, reintentos) |

---

## docs/alertas-predictivas.md (150 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 Prioridades | P0/P1/P2/P3 + UI |
| §3 Tipos (6) | cloudbeds_sync_failed, trabajador_en_riesgo, habitacion_rechazada, fin_turno_pendientes, trabajador_disponible, ticket_nuevo |
| §4 Algoritmo predictivo | tiempo_promedio_personal × habitaciones_restantes, margen 15 min |
| §4.4 Trabajador nuevo | Fallback 30 min/habitación |
| §5 Permisos | alertas.recibir_predictivas (trabajador NUNCA) |
| §6 Ciclo BD | alertas_activas → bitacora_alertas |
| §7 Config | alertas_config claves + defaults |

---

## docs/copilot-ia.md (180 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 Stack | Claude API, claude-sonnet-4-6, timeout 30s |
| §3 Permisos | Nivel 1 consultas / Nivel 2 acciones |
| §4 Tools dinámicas | Filtradas por permisos RBAC del usuario |
| §5 Prompts por rol | Base + rol-specific |
| §6 UI | FAB sparkles + panel slide-up/lateral |
| §7 Voz | Web Speech API es-CL |
| §9 Auditoría | audit_log con origen=copilot |
| §10 Doble validación | Frontend + backend re-validan cada tool |

---

## docs/tickets.md (90 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §1 Alcance MVP | Simple, no workflow complejo |
| §3 Estados | abierto → en_progreso → resuelto → cerrado |
| §4 Permisos | crear, ver_propios, ver_todos |
| §5 Crear | Desde Home o copilot + alerta P2 |
| §6 Gestión | Tomar/asignar/resolver/cerrar/reabrir |

---

## docs/turnos.md (100 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §1 Turnos base | mañana 08-16, tarde 14-22 |
| §2 Modelo | turnos + usuarios_turnos (UNIQUE usuario+fecha) |
| §3 Asignación | Calendario semanal, copiar semana |
| §4 Estados derivados | fuera_turno / pre / activo / disponible / post |
| §5 Reglas | Sin overtime, sin cross-midnight MVP |

---

## docs/usuarios.md (115 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §2 Trabajadores | Subconjunto filtrado por rol, no entidad separada |
| §3 CRUD | Listar, detalle, crear (pwd temporal), editar, activo, roles |
| §3.7 Safeguards | Auto-desactivación, último admin |
| §4 Mi cuenta | Todos los usuarios |

---

## docs/ajustes.md (145 líneas, v1.0)

Módulo separado `/ajustes`. 8 secciones filtradas por permisos.

| Sección | Contenido |
|---------|-----------|
| §2.2 Secciones | Mi cuenta, Roles/Permisos, Usuarios, Turnos, Checklists, Alertas, Cloudbeds, Logs |
| §3 Matriz RBAC | Tabla editable con checkboxes + safeguards |
| §4 Alertas config | Editar umbrales |
| §5 Cloudbeds | Credenciales .env + sync schedule + historial |
| §6 Mi cuenta | Datos, seguridad, copilot historial, logout |

---

## docs/logs.md (110 líneas, v1.0)

| Sección | Contenido |
|---------|-----------|
| §1 Dos tablas | logs_eventos (técnico) vs audit_log (acciones) |
| §2 NO loggear | Tokens, API keys, passwords, Authorization |
| §3 Formato | Niveles INFO/WARNING/ERROR + campos audit_log |
| §4 Viewer | 2 tabs + filtros + JSON pretty |
| §5 Rotación | Post-MVP (90 días eventos, 1 año audit) |

---

## docs/api-endpoints.md (170 líneas, v1.0)

Referencia maestra de todos los endpoints REST. Índice por módulo.

| Sección | Módulo |
|---------|--------|
| §2 | Auth |
| §3 | Usuarios |
| §4 | Roles/permisos |
| §5 | Habitaciones |
| §6 | Asignaciones |
| §7 | Checklist |
| §8 | Auditoría |
| §9 | Alertas |
| §10 | Tickets |
| §11 | Turnos |
| §12 | Cloudbeds |
| §13 | Copilot |
| §14 | Homes (agregados) |
| §15 | Sistema |
| §16 | Logs |
| §17 | Disponibilidad/Notificaciones |

Convenciones (§1): respuestas `{ok, data}` / `{ok, error}`, códigos 200/201/400/401/403/404/409/500, paginación `?pagina&por_pagina`, scope "propio" filtrado por sesión.
