# QA Checklist — Home del Administrador

**Archivo complementario de:** `docs/home-admin.md`
**Versión:** 1.0
**Fecha:** 14 de abril de 2026

> Checklist exhaustiva de comportamientos a verificar antes de aprobar el módulo. Los 10 comportamientos más críticos viven inline en `home-admin.md §15`; este archivo contiene el resto.

El Admin es el controlador del sistema — máxima confiabilidad requerida.

---

## Datos del endpoint y cálculos

- [ ] Las métricas operativas reflejan datos reales (queries correctas, no cached)
- [ ] El estado de Cloudbeds muestra la última sincronización real
- [ ] Las alertas P0-P1 aparecen si existen (query a tabla `alertas_tecnicas`)
- [ ] Eficiencia = (completadas / asignadas) × 100, NO incluye no_asignadas
- [ ] Tasa de rechazo = (rechazadas / total_auditadas) × 100 (no % de todas)
- [ ] Tiempo promedio solo suma habitaciones completadas (no en progreso/pendientes)
- [ ] El endpoint `GET /api/home/admin` retorna JSON completo en <500ms
- [ ] Cada métrica tiene una fuente de datos verificable (query específica)
- [ ] Los cálculos de KPI son independientes y no reutilizan datos erróneos
- [ ] Las métricas consolidadas (cuando selecciona "Ambos hoteles") suman correctamente
- [ ] Las métricas por hotel NO incluyen datos del otro hotel

## Filtrado por hotel

- [ ] El selector de hotel filtra las métricas correctamente (Ambos / ATAN Inn / ATAN)
- [ ] El selector de hotel persiste la selección en localStorage
- [ ] Si selecciona "Ambos hoteles", cada métrica muestra agrupación visual (ATAN Inn vs ATAN)
- [ ] Los contadores y KPIs se actualizan al cambiar hotel (sin page reload)

## Estados de KPI

- [ ] Los estados de KPIs cambian correctamente (🟢 OK, 🟡 ALERTA, 🔴 CRÍTICO)
- [ ] El estado "CRÍTICO" de un KPI genera una alerta técnica inmediatamente
- [ ] Los colores son consistentes (verde/amarillo/rojo en todas las métricas)

## Refresco automático

- [ ] La Home se refresca cada 30 min automáticamente
- [ ] El refresco automático NO recarga la pantalla (actualiza datos sin flash)
- [ ] El botón de refresco fuerza actualización inmediata
- [ ] El spinner de refresco aparece en el botón (feedback visual)
- [ ] El refresco automático respeta la zona horaria del servidor (timestamps correctos)
- [ ] Pull-to-refresh funciona en móvil (gesto de arrastrar desde arriba)

## Alertas técnicas

- [ ] Las 5 alertas técnicas se muestran en orden de prioridad (P0 primero)
- [ ] Si hay >5 alertas, aparece botón "Ver todas"
- [ ] Las alertas técnicas persisten hasta resolverse (no desaparecen automáticamente)
- [ ] Los botones de acción en alertas ejecutan correctamente (Reintentar sync, etc.)
- [ ] Los botones de acción en alertas registran la acción en `bitacora_alertas` con timestamp
- [ ] Las tarjetas de alertas tienen borde izquierdo coloreado por prioridad
- [ ] Las tarjetas de alertas son tappables completas (no solo los botones)

## Indicador de estado del sistema

- [ ] El indicador de estado (🟢 🟡 🔴) en el header refleja la salud real
- [ ] El indicador cambia en tiempo real (actualiza con refresco)
- [ ] 🟢 = Cloudbeds OK + sin errores críticos
- [ ] 🟡 = sync retrasada >30 min OR errores menores
- [ ] 🔴 = sync fallida OR errores críticos

## Permisos dinámicos

- [ ] Las secciones se ocultan dinámicamente según permisos
- [ ] Si usuario NO tiene `alertas.recibir_predictivas`, tab "Inicio" se oculta
- [ ] Si usuario NO tiene `kpis.ver_operativas`, tab "Operativas" se oculta
- [ ] Si usuario NO tiene `sistema.ver_salud`, tab "Técnicas" se oculta
- [ ] Si usuario NO tiene `ajustes.acceder`, tab "Ajustes" se oculta
- [ ] Los cambios en permisos del usuario se reflejan inmediatamente (sin reload)

## Responsive y layout

- [ ] En desktop el layout es de 2 columnas (Operativas izq | Técnicas der)
- [ ] En desktop, las columnas tienen altura equilibrada (visual)
- [ ] En mobile, una columna, scroll vertical (consistente)
- [ ] Las tarjetas respetan el ancho mínimo en móvil (no se achatan)
- [ ] En desktop, si una columna es más corta, no hay desequilibrio visual grave

## Header

- [ ] El header con avatar, saludo, rol, selector hotel e indicador funciona
- [ ] El header es sticky (se queda arriba al scroll)
- [ ] El header no cubre contenido (z-index correcto)
- [ ] El selector de hotel abre dropdown/modal correctamente
- [ ] La campana de notificaciones muestra badge si hay alertas sin leer
- [ ] El botón de refresco tiene spinner mientras se refresca
- [ ] El avatar muestra iniciales del nombre completo (ej: `NC` para Nicolás Campos)

## FAB Copilot

- [ ] El copilot FAB está siempre visible
- [ ] El copilot FAB no queda debajo del bottom tab bar (posicionamiento correcto)
- [ ] Al tocar el FAB, abre panel copilot (móvil: deslizable desde abajo, desktop: lateral)
- [ ] El icono del FAB es Lucide `sparkles` (estandarizado en las 4 Homes)

## UI/UX general

- [ ] En dark mode, los números grandes en KPIs tienen contraste suficiente
- [ ] Los números están en formato legible (ej: "28 min", no "1680 sec")
- [ ] Las unidades son claras (min, %, MB, etc.)
- [ ] Los estados "OK/ALERTA/CRÍTICO" usan colores consistentes (verde/amarillo/rojo)
- [ ] Todas las áreas tappables ≥ 44x44px

## Tab bar

- [ ] El bottom tab bar tiene el tab actual destacado visualmente
- [ ] Los tabs son tappables y cambian de vista correctamente
- [ ] Orden: [Inicio] [Operativas] [Técnicas] [Ajustes]
- [ ] Tab "Ajustes" navega al módulo separado (`/ajustes`), no tiene contenido embebido

## Seguridad

- [ ] NO se exponen API keys ni credenciales en el JSON del endpoint
- [ ] Los datos de tokens expirados no muestran el token, solo estado
- [ ] Las acciones de refresco/retry se registran en audit log
- [ ] El campo "usuarios activos ahora" excluye al admin que está viendo

## Performance

- [ ] El endpoint retorna en <500ms incluso con datos grandes (100+ habitaciones)
- [ ] Las queries usan índices correctamente (no hay full table scans)
- [ ] El refresco automático no aumenta memoria (no hay memory leak)
