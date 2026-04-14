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
