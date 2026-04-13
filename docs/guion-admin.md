# Guión de diseño — Home del Administrador

**Para usar en:** próxima conversación con Claude (Haiku/Sonnet) en claude.ai
**Pantalla a diseñar:** Home del rol Admin
**Documentos de referencia que ya existen:**
- `docs/handoff-2026-04-08.md` — contexto general del proyecto
- `docs/home-trabajador.md` — primera Home diseñada (referencia de formato y filosofía UX)
- `docs/home-supervisora.md` — segunda Home diseñada (referencia de alertas, estado de equipo, auditoría)
- `docs/home-recepcion.md` — tercera Home diseñada (si ya existe al momento de usar este guión)
- `plan.md` v3.1 — sección 5.4.1 (Admin tiene todos los permisos), sección 8.10 (módulo de Ajustes)

**Resultado esperado:** generar al final un archivo `docs/home-admin.md` con la misma estructura y nivel de detalle que las otras Homes.

> Sigue los temas en este orden. En cada tema están las preguntas concretas que tienes que responder. Las que tienen ⭐ son las críticas; las que tienen 💡 son sugerencias mías que probablemente vas a aceptar pero quiero validar.

---

## Contexto del rol de Admin

Antes de arrancar, lo que ya sabemos del rol:

**Permisos por defecto** (definidos en plan.md §5.4.1):
- **Todos los permisos del catálogo** — control total del sistema

**Quién es el Admin en la práctica:**
- Nicolás Campos (gerente de operaciones) y su jefe
- NO son personal operativo — no limpian habitaciones, no están en recepción
- Usan la app para **configurar, supervisar a nivel macro y resolver problemas**
- Probablemente acceden menos frecuentemente que la Supervisora o Recepción, pero cuando entran, necesitan poder completo
- Acceden más desde **desktop/tablet** que desde móvil (pero mobile-first sigue siendo la regla)

**Lo que el Admin tiene que NO tienen otros roles:**
- Gestión de usuarios (CRUD completo)
- Gestión de roles y permisos (la matriz RBAC dinámica)
- Gestión de turnos
- Configuración de Cloudbeds (credenciales, horarios de sync)
- Configuración de alertas predictivas (umbrales)
- Logs del sistema
- Editor global de checklists
- KPIs globales (base para Fase 2)
- Copilot con historial de todos los usuarios

**La pregunta fundamental:** El Admin ya tiene acceso a todo esto desde el módulo de **Ajustes** (plan.md §8.10). Entonces, ¿qué muestra su Home que no sea simplemente un menú de enlaces a Ajustes?

---

## Decisiones marco antes de arrancar

**P0.1 ⭐ — Filosofía de la Home del Admin**

El Admin responde a preguntas de nivel estratégico:
- "¿Cómo va la operación general hoy?"
- "¿Hay algún problema del sistema que deba atender?" (sync caída, errores, etc.)
- "¿El equipo está rindiendo bien?"

Pero también tiene un problema: **muchas de las cosas del Admin viven en Ajustes, no en la Home**. La Home tiene que justificar su existencia.

Tres enfoques posibles:
- (a) **Dashboard ejecutivo** — KPIs, métricas del día, gráficos simples. El Admin ve la "foto macro" de la operación. Las acciones viven en otras pantallas.
- (b) **Centro de control** — accesos rápidos a las funciones principales (usuarios, roles, cloudbeds, etc.) + resumen de estado. Más operativo que analítico.
- (c) **Híbrido de Supervisora + Ajustes** — como la Home de la Supervisora pero con accesos extra a configuración. Reusa componentes.

Mi recomendación: **(a)** con toques de **(b)**. La Home del Admin debe darle una foto rápida de la operación (métricas) + alertas del sistema (sync, errores) + accesos rápidos a las funciones que más usa. No debería ser una réplica de la Supervisora con más botones.

**P0.2 ⭐ — ¿El Admin ve lo mismo que la Supervisora + extras, o una pantalla completamente distinta?**

Opciones:
- (a) **Home propia y distinta** — diseñada desde cero para el perfil del Admin
- (b) **Home de Supervisora + secciones extra** — reusa la Home de la Supervisora y agrega secciones (KPIs, accesos a Ajustes, estado del sistema)
- (c) **Home minimalista** — el Admin casi no necesita Home porque todo lo importante está en Ajustes. La Home es un dashboard simple con métricas y enlaces rápidos.

Mi recomendación: **(c)** para el MVP. El Admin no vive en la Home — entra, ve que todo está bien, y si necesita actuar va a la sección específica. No vale la pena diseñar una Home compleja que va a ser usada 2-3 veces al día por 1-2 personas. Si necesita ver alertas o estado del equipo, puede ir a esas secciones directamente.

**P0.3 — Multi-hotel**

El Admin siempre gestiona ambos hoteles (Atankalama Inn + Atankalama). Su Home debe mostrar datos consolidados o con selector.

¿Reusamos el patrón de la Supervisora (selector "Ambos hoteles" / hotel específico)?

---

## TEMA 1 — Header del Admin

**T1.1** — ¿El header reusa el patrón de las otras Homes (avatar + saludo)?

**T1.2** — ¿Selector de hotel en el header? (como la Supervisora) ¿O la Home del Admin siempre muestra ambos hoteles consolidados?

**T1.3** — ¿Indicador de estado del sistema en el header? Algo como un semáforo:
- 🟢 Todo OK — Cloudbeds sincronizado, sin errores
- 🟡 Advertencia — sync retrasada o errores menores
- 🔴 Problema — sync fallida, errores críticos

💡 Esto sería muy útil para el Admin que entra a "verificar que todo anda bien".

**T1.4** — ¿Campana de notificaciones? El Admin podría recibir:
- Errores de sincronización con Cloudbeds
- Alertas predictivas (tiene el permiso)
- Habitaciones rechazadas
- Tickets nuevos
- Errores del sistema / logs críticos

¿Merece campana con badge o es mejor una sección de "alertas del sistema" en el body?

---

## TEMA 2 — Sección de estado del sistema (salud técnica)

**T2.1 ⭐** — Esta sería la sección más diferenciadora del Admin respecto a los otros roles. Muestra la "salud" del sistema:

Indicadores candidatos:
- Estado de sincronización con Cloudbeds (última sync exitosa, próxima programada)
- Cantidad de errores en logs hoy
- Estado de la base de datos (tamaño, si aplica)
- Usuarios activos ahora / en el último turno
- Versión de la app

¿Cuáles entran al MVP y cuáles son overkill?

Mi recomendación para MVP: solo **estado de Cloudbeds** (última sync + estado) + **errores recientes** (contador). Lo demás es Fase 2.

**T2.2** — ¿Dónde va esta sección? ¿Arriba de todo (porque el Admin primero quiere saber si el sistema funciona) o después de las métricas operativas?

**T2.3** — ¿Las alertas de sistema (sync fallida, errores) se muestran igual que las alertas de la Supervisora (tarjetas con botones de acción) o con un diseño diferente?

---

## TEMA 3 — Sección de métricas operativas del día

**T3.1 ⭐** — El "dashboard ejecutivo". ¿Qué métricas muestra?

Candidatas para el MVP:
- **Habitaciones totales hoy** — limpias / en proceso / pendientes / no asignadas (por hotel y consolidado)
- **Auditorías del día** — aprobadas / con observación / rechazadas
- **Trabajadores activos** — cuántos en turno, cuántos con carga completa
- **Tickets abiertos** — cuántos sin atender
- **Tiempo promedio de limpieza hoy** — global o por hotel

Candidatas para Fase 2 (mostrar como "Próximamente" o no mostrar):
- Tendencias (hoy vs ayer, esta semana vs anterior)
- Ranking de trabajadores por velocidad/calidad
- Tasa de rechazo de auditoría
- Gráficos de barras/líneas

**T3.2** — ¿Cómo se visualizan las métricas?

Opciones:
- (a) **Tarjetas de conteo** — 4-6 tarjetas en grid, cada una con número grande + label + ícono (estilo dashboard)
- (b) **Lista vertical** — más compacta, cada métrica es una fila
- (c) **Gráficos simples** — barras horizontales o donuts (más visual pero más complejo)

Mi recomendación para MVP: **(a)** tarjetas de conteo. Simples, claras, se leen rápido. Los gráficos son Fase 2.

**T3.3** — ¿Las métricas son tappables? Ej: tocar "5 rechazadas" lleva a la bandeja de auditoría filtrada por rechazadas. Útil pero agrega complejidad.

**T3.4** — ¿Mostramos métricas separadas por hotel o solo consolidadas?

Mi recomendación: consolidadas por defecto, con selector de hotel (reusar patrón de Supervisora) para desglosar.

---

## TEMA 4 — Sección de accesos rápidos

**T4.1** — El Admin accede frecuentemente a:
- Gestión de usuarios
- Matriz RBAC (roles y permisos)
- Configuración de Cloudbeds
- Configuración de alertas predictivas
- Logs del sistema

Todo esto vive en Ajustes. ¿La Home tiene accesos directos a estas secciones o el Admin simplemente va a la tab de Ajustes?

**T4.2 💡** — Mi recomendación: **NO poner accesos rápidos en la Home**. Todo está en Ajustes, que es una tab del bottom bar. Duplicar accesos agrega ruido. La Home del Admin debe ser solo métricas + estado del sistema, no un menú.

**T4.3** — Si decidimos poner accesos rápidos: ¿cómo se ven?
- Grid de iconos con labels (estilo "panel de control")
- Lista vertical con chevron
- Chips scrollables horizontales

---

## TEMA 5 — Sección de alertas (operativas, no del sistema)

**T5.1** — El Admin tiene permiso `alertas.recibir_predictivas`. ¿Muestra las mismas alertas que la Supervisora (trabajador en riesgo, habitación rechazada, etc.)?

Opciones:
- (a) **Sí, mismas alertas** — reusar la sección de alertas de la Supervisora
- (b) **Solo las críticas** — P0 (sync fallida) y P1 (trabajador en riesgo, rechazos), no las P2
- (c) **No, el Admin no ve alertas operativas en su Home** — las ve si va a la sección de alertas desde otra parte

Mi recomendación: **(b)** — solo P0 y P1. El Admin no necesita saber que "María está disponible" (P2). Eso es gestión operativa de la Supervisora. Pero sí necesita saber si hay un problema gordo.

**T5.2** — Si muestra alertas: ¿van antes o después de las métricas?

Mi recomendación: antes. Mismo patrón que la Supervisora: lo urgente arriba.

**T5.3** — ¿Reusar el componente de alertas de la Supervisora (tarjeta con botones de acción) o un formato más compacto para el Admin?

---

## TEMA 6 — Bottom tab bar del Admin

**T6.1 ⭐** — El Admin tiene acceso a todo. Hay que elegir qué tabs entran. Candidatas:

- 🏠 Inicio (Home/Dashboard)
- 🛏️ Habitaciones (vista global)
- 👥 Usuarios
- 🔍 Auditoría
- 🎫 Tickets
- ⚙️ Ajustes (aquí vive la mayor parte de las funciones del Admin)

Son 6, el bottom bar admite 4-5.

**T6.2 💡** — Mi propuesta: **Inicio / Habitaciones / Ajustes** (3 tabs) + FAB del copilot. O si queremos 4: **Inicio / Habitaciones / Auditoría / Ajustes**.

Razonamiento: el Admin no necesita tab de Tickets (es MVP simplificado) ni de Usuarios (está dentro de Ajustes). Lo que más usa: Home para el vistazo rápido, Habitaciones si quiere ver detalle operativo, Ajustes para configurar.

**T6.3** — ¿O debería el Admin tener **5 tabs** incluyendo más funciones?

Propuesta alternativa: **Inicio / Habitaciones / Auditoría / Usuarios / Ajustes** (5 tabs).

**T6.4** — En desktop, sidebar con todos los módulos expandidos (el Admin es el que más aprovecha el espacio extra).

---

## TEMA 7 — FAB del copilot IA

**T7.1** — Confirmar que el FAB va exactamente igual que en los otros roles (esquina inferior derecha, siempre visible).

**T7.2** — El Admin tiene copilot nivel 1+2 y además `copilot.ver_historial_todos` (puede ver conversaciones de otros usuarios). ¿Esto se accede desde el FAB o desde Ajustes?

Mi recomendación: desde Ajustes. El FAB es para interactuar con el copilot propio.

**T7.3** — Consultas típicas del Admin al copilot:
- "¿Cómo va la operación hoy?"
- "¿Cuántas habitaciones se rechazaron esta semana?"
- "¿La sincronización con Cloudbeds está funcionando?"
- "Resetea la contraseña de María López"

El copilot con todos los permisos es muy poderoso. ¿Alguna consideración especial para la UI?

Mi recomendación: ninguna. El FAB es idéntico. El poder está en el backend.

---

## TEMA 8 — Notificaciones y refresco

**T8.1** — ¿Cada cuánto se refresca la Home del Admin?

Contexto:
- Trabajador: cada 2 minutos
- Supervisora: cada 60 segundos
- Recepción: cada 30-60 segundos

El Admin no necesita tiempo real tan agresivo. Las métricas y el estado del sistema cambian más lento.

Mi recomendación: **cada 2-5 minutos**. El Admin no está mirando la pantalla esperando cambios.

**T8.2** — ¿Pull-to-refresh en móvil? (Sí, consistente con las otras Homes).

**T8.3** — ¿Refresco inmediato tras ejecutar acciones? (Sí, estándar).

---

## TEMA 9 — Estados especiales

**T9.1** — Estado "todo perfecto" — operación sin problemas, sin errores, todo sincronizado. ¿Mensaje tipo "La operación va bien, sin novedades"?

**T9.2** — Estado "Cloudbeds caído" — ¿Banner prominente? Para el Admin esto es especialmente crítico porque él es quien configura las credenciales.

**T9.3** — Estado "no hay trabajadores en turno" — ¿La Home muestra las métricas en 0 o un mensaje explicativo?

**T9.4** — Estado "primer uso / sistema recién instalado" — No hay datos históricos, no hay trabajadores creados. ¿La Home guía al Admin a completar la configuración inicial?

💡 Este último es interesante: un "wizard de setup" o al menos un checklist de "cosas que debes configurar" podría ser muy útil:
- [ ] Crear usuarios
- [ ] Asignar roles
- [ ] Configurar turnos
- [ ] Verificar conexión con Cloudbeds
- [ ] Crear/revisar checklists por tipo de habitación

¿Entra al MVP o es Fase 2?

---

## TEMA 10 — Permisos requeridos

**T10.1** — El Admin tiene TODOS los permisos. Pero la Home se renderiza dinámicamente según permisos (patrón establecido en Supervisora y Recepción).

¿Qué permisos específicos controlan qué secciones de la Home del Admin?
- Métricas operativas: `kpis.ver_globales`?
- Estado del sistema: `cloudbeds.ver_estado_sincronizacion` + `logs.ver`?
- Alertas: `alertas.recibir_predictivas`?

**T10.2** — Si alguien crea un "sub-admin" con permisos limitados, la Home debe degradarse graciosamente (ocultar secciones sin permiso). Confirmar que aplica el mismo patrón.

---

## TEMA 11 — Datos del backend (endpoint)

**T11.1** — Definir el shape del JSON que el endpoint `GET /api/home/admin` debe retornar. Probable contenido:
- Datos del usuario
- Estado del sistema (última sync Cloudbeds por hotel, errores recientes)
- Métricas operativas del día (contadores de habitaciones por estado, auditorías, trabajadores activos)
- Alertas activas P0-P1 (si aplica)
- Hotel seleccionado o consolidado

**T11.2** — ¿Un endpoint que devuelve todo o varios separados? Consistente con las otras Homes: uno solo para el MVP.

**T11.3** — ¿Las métricas se calculan en el backend (query a la BD) o se derivan de otros endpoints existentes?

Mi recomendación: calculadas en el backend con queries optimizadas. No reusar llamadas a otros endpoints internamente.

---

## TEMA 12 — Responsive

**T12.1** — En móvil: una columna, scroll vertical (consistente).

**T12.2 ⭐** — En desktop: el Admin es el rol que más aprovecha el espacio extra. Propuesta:
- Fila superior: estado del sistema (tarjetas anchas)
- Grid de métricas: 3-4 columnas de tarjetas de conteo
- Si hay alertas: panel lateral derecho o sección ancha

**T12.3** — ¿Breakpoint donde el layout cambia?

---

## TEMA 13 — Modo día/noche

Confirmar que aplica igual que en las otras Homes (`dark:` en todo, persistencia en localStorage, sin flash al cargar).

---

## TEMA 14 — Accesibilidad

**T14.1** — Mismos lineamientos:
- Áreas tappables ≥ 44x44px
- Tipografía mínima legible
- Contraste WCAG AA
- `aria-label` en botones con solo icono
- Foco visible

**T14.2** — La Home del Admin tiene métricas numéricas. Asegurar que los números grandes tienen suficiente contraste y que las etiquetas descriptivas acompañan a cada número (no solo un "12" suelto sin contexto).

---

## TEMA 15 — Checklist final de comportamientos críticos

Al final, generar el checklist de cosas que Claude Code debe verificar:

- [ ] Las métricas operativas reflejan datos reales (queries correctas)
- [ ] El estado de Cloudbeds muestra la última sincronización real
- [ ] Las alertas P0-P1 aparecen si existen
- [ ] El selector de hotel filtra las métricas correctamente
- [ ] La Home se refresca al intervalo definido
- [ ] Las secciones se ocultan dinámicamente según permisos
- [ ] En desktop las métricas aprovechan el ancho disponible
- [ ] El estado "primer uso" muestra guía de configuración (si se aprueba)
- [ ] Pull-to-refresh funciona en móvil
- [ ] El copilot FAB está siempre visible
- [ ] (más a definir durante la conversación)

---

## TEMA 16 — Vinculación con otros módulos

Probables:
- **Depende de:** `docs/auth.md`, `docs/habitaciones.md`, `docs/cloudbeds.md`, `docs/copilot-ia.md`, `docs/alertas-predictivas.md`, `docs/roles-permisos.md`
- **Accede a (desde Ajustes):** `docs/usuarios.md`, `docs/turnos.md`, `docs/ajustes.md`, `docs/checklist.md`
- **Comparte componentes con:** `docs/home-supervisora.md` (alertas, selector hotel), `docs/home-recepcion.md` (métricas de habitaciones)

---

## TEMA 17 — Notas operativas para Claude Code al codificar

Confirmar:
- Modo de codificación: **supervisión por módulo** (UI crítica)
- Archivos sugeridos a crear (controllers, services, vistas, partials)
- Tests sugeridos (queries de métricas, estado de sistema, permisos)
- Reutilización de componentes de alertas (de Supervisora) y contadores (de Recepción si aplica)

---

## Orden recomendado para la conversación

1. **Decisiones marco** (P0.1, P0.2, P0.3) — definir si es dashboard ejecutivo, centro de control o minimalista
2. **Tema 1** (Header) — corto
3. **Tema 2** (Estado del sistema) — lo más diferenciador del Admin
4. **Tema 3** (Métricas operativas) — el corazón del dashboard
5. **Tema 4** (Accesos rápidos) — probablemente se descarta rápido
6. **Tema 5** (Alertas) — definir qué niveles ve
7. **Tema 6** (Bottom tab bar) — navegación
8. **Temas 7, 13, 14** (FAB, modo nocturno, accesibilidad) — confirmaciones rápidas
9. **Tema 8** (Refresco) — definir cadencia
10. **Tema 9** (Estados especiales) — el "primer uso" es el más interesante
11. **Tema 10** (Permisos) — confirmar patrón dinámico
12. **Tema 11** (Endpoint backend) — técnico
13. **Tema 12** (Responsive) — al final
14. **Temas 15, 16, 17** (Checklist, vinculaciones, notas) — cierre

**Tiempo estimado:** 1-1.5 horas de conversación. Similar a Recepción en complejidad, pero con decisiones más estratégicas sobre qué mostrar vs qué dejar en Ajustes.

---

## Cómo arrancar la conversación con Claude

Sugerencia de mensaje inicial:

> "Hola Claude. Estoy diseñando la cuarta y última pantalla Home de una app de limpieza hotelera. Ya tenemos tres Homes diseñadas (Trabajador, Supervisora y Recepción) y ahora toca la del Administrador. Te paso los documentos que necesitas leer en este orden:
>
> 1. `handoff-2026-04-08.md` — contexto general del proyecto
> 2. `home-trabajador.md` — primera Home diseñada (referencia de formato)
> 3. `home-supervisora.md` — segunda Home diseñada (comparte alertas y selector hotel)
> 4. `home-recepcion.md` — tercera Home diseñada (comparte métricas de habitaciones)
> 5. `guion-admin.md` — los temas a cubrir en esta conversación
>
> Una vez leídos, dime que estás listo y arrancamos por las decisiones marco P0.1, P0.2 y P0.3. Vamos a ir tema por tema en el orden recomendado. Mi estilo: micro-pasos, opciones concretas a/b/c, una pregunta a la vez. Nada de listas gigantes ni preguntas múltiples sin pausa."

---

## Recordatorios importantes para mantener la coherencia

Al diseñar la Home del Admin, no olvidar:

- **El Admin es Nicolás y su jefe** — no son operativos, son estratégicos. No limpian ni auditan en la práctica
- **El módulo de Ajustes ya tiene casi todo** — la Home no debe duplicar funcionalidad, sino dar visibilidad rápida
- **Todos los permisos** — el Admin ve todo, pero eso no significa que su Home deba mostrar todo. Priorizar lo relevante
- **RBAC dinámico** — no chequear rol "admin", chequear permisos específicos. Un "sub-admin" con permisos reducidos debe ver una Home degradada graciosamente
- **Dos hoteles siempre** — el Admin gestiona ambos, su Home debe reflejarlo
- **Mobile-first** sigue siendo la regla aunque el Admin use más desktop
- **Cloudbeds es crítico** — si la sync falla, el Admin es quien tiene que actuar (tiene `cloudbeds.configurar_credenciales`)
- **KPIs completos son Fase 2** — en el MVP solo mostramos contadores básicos, no tendencias ni gráficos
- **El copilot con todos los permisos es muy poderoso** — pero la UI del FAB es idéntica
- **Estado "primer uso"** — el Admin es el primero en entrar al sistema recién instalado. Pensar en esa experiencia

---

*Este guión está diseñado para conducir una conversación de diseño productiva con cualquier modelo de Claude. Si la conversación se desvía o se atasca, vuelve a este orden de temas.*
