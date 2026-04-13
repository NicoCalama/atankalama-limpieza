# Guión de diseño — Home del Trabajador de Recepción

**Para usar en:** próxima conversación con Claude (Haiku/Sonnet) en claude.ai
**Pantalla a diseñar:** Home del rol Recepción
**Documentos de referencia que ya existen:**
- `docs/handoff-2026-04-08.md` — contexto general del proyecto
- `docs/home-trabajador.md` — pantalla ya diseñada (referencia de formato y filosofía UX)
- `docs/home-supervisora.md` — pantalla ya diseñada (referencia de cómo manejar auditoría y multi-hotel)
- `plan.md` v3.1 — sección 5.4.4 (permisos de recepción), sección 8.4 (auditoría con 3 estados)

**Resultado esperado:** generar al final un archivo `docs/home-recepcion.md` con la misma estructura y nivel de detalle que `docs/home-trabajador.md` y `docs/home-supervisora.md`.

> Sigue los temas en este orden. En cada tema están las preguntas concretas que tienes que responder. Las que tienen ⭐ son las críticas; las que tienen 💡 son sugerencias mías que probablemente vas a aceptar pero quiero validar.

---

## Contexto del rol de Recepción

Antes de arrancar, lo que ya sabemos del rol:

**Permisos por defecto** (definidos en plan.md §5.4.4):
- `habitaciones.ver_todas` — solo lectura, ve estado de todas las habitaciones
- `auditoria.ver_bandeja` — ve habitaciones pendientes de auditoría
- `auditoria.aprobar` — puede dar veredicto "aprobada"
- `auditoria.aprobar_con_observacion` — puede dar veredicto "aprobada con observación" (con desmarcado de ítems)
- `auditoria.rechazar` — puede dar veredicto "rechazada"
- `auditoria.editar_checklist_durante_auditoria` — puede desmarcar ítems del checklist al aprobar con observación
- `copilot.usar_nivel_1_consultas` — puede hacer preguntas al copilot IA
- `copilot.usar_nivel_2_acciones` — puede pedir acciones al copilot IA

**Lo que Recepción NO tiene:**
- No asigna ni reasigna habitaciones
- No ve alertas predictivas (eso es solo para supervisora)
- No gestiona usuarios, roles, turnos ni ajustes
- No crea tickets de mantenimiento (en el MVP)
- No ve KPIs de equipo

**Función principal:** Recepción es el **puente entre limpieza y huéspedes**. Necesita saber qué habitaciones están listas para check-in, cuáles están en proceso, y auditar la calidad cuando una habitación se marca como terminada.

---

## Decisiones marco antes de arrancar

**P0.1 ⭐ — Filosofía de la Home de Recepción**

Recepción responde a preguntas distintas que la Supervisora y el Trabajador:
- "¿Qué habitaciones puedo entregar ahora para check-in?"
- "¿Cuáles están pendientes de auditar?"
- "¿Cuánto falta para que terminen las que están en limpieza?"

La Home de Recepción probablemente se parece más a un **tablero de estado de habitaciones** que a un dashboard de gestión de equipo.

Pregunta concreta: ¿La Home de Recepción prioriza **(a)** el estado de las habitaciones (qué está limpio, qué no) o **(b)** la bandeja de auditoría (qué necesita mi revisión)?

Mi recomendación: **(a)** como contenido principal, con un acceso prominente a **(b)**. La realidad es que Recepción mira esta pantalla muchas veces al día para responder a huéspedes que preguntan "¿ya está lista mi habitación?", y audita solo cuando hay habitaciones completadas esperando revisión.

**P0.2 ⭐ — Relación con la Supervisora en auditoría**

Esto ya se resolvió en `docs/home-supervisora.md`: ambas auditan en paralelo, no hay jerarquía entre ellas. La inmutabilidad post-auditoría aplica igual — quien audita primero, cierra.

Pregunta a confirmar: ¿Recepción ve las mismas habitaciones pendientes de auditar que la Supervisora, o solo ve las de cierto hotel? (Recordar que la Supervisora tiene selector "Ambos hoteles" / hotel específico).

Mi recomendación: Recepción por defecto trabaja en **un hotel específico** (el que tiene asignado o donde está físicamente). Sin selector multi-hotel — Recepción está siempre en un escritorio de un hotel específico. Si la empresa necesita que alguien de recepción vea ambos, el Admin le asigna permisos extra y se agrega un selector, pero eso no es el default MVP.

**P0.3 — Volumen de uso vs. profundidad**

Recepción probablemente abre la app muchas veces al día pero pasa poco tiempo cada vez (consulta rápida: "¿habitación 205 está lista?"). A diferencia de la Supervisora que puede pasar 10 minutos revisando el estado del equipo.

Pregunta: ¿Diseñamos para **consultas rápidas** (información densa, poco scroll) o para **sesiones de trabajo** (más detalle, más acciones)?

Mi recomendación: consultas rápidas. La Home debe responder la pregunta en los primeros 2 segundos sin scroll.

---

## TEMA 1 — Header de Recepción

**T1.1** — ¿El header reusa el patrón de las otras Homes (avatar + saludo + indicadores)?

**T1.2** — ¿Mostramos el nombre del hotel en el header? A diferencia de la Supervisora (que tiene selector), Recepción probablemente trabaja siempre en el mismo hotel, pero el contexto ayuda.

**T1.3 💡** — ¿Incluimos un indicador del estado de sincronización con Cloudbeds en el header? Recepción depende mucho de que los estados estén actualizados. Si Cloudbeds no ha sincronizado en un rato, un badge de advertencia podría ser útil.

**T1.4** — ¿Campana de notificaciones? Recepción tiene menos fuentes de notificaciones que la Supervisora. Las posibles:
- Habitación completada por trabajador → lista para auditar
- Resultado de una auditoría que hizo (confirmación)
- Cloudbeds fuera de sincronización

¿Justifica una campana o es ruido?

---

## TEMA 2 — Sección principal: Estado de habitaciones

**T2.1 ⭐** — Esta es la pieza central. Recepción necesita ver de un vistazo qué habitaciones hay y en qué estado están. ¿Cómo lo visualizamos?

Opciones:
- (a) **Lista vertical agrupada por estado** — primero las limpias/listas, luego en limpieza, luego sucias/pendientes
- (b) **Grid de tarjetas** — cada habitación es una tarjeta con color según estado (verde=limpia, amarillo=en limpieza, rojo=sucia, azul=auditada)
- (c) **Vista tipo "tablero kanban"** — columnas por estado con las habitaciones dentro
- (d) **Resumen numérico arriba** (12 limpias / 5 en proceso / 3 sucias) + lista debajo filtrable

Mi recomendación: **(d)** — resumen numérico como tarjetas de conteo arriba (responde la pregunta en 2 segundos), lista filtrable debajo para los detalles. En móvil el resumen es horizontal scrollable o 2x2, en desktop es una fila completa.

**T2.2** — ¿Qué estados de habitación mostramos? Candidatos:
- **Limpia / Lista para check-in** (ya auditada y aprobada)
- **Completada, pendiente de auditoría** (el trabajador terminó pero nadie la ha revisado)
- **En limpieza** (trabajador está ejecutando el checklist)
- **Sucia / Pendiente** (asignada pero no iniciada)
- **No asignada** (sin trabajador asignado aún)
- **Ocupada** (huésped dentro, no aplica limpieza hoy)
- **Fuera de servicio** (mantenimiento)

¿Cuáles son relevantes para Recepción en el MVP y cuáles son ruido?

**T2.3** — ¿Cada habitación en la lista muestra qué información?

Candidatas:
- Número de habitación (obligatorio)
- Tipo (Doble, Suite, VIP)
- Estado actual (con color/badge)
- Nombre del trabajador asignado
- Tiempo estimado de finalización (si está en limpieza)
- Si tiene huésped esperando check-in (dato de Cloudbeds)
- Hora de check-in del próximo huésped
- Piso / ubicación

**T2.4 💡** — ¿Filtros rápidos? Chips en la parte superior para filtrar por estado. Ejemplo: [Todas] [Listas ✅] [Pendientes auditoría 🔍] [En limpieza 🧹] [Sucias].

**T2.5** — ¿Buscador? Un campo de búsqueda rápida por número de habitación sería muy útil para el caso "un huésped pregunta por SU habitación". ¿Lo incluimos arriba?

**T2.6** — ¿Qué pasa cuando Recepción toca una habitación de la lista?
- Si está pendiente de auditoría → ¿abre directamente la pantalla de auditoría?
- Si está en otro estado → ¿abre un detalle solo lectura?
- ¿O siempre abre el mismo detalle y adapta los botones según estado?

---

## TEMA 3 — Sección de auditoría pendiente

**T3.1 ⭐** — ¿La auditoría tiene su propia sección destacada en la Home, o es un filtro más de la lista de habitaciones (Tema 2)?

Opciones:
- (a) **Sección separada** encima o debajo de la lista de habitaciones — "3 habitaciones esperan tu auditoría" con acceso directo
- (b) **Integrada en la lista** — las habitaciones pendientes de auditoría se muestran con badge destacado pero dentro de la misma lista
- (c) **Tab independiente** — la auditoría vive en su propia tab del bottom bar, no en la Home

Mi recomendación: **(a)** o **(c)**. La auditoría es la función más importante de Recepción después de consultar estados. Merece visibilidad propia.

Nota: La Supervisora tiene auditoría como una tab del bottom bar (Inicio / **Auditoría** / Tickets / Ajustes). ¿Recepción debería tener la misma tab?

**T3.2** — Si es sección en la Home: ¿cómo se ve?
- Contador: "3 habitaciones completadas esperan tu auditoría"
- Lista compacta de las pendientes (número + tipo + trabajador que la limpió + hace cuánto terminó)
- Botón "Auditar" en cada una o botón general "Ir a auditoría"

**T3.3** — ¿Mostramos las habitaciones ya auditadas por Recepción hoy? (historial del día)
- Podría ser útil como confirmación: "hoy ya audité 8 habitaciones"
- O podría ser ruido innecesario

**T3.4** — Recordar: la auditoría usa los mismos 3 botones que la Supervisora (Aprobar / Aprobar con observación / Rechazar) y la misma inmutabilidad post-auditoría. No hay que rediseñar el flujo, solo decidir cómo se accede desde la Home de Recepción.

---

## TEMA 4 — Bottom tab bar de Recepción

**T4.1 ⭐** — Las tabs del bottom bar de Recepción son distintas a las del Trabajador y la Supervisora. Tabs candidatas:

- 🏠 Inicio (Home con estado de habitaciones)
- 🔍 Auditoría (bandeja completa)
- 🛏️ Habitaciones (vista global — ¿o ya está en Inicio?)
- 💬 Copilot IA (en vez de FAB, como tab)
- ⚙️ Ajustes (mínimos para Recepción: cambiar contraseña, modo día/noche)

Recepción tiene menos funciones que la Supervisora. Podrían ser solo 3-4 tabs.

**T4.2 💡** — Mi propuesta: **Inicio / Auditoría / Ajustes** (3 tabs) + FAB del copilot. Recepción no necesita tab de Tickets (no crea tickets en el MVP) ni de Habitaciones si la Home ya muestra todo.

¿O son 4? **Inicio / Auditoría / Copilot / Ajustes** — poniendo copilot como tab en vez de FAB?

**T4.3** — El FAB del copilot: ¿se mantiene como FAB flotante (igual que Trabajador y Supervisora) o se mueve a una tab? Mi recomendación: mantener el FAB por consistencia entre roles.

**T4.4** — En desktop, ¿sidebar con los mismos items o se expanden opciones?

---

## TEMA 5 — FAB del copilot IA

**T5.1** — Confirmar que el FAB va exactamente igual que en Trabajador y Supervisora (esquina inferior derecha, siempre visible).

**T5.2** — Recepción tiene acceso a nivel 1 (consultas) y nivel 2 (acciones) del copilot. Las consultas típicas serían:
- "¿La habitación 205 está lista?"
- "¿Quién está limpiando la 302?"
- "¿Cuántas habitaciones quedan por limpiar?"

Las acciones típicas:
- "Muéstrame las pendientes de auditoría"
- (¿Otras?)

¿El FAB se ve idéntico o tiene algún indicador contextual?

Mi recomendación: FAB idéntico. Las capacidades se descubren al usarlo.

---

## TEMA 6 — Notificaciones y actualizaciones en tiempo real

**T6.1 ⭐** — ¿Cada cuánto se refresca la Home de Recepción?

Contexto:
- Trabajador: cada 2 minutos
- Supervisora: cada 60 segundos

Recepción necesita datos frescos porque responde a huéspedes en el momento. Pero no necesita el nivel de la Supervisora (que toma decisiones operativas).

Mi recomendación: **cada 60 segundos**, igual que la Supervisora. O incluso **cada 30 segundos** dado que la consulta es ligera (solo estados de habitaciones).

**T6.2** — ¿Refresco inmediato después de auditar una habitación? (Sí, obligatorio — pero confirmar).

**T6.3** — ¿Pull-to-refresh en móvil? (Sí, consistente con las otras Homes).

**T6.4** — Cuando una habitación cambia de estado (ej: un trabajador marca como terminada), ¿aparece alguna notificación visual en la Home? Opciones:
- (a) Solo se actualiza en el próximo refresh — simple
- (b) Badge/toast que dice "Habitación 205 lista para auditar" — más útil pero más complejo
- (c) El número del contador de "pendientes de auditoría" se anima brevemente para llamar la atención

---

## TEMA 7 — Estados especiales

**T7.1** — Estado "todas las habitaciones están limpias y auditadas" — todo el hotel está perfecto. ¿Qué muestra la Home?
- Mensaje tipo "Todas las habitaciones están listas ✅"
- ¿Desaparece la sección de auditoría o muestra "0 pendientes"?

**T7.2** — Estado "Cloudbeds desincronizado" — los estados de habitaciones pueden no ser confiables. ¿Banner de advertencia? ¿Dónde?

**T7.3** — Estado "sin habitaciones completadas hoy" (inicio del turno, nadie ha terminado nada aún) — ¿qué muestra la sección de auditoría?

**T7.4** — Estado "todas las completadas ya fueron auditadas por la Supervisora" — Recepción llega y no tiene nada que auditar porque la Supervisora fue más rápida. ¿Mensaje o simplemente la sección vacía?

---

## TEMA 8 — Permisos requeridos

**T8.1** — Listar exactamente qué permisos necesita un usuario para ver esta Home:
- `habitaciones.ver_todas` — para ver el estado de todas las habitaciones
- `auditoria.ver_bandeja` — para ver pendientes de auditoría
- `auditoria.aprobar` — para ejecutar auditorías
- `auditoria.aprobar_con_observacion` — para el flujo de observación
- `auditoria.rechazar` — para rechazar
- `auditoria.editar_checklist_durante_auditoria` — para desmarcar ítems
- ¿Cuáles más?

**T8.2** — Si un usuario tiene solo algunos de estos permisos (ej: puede ver habitaciones pero no auditar), ¿las secciones sin permiso se ocultan dinámicamente? (Consistente con la decisión de la Supervisora: sí).

---

## TEMA 9 — Datos del backend (endpoint)

**T9.1** — Definir el shape del JSON que el endpoint `GET /api/home/recepcion` debe retornar. Probable contenido:
- Datos del usuario y hotel
- Resumen de habitaciones por estado (contadores)
- Lista de habitaciones (con estado, tipo, trabajador, etc.)
- Habitaciones pendientes de auditoría (lista separada o marcadas en la lista principal)
- Estado de sincronización con Cloudbeds
- Notificaciones sin leer (si aplica)

**T9.2** — ¿Un endpoint que devuelve todo o varios separados? Mi recomendación para el MVP: uno solo, consistente con la Supervisora.

**T9.3** — ¿Qué datos de Cloudbeds necesitamos para esta Home?
- Estado de limpieza de cada habitación (clean/dirty/inspected)
- ¿Dato de check-in del próximo huésped? (útil para priorizar auditorías)
- ¿Ocupación actual? (para saber cuáles están ocupadas y no aplica limpieza)

---

## TEMA 10 — Responsive

**T10.1** — En móvil: una columna, scroll vertical (mismo patrón que Trabajador y Supervisora).

**T10.2** — En tablet/desktop: ¿cómo se reorganiza?

Propuesta: como la Home de Recepción es más "tablero de estado" que "dashboard de gestión", en desktop podríamos mostrar un **grid más grande de habitaciones** aprovechando el ancho. Algo como:
- Arriba: contadores de resumen en una fila
- Medio: grid de tarjetas de habitaciones (3-4 columnas)
- Derecha (sidebar): pendientes de auditoría como panel fijo

**T10.3** — ¿Breakpoint donde el layout cambia?

---

## TEMA 11 — Modo día/noche

Confirmar que aplica igual que en las otras Homes (`dark:` en todo, persistencia en localStorage, sin flash al cargar).

No requiere mucha discusión, solo confirmación.

---

## TEMA 12 — Accesibilidad

**T12.1** — Confirmar los mismos lineamientos:
- Áreas tappables ≥ 44x44px
- Tipografía mínima legible
- Contraste WCAG AA
- `aria-label` en botones con solo icono
- Foco visible

**T12.2** — La Home de Recepción puede tener muchas habitaciones visibles (20-40 en un hotel grande). ¿Hay riesgo de información demasiado densa? Validar tamaños de fuente en los contadores y lista.

---

## TEMA 13 — Checklist final de comportamientos críticos

Al final, generar el checklist de cosas que Claude Code debe verificar al terminar la pantalla:

- [ ] Los contadores de estado de habitaciones reflejan datos reales
- [ ] Las habitaciones pendientes de auditoría están destacadas visualmente
- [ ] El flujo de auditoría (3 botones) funciona correctamente desde la Home
- [ ] La inmutabilidad post-auditoría se respeta (habitaciones ya auditadas no muestran botones)
- [ ] El filtrado/búsqueda por número de habitación funciona
- [ ] La Home se refresca al intervalo definido
- [ ] Refresco inmediato tras auditar una habitación
- [ ] Las secciones se ocultan dinámicamente según permisos del usuario
- [ ] El badge de Cloudbeds desincronizado aparece cuando corresponde
- [ ] Pull-to-refresh funciona en móvil
- [ ] El copilot FAB está siempre visible
- [ ] (más a definir durante la conversación)

---

## TEMA 14 — Vinculación con otros módulos

Probables:
- **Depende de:** `docs/auth.md`, `docs/auditoria.md`, `docs/habitaciones.md`, `docs/cloudbeds.md`, `docs/copilot-ia.md`
- **No depende de:** `docs/asignacion.md` (Recepción no asigna), `docs/alertas-predictivas.md` (solo Supervisora), `docs/tickets.md` (Recepción no crea tickets en el MVP)
- **Comparte flujo con:** `docs/home-supervisora.md` (auditoría — mismo flujo de 3 botones, misma inmutabilidad)

---

## TEMA 15 — Notas operativas para Claude Code al codificar

Confirmar:
- Modo de codificación: **supervisión por módulo** (UI crítica)
- Archivos sugeridos a crear (controllers, services, vistas, partials)
- Tests sugeridos (shape del endpoint, contadores correctos, permisos)
- Reutilización de componentes de auditoría ya definidos en home-supervisora.md

---

## Orden recomendado para la conversación

1. **Empezar por las decisiones marco** (P0.1, P0.2, P0.3) — sin esto el resto no fluye
2. **Tema 1** (Header) — corto, calienta motores
3. **Tema 2** (Estado de habitaciones) — la sección más importante, es el corazón de esta Home
4. **Tema 3** (Auditoría pendiente) — la segunda función más importante
5. **Tema 4** (Bottom tab bar) — define la navegación
6. **Tema 5** (FAB copilot) — confirmación rápida
7. **Tema 6** (Notificaciones/refresco) — define el comportamiento en tiempo real
8. **Tema 7** (Estados especiales) — pulir bordes
9. **Temas 8, 11, 12** (Permisos, modo nocturno, accesibilidad) — confirmaciones
10. **Tema 9** (Endpoint del backend) — técnico, requiere cabeza fría
11. **Tema 10** (Responsive) — al final, cuando todo lo demás está claro
12. **Temas 13, 14, 15** (Checklist final, vinculaciones, notas) — cierre

**Tiempo estimado:** 1-1.5 horas de conversación. Más corta que la Supervisora porque Recepción tiene menos complejidad.

---

## Cómo arrancar la conversación con Claude

Sugerencia de mensaje inicial:

> "Hola Claude. Estoy diseñando la tercera pantalla Home de una app de limpieza hotelera. Ya tenemos dos Homes diseñadas (Trabajador y Supervisora) y ahora toca la de Recepción. Te paso los documentos que necesitas leer en este orden:
>
> 1. `handoff-2026-04-08.md` — contexto general del proyecto
> 2. `home-trabajador.md` — primera Home diseñada (referencia de formato)
> 3. `home-supervisora.md` — segunda Home diseñada (comparte flujo de auditoría con Recepción)
> 4. `guion-recepcion.md` — los temas a cubrir en esta conversación
>
> Una vez leídos, dime que estás listo y arrancamos por las decisiones marco P0.1, P0.2 y P0.3. Vamos a ir tema por tema en el orden recomendado. Mi estilo: micro-pasos, opciones concretas a/b/c, una pregunta a la vez. Nada de listas gigantes ni preguntas múltiples sin pausa."

---

## Recordatorios importantes para mantener la coherencia

Al diseñar la Home de Recepción, no olvidar:

- **Recepción es principalmente un auditor y un consultante de estados** — no gestiona equipo ni asigna
- **La auditoría es compartida con la Supervisora** — ambas pueden auditar, quien lo hace primero cierra la habitación (inmutabilidad)
- **RBAC dinámico** — no chequear rol "recepcion", chequear permisos específicos
- **Mobile-first** sigue siendo la regla, aunque Recepción probablemente usa más una tablet o PC detrás del mostrador
- **Los estados de habitaciones vienen de Cloudbeds** — la sincronización es crítica para esta Home
- **El copilot nivel 1+2** está disponible — Recepción puede preguntar y pedir acciones al copilot
- **Recepción NO ve alertas predictivas** — eso es exclusivo de la Supervisora
- **Recepción NO crea tickets** en el MVP — esa funcionalidad viene en Fase 2
- **Recepción trabaja en UN hotel** (el default MVP), a diferencia de la Supervisora que puede ver ambos
- **Consultas rápidas** — esta Home se abre muchas veces al día, cada visita es breve. Optimizar para respuesta inmediata

---

*Este guión está diseñado para conducir una conversación de diseño productiva con cualquier modelo de Claude. Si la conversación se desvía o se atasca, vuelve a este orden de temas.*
