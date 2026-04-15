# Atankalama Aplicación Limpieza — Plan General (MVP)

**Proyecto:** Sistema de gestión de limpieza hotelera — reemplazo de Flexkeeping
**Empresa:** Atankalama Corp
**Propiedades:** Hotel Atankalama Inn (Chorrillos 558) y Hotel Atankalama (1 Sur 858) — Calama, Chile
**Fecha del documento:** 13 de abril de 2026
**Versión:** 3.1 (incluye decisiones del diseño detallado de la Home de la Supervisora)
**Estado:** Planificación general — esqueleto consolidado con decisiones de diseño
**Repositorio:** `atankalama-limpieza` (público, GitHub / NicoCalama)
**Autor de referencia:** Nicolás Campos (Informática / Atankalama Corp)

> Este documento es el **esqueleto consolidado** del proyecto. Incluye todas las decisiones tomadas durante las conversaciones iniciales de planificación, incluyendo decisiones arquitectónicas importantes como el sistema RBAC dinámico y la lógica de auditoría con 3 estados. El detalle fino de cada pantalla vivirá en archivos individuales dentro de `docs/` que se generarán en la siguiente fase.

---

## 1. Resumen ejecutivo

Desarrollo de una aplicación web mobile-first para reemplazar Flexkeeping (USD $450/mes ≈ CLP $3.240.000/año) en la gestión de limpieza de las dos propiedades de Atankalama Corp. La app obtiene habitaciones a limpiar desde Cloudbeds, permite asignación (manual o automática equitativa), ejecución con checklist digital por el personal de limpieza, auditoría de calidad con tres niveles de resolución, y sincronización inmediata del estado de limpieza de vuelta a Cloudbeds al completar una habitación.

Incluye un **copilot conversacional con IA (Claude)** integrado — voz y texto — que permite a cada rol ejecutar consultas y acciones propias de sus permisos mediante lenguaje natural.

**Diferenciadores clave vs Flexkeeping:**
- Copilot IA integrado por voz y texto (Claude), respetando permisos por rol
- **Sistema RBAC dinámico** — permisos editables sin tocar código
- **Alertas predictivas** que avisan a la supervisora cuando un trabajador no va a alcanzar a terminar su turno
- **Auditoría con 3 estados** — aprobado limpio, aprobado con observación (trazabilidad a nivel de ítem del checklist), rechazado
- 100% adaptado al flujo y lenguaje del hotel (español, RUT, turnos mineros)
- Costo marginal cero (infraestructura existente absorbida por equipo TI interno)
- Extensible: el día de mañana integrable dentro del "Chat Interno" existente del hotel

**Destino del código:** la aplicación será **codificada por Claude Code** corriendo en VS Code, en modo de autonomía híbrida (ver sección 16). Toda la documentación de este proyecto está pensada para servir como especificación ejecutable por un agente de codificación.

---

## 2. Objetivos del MVP

1. **Demostrar una app 100% usable end-to-end** — no un prototipo, sino algo que se pueda poner en manos del personal y funcione.
2. **Mobile-first real** — diseñada primero para celular y tablet, porque ese es el dispositivo primario del personal de limpieza.
3. **Integración real con Cloudbeds** — lectura 2×/día de habitaciones a limpiar, escritura inmediata al marcar una habitación como lista.
4. **Copilot IA funcional** — Niveles 1 (consultas) y 2 (acciones simples) operativos por voz y texto.
5. **Sistema RBAC dinámico operativo** — permisos por rol editables desde Ajustes sin tocar código.
6. **Alertas predictivas simples funcionando** — la supervisora recibe avisos automáticos cuando un trabajador está en riesgo de no terminar su turno.
7. **Persistencia robusta del progreso del trabajador** — si la app se cierra, nada se pierde.
8. **Preparar el terreno para Fase 2** — dejar "guiños" visibles (tickets de mantenimiento simples, analítica básica, funciones grises en Ajustes) sin construir los módulos completos.
9. **Codebase limpia y bien documentada** — apta para que Claude Code la mantenga y la extienda con mínima fricción humana.

---

## 3. Stack tecnológico

### Backend
- **Lenguaje:** PHP 8.2 (requisito de gerencia)
- **Base de datos (MVP):** SQLite — un solo archivo, cero configuración, fácil de migrar a Postgres/Supabase en el futuro
- **Hosting:** Hostinger VPS con EasyPanel
- **Integraciones externas:** Cloudbeds API (2 API keys, una por propiedad), Claude API

### Frontend (decisión confirmada por gerencia)
- **Stack:** PHP server-rendered con **plantillas PHP nativas** (sin Blade ni Twig para el MVP)
- **Estilos:** Tailwind CSS **por CDN** — sin build step, sin npm para el frontend
- **Interactividad:** Alpine.js **por CDN** — para interactividad puntual (checkboxes del checklist, FAB del copilot, modales, modo día/noche, panel del copilot)
- **Iconos:** Lucide via CDN
- **Tipografía:** Inter via Google Fonts CDN
- **Enfoque:** mobile-first con breakpoints de Tailwind (`sm:`, `md:`, `lg:`)
- **Línea visual:** heredada del "Chat Interno" del hotel

### IA
- **Claude API** para el copilot conversacional (modelo exacto a definir en fase de implementación, probablemente `claude-sonnet-4-6` o `claude-haiku-4-5` según costo/latencia)
- **Web Speech API** del navegador para transcripción de voz (gratis, nativa, suficiente para MVP; migrable a Whisper si es necesario más adelante)

### Herramientas de desarrollo
- **Editor:** VS Code en Windows
- **Agente de codificación:** Claude Code (extensión VS Code)
- **Control de versiones:** Git + GitHub (repo público `atankalama-limpieza`)
- **Seguridad de secretos:** `gitleaks` (escaneo pre-commit obligatorio)
- **MCPs:** GitHub, Filesystem, SQLite, Context7, Playwright (configurados en `.mcp.json` del proyecto)

---

## 4. Filosofía de diseño

### 4.1 Mobile-first real
- Diseño base para 375px de ancho (celular), escala automática a tablet y desktop con breakpoints de Tailwind
- **Navegación inferior (bottom tab bar)** en móvil — más cómoda con el pulgar
- **Sidebar** solo en tablet horizontal y desktop
- Botones mínimo 44px de alto — usables con guantes o manos húmedas
- Checkboxes de checklist tappables en **toda la fila**, no solo en el cuadradito
- Tipografía legible a distancia de brazo

### 4.2 Línea visual (heredada del Chat Interno del hotel)
- Azul primario consistente con botones "Filtrar" y sidebar activa del Chat Interno
- Badges redondeados con colores suaves: amarillo = pendiente, verde = completado, rojo = urgente
- Fondo gris muy claro en modo día, gris muy oscuro en modo noche
- Tipografía sans-serif limpia (Inter)
- Iconos minimalistas tipo Lucide
- **Modo día / modo noche** con toggle accesible desde cualquier pantalla (incluyendo antes de iniciar sesión)

### 4.3 UX centrada en no generar ansiedad al personal
Decisión de diseño importante tomada en la conversación inicial: las pantallas del trabajador de limpieza deliberadamente **ocultan métricas y contadores numéricos** ("te quedan 3", "vas al 67%") porque pueden generar ansiedad. En cambio, mostramos **barras de progreso visuales** que transmiten sensación de avance sin presionar. La presión se gestiona desde el rol de la supervisora vía alertas predictivas (ver sección 9).

### 4.4 Idioma
100% español chileno.

---

## 5. Roles y permisos (RBAC dinámico)

### 5.1 Decisión arquitectónica clave

El sistema de permisos es **RBAC dinámico** — no hardcodeado en el código. Esto significa:

- Los roles viven en una tabla `roles` de la BD
- Los permisos viven en una tabla `permisos` (catálogo maestro de todos los permisos posibles)
- La relación se gestiona con una tabla `rol_permisos`
- Los usuarios apuntan a un `rol_id`
- El código nunca pregunta `if ($usuario->rol === 'admin')` — siempre pregunta `if ($usuario->tienePermiso('habitaciones.asignar'))`
- Existe una **pantalla en Ajustes del Admin** con una matriz visual (filas = permisos, columnas = roles, celdas = checkboxes) donde el admin gestiona todo sin tocar código

### 5.2 Qué es dinámico y qué no

**100% dinámico (sin código, sin redeploy):**
- Asignación de permisos existentes a roles
- Creación de roles nuevos (ej: "Jefe de Turno", "Auditor Externo", "Lavandería")
- Edición de nombres de roles
- Eliminación de roles (con validación: no se puede borrar un rol que tenga usuarios asignados)

**Requiere cambio de código (pero gestionado):**
- Agregar **permisos nuevos** al catálogo — esto ocurre naturalmente cuando se agregan features nuevas al sistema, porque el código nuevo necesita chequear esos permisos
- Se gestiona con un archivo único `database/seeds/permisos.php` que es el catálogo maestro
- Al desplegar una versión nueva, el sistema detecta permisos nuevos, los inserta en la tabla, y aparecen automáticamente en la pantalla de Ajustes

### 5.3 Catálogo inicial de permisos (MVP)

El Admin los distribuye entre roles vía la pantalla de Ajustes. Agrupados por módulo:

**Habitaciones**
- `habitaciones.ver_todas`
- `habitaciones.ver_asignadas_propias`
- `habitaciones.asignar`
- `habitaciones.reasignar`
- `habitaciones.marcar_completada`
- `habitaciones.ver_historial`

**Checklists**
- `checklists.ver`
- `checklists.editar`
- `checklists.crear_nuevos`

**Asignaciones**
- `asignaciones.asignar_manual`
- `asignaciones.auto_asignar`
- `asignaciones.reordenar_cola_trabajador`

**Auditoría**
- `auditoria.ver_bandeja`
- `auditoria.aprobar`
- `auditoria.aprobar_con_observacion`
- `auditoria.rechazar`
- `auditoria.editar_checklist_durante_auditoria`

**Tickets de mantenimiento**
- `tickets.crear`
- `tickets.ver_propios`
- `tickets.ver_todos`

**Usuarios**
- `usuarios.ver`
- `usuarios.crear`
- `usuarios.editar`
- `usuarios.resetear_password`
- `usuarios.activar_desactivar`
- `usuarios.asignar_rol`

**Roles y permisos (metapermisos)**
- `roles.ver`
- `roles.crear`
- `roles.editar`
- `roles.eliminar`
- `permisos.asignar_a_rol`

**Turnos**
- `turnos.ver`
- `turnos.crear_editar`
- `turnos.asignar_a_usuario`

**Copilot IA**
- `copilot.usar_nivel_1_consultas`
- `copilot.usar_nivel_2_acciones`
- `copilot.ver_historial_propio`
- `copilot.ver_historial_todos`

**Cloudbeds**
- `cloudbeds.ver_estado_sincronizacion`
- `cloudbeds.forzar_sincronizacion`
- `cloudbeds.configurar_credenciales`

**KPIs y reportes (base para Fase 2)**
- `kpis.ver_propios`
- `kpis.ver_equipo`
- `kpis.ver_globales`

**Alertas predictivas**
- `alertas.recibir_predictivas`
- `alertas.configurar_umbrales`

**Logs del sistema**
- `logs.ver`

### 5.4 Roles por defecto (pre-cargados al crear la BD)

El sistema viene con 4 roles pre-cargados, pero todos son editables desde Ajustes:

**5.4.1 Admin** — control total. Pensado para Nicolás Campos y su jefe.
- Todos los permisos del catálogo

**5.4.2 Supervisora**
- Asignaciones, reasignaciones, edición de checklists, auditorías (incluye los 3 niveles), tickets ver_todos, consultas generales, alertas predictivas, copilot nivel 1 y 2, reordenamiento de colas

**5.4.3 Trabajador de limpieza**
- `habitaciones.ver_asignadas_propias`, `habitaciones.marcar_completada`, `tickets.crear`, `tickets.ver_propios`, `copilot.usar_nivel_1_consultas`, `copilot.usar_nivel_2_acciones`, `copilot.ver_historial_propio`, `kpis.ver_propios`

**5.4.4 Recepción**
- `habitaciones.ver_todas` (solo lectura), `auditoria.ver_bandeja`, `auditoria.aprobar`, `auditoria.aprobar_con_observacion`, `auditoria.rechazar`, `auditoria.editar_checklist_durante_auditoria`, `copilot.usar_nivel_1_consultas`, `copilot.usar_nivel_2_acciones`

---

## 6. Gestión de turnos

Los trabajadores tienen un **turno asignado** que se usa para el cálculo de las alertas predictivas. Para el MVP:

- Cuando el Admin crea un usuario, le asigna un turno en el formulario de creación
- Los turnos viven en una tabla `turnos` con campos: id, nombre, hora_inicio, hora_fin
- El Admin puede crear/editar turnos desde Ajustes → Turnos
- Turnos pre-cargados por defecto: **Diurno (08:00-17:00)** y **Nocturno (20:00-05:00)**
- Fase 2 abordará casos complejos: cambio de turno puntual, doblar turno, turnos rotativos

---

## 7. Flujo general del sistema

1. **Lectura desde Cloudbeds** — la app consulta automáticamente **2 veces al día** (horarios configurables desde Ajustes, alineados con turnos mineros) las habitaciones con estado "Dirty" o "Pending Cleanup" en ambas propiedades.
2. **Visualización en el panel de la supervisora** — aparece la lista de habitaciones pendientes, separadas por hotel y tipo.
3. **Asignación** — la supervisora asigna manualmente o usa el botón "auto-asignar equitativamente" (round-robin entre el personal de limpieza activo ese día). Puede reordenar la cola de cada trabajador.
4. **Ejecución** — el trabajador abre la app, ve su habitación actual, entra a ella, completa el checklist tap por tap. **Cada tap se guarda inmediatamente en la BD** (ver sección 8.3).
5. **Cierre** — al completar el último ítem del checklist, el botón "Habitación terminada" se desbloquea. Al presionarlo:
   - La app envía inmediatamente un PUT a Cloudbeds → habitación pasa a "Clean"
   - Queda registrada en la base de datos local con timestamp de inicio, fin y usuario
   - Aparece en la bandeja de auditoría de recepción y supervisora
6. **Auditoría con 3 resultados posibles** (ver sección 8.4):
   - **Aprobada** → se mantiene "Clean" en Cloudbeds
   - **Aprobada con observación** → se mantiene "Clean" pero queda registro de items desmarcados
   - **Rechazada** → vuelve a "Dirty" en Cloudbeds + la supervisora decide a quién reasignar
7. **Trazabilidad** — cada acción queda registrada en el log del sistema, incluyendo acciones ejecutadas vía copilot IA (marcadas con flag `via_copilot_ia`).

---

## 8. Módulos de la aplicación

### 8.1 Módulo de autenticación
- Login con **RUT** (formato flexible: acepta con/sin puntos, con/sin guión; valida dígito verificador en el cliente antes de enviar)
- Contraseña
- **No** hay checkbox "mantener sesión iniciada" — la sesión dura un tiempo razonable porque los dispositivos son personales
- "¿Olvidé mi contraseña?" → mensaje indicando contactar al administrador (el admin resetea desde su panel y genera una contraseña temporal)
- **Primer login obligatorio con cambio de contraseña** — si el usuario entra con una contraseña temporal, la app lo fuerza a cambiarla antes de acceder a cualquier otra pantalla
- Contraseñas temporales generadas por el admin: 6 caracteres alfanuméricos sin ambigüedades (sin 0/O, 1/l/I) — ejemplo `K7M4XP` — fáciles de dictar por WhatsApp
- Toggle sol/luna accesible desde esta pantalla (antes de loguearse)

### 8.2 Módulo de Home / Dashboard
Cuatro versiones distintas según el rol logueado. Lineamiento general: al abrir la app, el usuario ve inmediatamente **lo que tiene que hacer ahora**, no un menú.

**8.2.1 Home del Trabajador de Limpieza (YA DISEÑADA EN DETALLE)**

Layout en 5 secciones mobile-first:

1. **Header** — saludo contextual por hora del día + nombre + hotel + avatar + campana de notificaciones
2. **Tarjeta de progreso del día** — solo barra visual con segmentos de colores. **SIN texto numérico** (evita ansiedad). Cuando termina todas las habitaciones, la tarjeta cambia a verde con mensaje de felicitación
3. **Habitación actual** — tarjeta destacada con número, tipo, estado. Botón principal **dinámico**:
   - Si nunca la tocó → "Comenzar limpieza"
   - Si ya la tocó pero no terminó → "Continuar" (recupera el progreso del checklist exactamente donde lo dejó)
4. **Lista del resto de habitaciones asignadas** — lista compacta tappeable
5. **Bottom tab bar** — Inicio / Habitaciones / Tickets / Ajustes + **FAB del copilot IA** flotante en esquina inferior derecha

**Comportamientos críticos:**
- **Persistencia a nivel de cada tap** del checklist (ver 8.3)
- **Tracking de tiempo oculto** al trabajador (para KPIs y alertas predictivas)
- **Estado vacío** (sin habitaciones asignadas): mensaje amigable + botón "Avisar a supervisora que estoy disponible" que le manda notificación interna a la supervisora
- **Historial propio** accesible desde la tab "Habitaciones" → muestra habitaciones limpiadas con solo estado `aprobada/rechazada`, sin métricas ni tiempos
- **Habitaciones con observación de auditoría** — aparecen como aprobadas en la UI del trabajador, pero internamente afectan KPIs (el trabajador no ve nada, la supervisora sí)
- **Habitaciones rechazadas** — aparecen en el historial marcadas como rechazadas; la supervisora recibe alerta y decide a quién reasignar la re-limpieza

**8.2.2 Home de la Supervisora** — ✅ DISEÑADA EN DETALLE en `docs/home-supervisora.md` v2.1

Layout en 4 secciones mobile-first: Header (con selector de hotel y opción "Ambos hoteles") + Sección de Alertas Urgentes (top 5 con jerarquía de prioridades) + Estado del Equipo (lista vertical con números visibles) + Bottom Tab Bar (Inicio / Auditoría / Tickets / Ajustes) + FAB del copilot. Refresco cada 60 segundos. Los detalles completos viven en el archivo de documentación.

**8.2.3 Home del Trabajador de Recepción** — PENDIENTE DE DISEÑO DETALLADO

**8.2.4 Home del Admin** — PENDIENTE DE DISEÑO DETALLADO

### 8.3 Módulo de checklist (persistencia y tracking de tiempo)

**Persistencia robusta:**
- Cada tap de un checkbox del checklist dispara un request al backend que actualiza `ejecuciones_checklist_items` en la BD
- Si no hay internet en el momento del tap, el cambio queda en una cola local que se sincroniza cuando vuelve la conexión
- Si la app se cierra (batería, crash, llamada, lo que sea), al volver a abrirla la habitación aparece como "Continuar" y los checks ya marcados están ahí
- Nunca se pierde progreso, pase lo que pase

**Tracking de tiempo:**
- `timestamp_inicio` — registrado automáticamente cuando el trabajador toca "Comenzar limpieza" por primera vez
- `timestamp_fin` — registrado cuando toca "Habitación terminada"
- El trabajador **nunca ve estos timestamps ni el tiempo transcurrido** en su pantalla
- Esta data se usa exclusivamente para:
  - Cálculo del tiempo promedio personal (input del algoritmo de alertas predictivas)
  - Reportes, estadísticas y KPIs del módulo de Analytics (Fase 2 completa, versión básica en MVP)

**Botón de ticket de mantenimiento:** siempre visible dentro de la pantalla del checklist, para reportar problemas sin salir.

### 8.4 Módulo de Auditoría (3 estados)

**Decisión clave:** la auditoría tiene 3 resultados posibles, no 2.

**8.4.1 Aprobada** ✅
- Todo en orden
- La habitación se mantiene en estado "Clean" en Cloudbeds
- El trabajador original recibe el "tick" completo en sus KPIs

**8.4.2 Aprobada con observación** 📝
- El auditor encontró algo menor y lo resolvió en el momento (ej: recepción fue a revisar, encontró basura en el piso, la limpió ella misma)
- La habitación se mantiene en "Clean" en Cloudbeds
- **Flujo especial en la UI:** al tocar "Aprobar con observación", la pantalla despliega el checklist completo del trabajador (con todos los ítems que él había marcado). El auditor **desmarca** el/los ítems específicos que encontró mal ejecutados y deja un comentario opcional
- **Trazabilidad a nivel de ítem:** el registro guarda exactamente qué ítem del checklist fue desmarcado — esto permite reportes tipo "María tiende a fallar en 'limpiar espejos del baño'" para coaching dirigido
- El trabajador en su historial **no ve** esto como rechazo — ve la habitación como aprobada normal
- Pero internamente pierde el "tick de calidad" del ítem específico desmarcado → impacto en KPIs a nivel granular

**8.4.3 Rechazada** ❌
- La habitación necesita re-limpieza por el equipo
- El auditor deja un comentario obligatorio explicando por qué
- La habitación vuelve a estado "Dirty" en Cloudbeds
- La supervisora recibe una alerta con el comentario del rechazo
- La supervisora decide a quién asignar la re-limpieza (puede ser la misma persona u otra)
- En el historial del trabajador original aparece como "Rechazada"

**UI de la pantalla de auditoría:** 3 botones grandes claramente diferenciados — ✅ Aprobar / 📝 Aprobar con observación / ❌ Rechazar.

**Inmutabilidad post-auditoría:** una vez una habitación recibe veredicto de auditoría (`aprobada`, `aprobada_con_observacion` o `rechazada`), **no puede ser re-auditada**. Aparece en las listas de auditoría como solo lectura, visualmente diferenciada (opaca, badge "Auditada"), sin botones de acción. Esto mantiene la trazabilidad histórica para KPIs sin ambigüedades. Aplica tanto a Supervisora como a Recepción.

### 8.5 Módulo de Habitaciones (núcleo)
- Listado por hotel, por estado, por tipo
- Sincronización bidireccional con Cloudbeds
- Historial de limpiezas por habitación

### 8.6 Módulo de Checklists (CRUD)
- CRUD de checklists por tipo de habitación (Doble, Suite, VIP, etc.)
- Editable por roles con permiso `checklists.editar` (por defecto Admin y Supervisora)
- Versión activa + histórico de versiones para trazabilidad

### 8.7 Módulo de Asignación
- Asignación manual seleccionando habitación → trabajador
- Asignación automática equitativa (round-robin entre trabajadores activos del turno)
- Reasignación con un toque
- Reordenamiento de la cola de cada trabajador (la supervisora puede cambiar el orden en que aparecen las habitaciones para un trabajador específico)

### 8.8 Módulo de Tickets de mantenimiento (MVP simplificado, guiño a Fase 2)
- Campo de texto + foto opcional
- Queda en una bandeja que la supervisora ve
- **Sin** estados completos (abierto/en progreso/cerrado) ni asignación — eso es Fase 2
- La interfaz deja visible que "esto es parte de Maintenance Suite — próximamente"

### 8.9 Módulo de Usuarios (gestión por Admin)
- CRUD de usuarios
- Asignación de rol (desde el catálogo de roles, que es dinámico)
- Asignación de turno
- Reseteo de contraseña (genera temporal de 6 caracteres sin ambigüedades)
- Activar / desactivar

### 8.10 Módulo de Ajustes
Contenido variable según el rol y los permisos del usuario logueado:

**Admin** (acceso a todas las secciones):
- Usuarios
- **Roles y permisos** (la matriz RBAC dinámica) ⭐
- Turnos
- Checklists (editor global)
- Cloudbeds (configuración de sincronización, horarios, credenciales — las credenciales se muestran ofuscadas y solo se pueden reemplazar, nunca ver en claro)
- **Alertas predictivas** (umbrales configurables, ver sección 9)
- Logs del sistema
- Preferencias personales (tema, contraseña, historial del copilot propio)

**Supervisora:**
- Checklists (editor)
- Umbrales de alertas predictivas (si tiene el permiso)
- Preferencias personales

**Trabajador y Recepción:**
- Preferencias personales (tema, contraseña, historial del copilot propio)

### 8.11 Módulo del copilot IA (transversal a toda la app)
- FAB (botón flotante) siempre visible en la esquina inferior derecha en todas las pantallas
- Abre panel deslizable desde abajo en móvil, panel lateral en desktop
- Input dual: escribir o mantener presionado el micrófono para hablar
- Transcripción con Web Speech API del navegador
- Procesamiento con Claude API vía **tool use**
- **Respeta estrictamente los permisos dinámicos del rol** del usuario logueado (no chequea rol directo, chequea permisos)
- Confirmación visual antes de ejecutar acciones sensibles ("¿Asignar habitación 412 a Juan Escamosa?")
- Pide clarificación ante ambigüedad (ej: dos Juanes, múltiples habitaciones que terminan en el mismo dígito)
- **Historial persistente por usuario** en SQLite (auditoría + visible en Ajustes)
- Todas las acciones ejecutadas por el copilot quedan en el log general marcadas como "vía asistente IA"

**Niveles de capacidad:**
- **Nivel 1 — Consultas**: solo lectura de información
- **Nivel 2 — Acciones**: ejecutar operaciones (asignar, marcar, aprobar, etc.)
- **Nivel 3 — Acciones complejas**: reconocido pero respondido con "esa acción aún no está disponible desde el asistente, ve a Ajustes"

**Comportamiento ante acción fuera de permisos:** responde amablemente "esa acción está fuera de tus permisos, debes pedírselo a tu supervisora" y registra el intento en el log.

---

## 9. Sistema de alertas predictivas

### 9.1 Concepto

El sistema calcula en tiempo real, para cada trabajador activo, si va a alcanzar a completar su carga asignada antes de que termine su turno. Cuando el sistema predice que **no va a alcanzar**, envía una alerta a la supervisora del turno.

### 9.2 Versión MVP (simple)

**Cálculo:**
- `tiempo_estimado_restante = habitaciones_pendientes × tiempo_promedio_personal`
- Si `tiempo_estimado_restante > tiempo_de_turno_restante + margen_de_seguridad_negativo` → alerta

**Inputs del cálculo:**
1. Habitaciones que le quedan al trabajador (pendientes + en progreso)
2. Tiempo promedio que tarda ESE trabajador en limpiar UNA habitación (calculado del histórico personal — se actualiza con cada limpieza completada)
3. Tiempo restante del turno (hora_fin_turno - hora_actual)

**Período de gracia para trabajadores nuevos:**
- Si un trabajador tiene menos de N limpiezas en su histórico (ej: menos de 5), el sistema usa un **promedio global del equipo** en vez de su promedio personal

**Margen de seguridad:**
- Default: **15 minutos** — si el sistema predice que va a terminar más de 15 min después del fin del turno, dispara la alerta
- Configurable desde Ajustes por Admin y roles con permiso `alertas.configurar_umbrales`

**Canales de notificación (MVP):**
- Badge en la campana del header de la supervisora
- Entrada en una bandeja de alertas dentro de la app
- Banner rojo en la Home de la supervisora cuando entra a la app

**Cuándo se recalcula:**
- Cada vez que un trabajador completa una habitación (recálculo automático)
- Cada 15 minutos en background
- Al cargarse la app de la supervisora

**Mensaje de la alerta (ejemplo):**
> ⚠️ **María Inés podría no alcanzar a terminar**
> Le quedan 3 habitaciones y su turno termina en 1h 20min.
> Tiempo estimado: 1h 47min.
>
> [Reasignar una habitación] [Ver carga del equipo] [Descartar alerta]

**Visibilidad:** la alerta es **exclusivamente para la supervisora**. El trabajador nunca ve el cálculo ni sabe que existe la alerta. Esto es deliberado para no generar ansiedad.

**Tipos de alerta y prioridades (definidos en `docs/home-supervisora.md`):**

- **Prioridad 0 (Crítica máxima):** Sincronización Cloudbeds falló
- **Prioridad 1 (Crítica):** Trabajador en riesgo predictivo, Habitación rechazada por Recepción, Fin de turno con pendientes
- **Prioridad 2 (Importante):** Trabajador disponible sin carga, Ticket de mantenimiento nuevo
- **Prioridad 3 (Menos urgente):** Trabajador disponible (anteriormente "inactivo")

Las prioridades son **editables desde Ajustes** por Admin. En el MVP, todos los tickets entran como Prioridad 2.

### 9.3 Guiños a Fase 2 (visibles en Ajustes como opciones grises deshabilitadas con tooltip "Próximamente")

- **Ponderación por tipo de habitación** (Suite vs Doble vs VIP — cada tipo con su propio tiempo promedio)
- **Notificaciones push** al navegador
- **Alertas predictivas para múltiples turnos del día**
- **Sugerencias automáticas de reasignación** ("María no va a alcanzar, Pedro tiene capacidad — ¿reasignar habitación 305 de María a Pedro?")

---

## 10. Pantallas principales (esqueleto visual)

> Detalle fino por pantalla se irá desarrollando en archivos individuales dentro de `docs/`. Esta sección solo lista qué pantallas existen.

1. **Login** — RUT + contraseña, toggle día/noche, logo Atankalama, link "¿olvidé mi contraseña?"
2. **Forzar cambio de contraseña** — aparece en el primer login tras una contraseña temporal
3. **Home / Dashboard** — cuatro versiones según rol
4. **Listado de habitaciones** — con filtros (área, estado, prioridad)
5. **Detalle de habitación + checklist** — pantalla principal del trabajador de limpieza
6. **Asignación de habitaciones** — vista de la supervisora
7. **Bandeja de auditoría** — para recepción y supervisora, con los 3 botones
8. **Checklist expandible durante auditoría** — cuando se aprueba con observación
9. **Levantar ticket de mantenimiento** — pantalla sencilla con descripción + foto
10. **Gestión de usuarios** — solo roles con permiso
11. **Gestión de roles y permisos** (matriz RBAC) — solo roles con metapermiso
12. **Gestión de turnos**
13. **Configuración de alertas predictivas**
14. **Configuración de Cloudbeds**
15. **Ajustes** — contenido variable por rol
16. **Cambio de contraseña** — accesible desde Ajustes en cualquier rol
17. **Historial del copilot IA** — accesible desde Ajustes
18. **Copilot IA (panel flotante)** — overlay siempre disponible
19. **Logs del sistema** — solo roles con permiso

---

## 11. Integración con Cloudbeds API

### 11.1 Credenciales
- Dos API keys ya configuradas, una por propiedad (Atankalama Inn y Atankalama)
- Permisos confirmados: Gestión de Limpieza (lectura + escritura), Habitación (escritura)
- Almacenadas en variables de entorno, **nunca** en código ni en la base de datos ni en el repo

### 11.2 Patrones de sincronización
- **Lectura:** 2 veces al día (horarios configurables desde Ajustes, alineados con turnos mineros). Cron job o scheduler de EasyPanel.
- **Escritura:** inmediata al marcar una habitación como completada (PUT directo a Cloudbeds desde el backend PHP)
- **Escritura:** inmediata al rechazar una auditoría (vuelve a "Dirty")
- Todas las llamadas a Cloudbeds quedan registradas en el log con timestamp, payload y respuesta

### 11.3 Manejo de errores
- Si Cloudbeds falla al escribir, la acción queda en una cola de reintentos (`cloudbeds_sync_queue`) y se notifica al admin
- La habitación queda marcada en la BD local como "completada localmente, pendiente de sincronizar"
- Reintentos automáticos con backoff exponencial (1s, 2s, 4s)
- Timeout de 10 segundos por request

---

## 12. Base de datos (SQLite — MVP)

Esqueleto de tablas principales. El schema detallado y ejecutable se definirá en `database-schema.sql` durante la fase de detalle.

**Autenticación y usuarios**
- `usuarios` — id, rut, nombre, rol_id (FK), turno_id (FK), password_hash, debe_cambiar_password, activo, timestamps
- `roles` — id, nombre, descripcion, es_sistema (bool, para marcar los 4 por defecto)
- `permisos` — id, codigo (único), descripcion, modulo
- `rol_permisos` — rol_id, permiso_id (tabla pivote)
- `turnos` — id, nombre, hora_inicio, hora_fin

**Estructura física**
- `hoteles` — id, nombre, direccion, cloudbeds_property_id
- `tipos_habitacion` — id, hotel_id, nombre (Doble, Suite, VIP…)
- `habitaciones` — id, hotel_id, numero, tipo_id, estado_local, estado_cloudbeds, last_sync

**Checklists**
- `checklists` — id, tipo_habitacion_id, version, activo
- `checklist_items` — id, checklist_id, orden, descripcion

**Flujo de limpieza**
- `asignaciones` — id, habitacion_id, trabajador_id, supervisora_id, fecha, orden_en_cola, estado
- `ejecuciones_checklist` — id, asignacion_id, timestamp_inicio, timestamp_fin
- `ejecuciones_checklist_items` — id, ejecucion_id, item_id, completado, timestamp

**Auditoría (3 estados)**
- `auditorias` — id, asignacion_id, auditor_id, resultado (`aprobado` | `aprobado_con_observacion` | `rechazado`), comentario, timestamp
- `auditoria_items_observados` — id, auditoria_id, item_id (items que el auditor desmarcó en modo "aprobado con observación")

**Tickets y logs**
- `tickets_mantenimiento` — id, habitacion_id, usuario_id, descripcion, foto_path, timestamp
- `logs_sistema` — id, usuario_id, accion, detalles_json, via_copilot_ia (bool), timestamp

**Copilot IA**
- `copilot_conversaciones` — id, usuario_id, mensaje, rol (user/assistant), timestamp

**Sincronización Cloudbeds**
- `cloudbeds_sync_queue` — id, tipo_operacion, payload, estado, intentos, timestamp

**Alertas predictivas**
- `alertas_predictivas` — id, trabajador_id, supervisora_id_notificada, mensaje, creada_en, atendida_en, estado
- `alertas_config` — key, value (tabla de configuración clave-valor para umbrales configurables)
- `bitacora_alertas` — id, alerta_id (FK), accion, usuario_id (FK), timestamp, datos_json

**Disponibilidad del trabajador**
- `notificaciones_disponibilidad` — id, trabajador_id, supervisora_id_notificada, timestamp, atendida

---

## 13. Seguridad (lineamientos generales para el MVP)

- Contraseñas hasheadas con `password_hash()` de PHP (bcrypt)
- API keys de Cloudbeds y Claude en variables de entorno (`.env`)
- `.env` **nunca** en el repo (está en `.gitignore` desde el commit cero)
- `.env.example` **sí** en el repo, con placeholders
- Escaneo obligatorio con **gitleaks** antes de cada commit
- **Validación de permisos dinámica** en el backend antes de cada acción — chequea si el usuario logueado tiene el permiso específico requerido por la acción, nunca chequea rol directamente
- Validación de permisos del copilot IA en el backend antes de ejecutar cualquier tool
- Logs de todas las acciones sensibles
- HTTPS obligatorio en producción (EasyPanel)
- Sesiones con timeout razonable (a definir) — dispositivos personales, sesiones largas
- Rate limiting básico en endpoints sensibles

---

## 14. Fases de implementación

### Fase 0 — Setup (pre-desarrollo) ✅ COMPLETADA
- Crear repo `atankalama-limpieza` en GitHub (público) ✅
- Configurar Claude Code en VS Code con los MCPs recomendados ✅
- Crear estructura de carpetas inicial del proyecto ✅
- Variables de entorno (API keys Cloudbeds x2, Claude API) ✅ (`.env.example`)
- Instalar y configurar `gitleaks` ✅ (hook pre-commit activo)
- Crear `CLAUDE.md` raíz con convenciones del proyecto ✅
- Crear schema SQLite inicial con todas las tablas (RBAC + alertas) ✅ (`docs/database-schema.sql`, commit `b0542a4`)
- Seeders de permisos (50), roles (4), turnos (2), hoteles (2), admin inicial ✅ (commit `b0542a4`)
- Pendiente solo: configurar despliegue EasyPanel en VPS (no bloquea desarrollo)

### Fase 1 — MVP (este plan)
Todo lo descrito en este documento:
- Autenticación con RUT + RBAC dinámico
- Módulos de habitaciones, checklists, asignación, auditoría (3 estados)
- Persistencia del progreso del checklist a nivel de tap
- Tracking de tiempo oculto
- Tickets de mantenimiento simplificados
- Copilot IA Niveles 1 y 2 con permisos dinámicos
- Alertas predictivas simples
- Pantalla de administración RBAC dinámica
- Integración real con Cloudbeds (lectura 2×/día, escritura inmediata)
- Frontend PHP nativo + Tailwind CDN + Alpine CDN, mobile-first

### Fase 2 — Expansión (post-MVP)
- Módulo completo de Maintenance Suite (tickets con estados, asignación, SLAs)
- Analytics & Reporting completo (eficiencia por trabajador, predicción de carga, KPIs, coaching dirigido basado en observaciones de auditoría)
- Alertas predictivas avanzadas (ponderación por tipo, push, sugerencias de reasignación)
- Copilot IA Nivel 3 (acciones complejas)
- Migración de SQLite a Supabase/Postgres
- Integración formal dentro del "Chat Interno" del hotel (si así lo decide el equipo TI)
- Posible app móvil nativa reusando la API PHP

---

## 15. Próximos pasos inmediatos

### Diseño de documentación — ✅ CERRADA

Todos los docs de diseño ya existen en `docs/`:

1. ~~Home del Trabajador~~ ✅ `docs/home-trabajador.md`
2. ~~Home de la Supervisora~~ ✅ `docs/home-supervisora.md` v2.1
3. ~~Home de Recepción~~ ✅ `docs/home-recepcion.md`
4. ~~Home del Admin~~ ✅ `docs/home-admin.md` + `docs/home-admin-qa-checklist.md`
5. ~~Habitaciones + checklist + asignación~~ ✅ `docs/habitaciones.md`, `docs/checklist.md`
6. ~~Auditoría (3 estados)~~ ✅ `docs/auditoria.md`
7. ~~Copilot IA~~ ✅ `docs/copilot-ia.md`
8. ~~Ajustes + matriz RBAC~~ ✅ `docs/ajustes.md`, `docs/roles-permisos.md`
9. ~~Alertas predictivas~~ ✅ `docs/alertas-predictivas.md`
10. ~~Schema SQLite~~ ✅ `docs/database-schema.sql`
11. ~~Endpoints REST~~ ✅ `docs/api-endpoints.md`
12. ~~Cloudbeds~~ ✅ `docs/cloudbeds.md`
13. ~~Auth, logs, tickets, turnos, usuarios~~ ✅ docs respectivos

### Codificación — EN CURSO

Ver `claude-code-setup.md` §10 para el plan detallado (etapas A–I).

- ✅ **Etapa A — Fundación** (commit `b0542a4`)
- ✅ **Etapa B — Auth y RBAC** (commit `8ebbe68`)
- ✅ **Etapa C — Habitaciones y Cloudbeds** (commit `7906b71`)
- ✅ **Etapa D — Checklists, asignaciones, auditoría** (commit `570aca0`)
- ✅ **Etapa E — Alertas predictivas** (commit pendiente)
- ⏭️ **Etapa F — Tickets, usuarios, turnos** (siguiente)
- ⏳ Etapa G — Copilot IA
- ⏳ Etapa H — Frontend (supervisión)
- ⏳ Etapa I — Pulido final + despliegue VPS

---

## 16. Preparación para desarrollo con Claude Code

Esta sección documenta las decisiones tomadas para que la aplicación sea **codificada por Claude Code** de manera ordenada, segura y eficiente. El detalle técnico de configuración vive en el documento complementario `claude-code-setup.md`.

### 16.1 Modo de trabajo: Autonomía Híbrida (c3)

**Autonomía total** (Claude Code codifica módulos completos sin supervisión paso a paso):
- Schema y migraciones de SQLite (incluyendo RBAC dinámico)
- Seeders de permisos, roles y turnos por defecto
- Modelos / capa de acceso a datos
- Sistema de autenticación y sesiones
- Middleware de permisos (chequea `tienePermiso('codigo')` en cada endpoint)
- CRUD de usuarios, roles, permisos, turnos
- Endpoints REST de habitaciones, asignaciones, checklists
- Lógica de persistencia del checklist (tap por tap)
- Integración con Cloudbeds API (cliente, lectura, escritura, cola de reintentos)
- Sistema de logs y trazabilidad
- Validación de RUT chileno
- Hash de contraseñas, generación de contraseñas temporales
- Algoritmo de alertas predictivas
- Tests unitarios y de integración

**Supervisión por módulo** (Claude Code propone, Nicolás revisa):
- Pantalla de Login y forzar cambio de contraseña
- Pantallas Home (las cuatro versiones por rol)
- Pantalla de detalle de habitación + checklist
- UI del copilot IA (FAB, panel deslizable, voz, confirmaciones)
- Pantallas de Ajustes por rol (especialmente la matriz RBAC)
- Bandeja de auditoría (con el flujo de checklist expandible)
- UI de alertas predictivas

### 16.2 Implicaciones para la documentación

- Cada módulo de `docs/` debe especificar comportamiento ante errores, estados de carga, estados vacíos, validaciones, textos de UI exactos donde apliquen, y permisos requeridos por endpoint
- El `CLAUDE.md` raíz incluirá una sección "Defaults razonables" para casos no especificados, pidiendo que Claude Code marque sus decisiones autónomas con comentarios `// DECISIÓN AUTÓNOMA: ...` para revisión posterior

### 16.3 Seguridad desde el commit cero

- `.gitignore` con `.env`, `database/*.db`, `vendor/`, `node_modules/`, `storage/logs/*` desde el primer commit
- `.env.example` con todas las variables nombradas pero con placeholders
- **`gitleaks` instalado y configurado como hook pre-commit obligatorio**
- Regla en `CLAUDE.md`: "nunca hardcodear credenciales, nunca commitear `.env`"
- Las contraseñas siempre hasheadas con `password_hash()` (bcrypt)

### 16.4 Repositorio

- **Nombre:** `atankalama-limpieza`
- **Visibilidad:** público
- **Owner:** NicoCalama
- **Branching:** `main` (estable) + ramas por feature (`feature/auth`, `feature/rbac`, `feature/copilot-ia`, etc.)
- **Commits:** uno por módulo o sub-módulo terminado, mensaje descriptivo en español

### 16.5 Entorno del desarrollador

- **OS:** Windows
- **Editor:** VS Code
- **Agente:** Claude Code (extensión VS Code) v2.1.92
- **PHP:** 8.2.30 instalado localmente
- **Servidor local:** servidor embebido de PHP (`php -S localhost:8000 -t public/`)
- **Despliegue:** manual al VPS Hostinger / EasyPanel cuando un módulo esté validado

### 16.6 MCPs instalados

Ya configurados en `.mcp.json` del proyecto:
- **GitHub MCP** — commits, branches, PRs
- **Filesystem MCP** — navegación del proyecto
- **SQLite MCP** — consulta directa al schema durante desarrollo
- **Context7 MCP** — documentación oficial actualizada de PHP, Tailwind, Alpine, Claude API, Cloudbeds
- **Playwright MCP** — testing automático en navegador

### 16.7 Skills personalizadas a crear

- **cloudbeds-api** — patrones de uso de la API de Cloudbeds, autenticación, endpoints clave, manejo de errores
- **php-conventions** — convenciones de código PHP 8.2 para este proyecto
- **ui-components** — paleta exacta, espaciados, componentes Tailwind reutilizables de la línea visual del Chat Interno

---

## 17. Consideraciones abiertas (para resolver en fases posteriores)

- Horarios exactos de sincronización con Cloudbeds (alineados con turnos mineros)
- Nombre final de la aplicación (actualmente "Atankalama Aplicación Limpieza")
- Política de retención de logs y conversaciones del copilot
- Estrategia de backup del archivo SQLite
- Eventual migración/integración con el Chat Interno del hotel
- Modelo Claude exacto a usar para el copilot (Sonnet vs Haiku según costo/latencia)
- Manejo de casos especiales de turnos (cambios puntuales, doblar turno, turnos rotativos) — Fase 2
- Tracking de tiempo "activo" descontando pausas largas — Fase 2

---

*Fin del esqueleto general v3.0. Ver `claude-code-setup.md` para el detalle técnico de configuración del entorno de desarrollo.*
