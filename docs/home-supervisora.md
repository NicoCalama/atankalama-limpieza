# Home de la Supervisora — Especificación detallada

**Módulo:** Home / Dashboard
**Rol destinatario:** Supervisora de Limpieza
**Versión:** 2.1
**Fecha:** 13 de abril de 2026
**Estado:** ✅ Auditoría completada, aprobado para codificación
**Modo de codificación de este módulo:** Supervisión por módulo (UI crítica para el MVP)

> Esta es la **especificación ejecutable** que Claude Code debe seguir al codificar la Home de la Supervisora. Léela completa antes de empezar. Cualquier decisión no especificada aquí debe seguir los **defaults razonables** del `CLAUDE.md` raíz, marcando con comentarios `// DECISIÓN AUTÓNOMA: ...`.

---

## 1. Contexto y propósito

### 1.1 Quién usa esta pantalla

Personal de supervisión del hotel. Perfil tipo: una supervisora que llega a las 9 de la mañana, abre la app en su celular, y necesita saber en 10 segundos: **¿hay problemas ahora? ¿quién está en riesgo? ¿qué necesita mi atención?**

### 1.2 Qué responde esta pantalla

Múltiples preguntas simultáneamente:
- **"¿Hay algo urgente que resolver?"** → Sección de Alertas
- **"¿Cómo va el equipo hoy?"** → Sección de Estado del Equipo
- **"¿Necesito auditar algo?"** → Acceso vía bottom tab bar a Auditoría

A diferencia del Trabajador (que tiene una pregunta: "¿qué hago?"), la Supervisora tiene **visibilidad panorámica** con jerarquía clara: lo urgente arriba, lo importante en el medio, lo gestional accesible desde tabs.

### 1.3 Filosofía UX — Información para decidir

**A diferencia del Trabajador**, la Home de la Supervisora **SÍ muestra números, métricas y detalles** porque necesita datos para tomar decisiones en tiempo real. No hay preocupación por "generar ansiedad" — la supervisora está exactamente ahí para manejar presión.

**Esto es un requisito de diseño deliberado.**

La supervisora ve datos del trabajador que el trabajador no ve sobre sí mismo (última actividad, tiempo estimado restante, alertas predictivas). Esto es deliberado y parte del rol de supervisión.

### 1.4 Dispositivo principal

**Mobile-first para MVP** (celular vertical, 375px base). Diseñado para supervisoras que usan celular mientras se mueven por el hotel. Tablet/desktop viene en Fase 2 con sidebar colapsable y mejor aprovechamiento del espacio.

---

## 2. Permisos requeridos

Esta pantalla es visible para usuarios que tengan **al menos uno** de los siguientes permisos granulares:

- `habitaciones.ver_todas` → ve sección "Estado del Equipo"
- `alertas.recibir_predictivas` → ve sección "Alertas"
- `auditoria.ver_bandeja` → puede acceder a tab "Auditoría"
- `asignaciones.asignar_manual` → botones "Reasignar" / "Ver carga" activos
- `tickets.ver_todos` → tickets aparecen en alertas

**Si no tiene ninguno de estos permisos:** redirección a página genérica de "sin acceso".

**Permisos específicos adicionales (secciones se ocultan dinámicamente):**

- `auditoria.editar_checklist_durante_auditoria` → puede desmarcar items del checklist durante auditoría

---

## 3. Layout general

La pantalla se divide en secciones principales, de arriba a abajo, en una sola columna en móvil:

```
┌───────────────────────────────────┐
│  SECCIÓN 1 — Header               │
│  (avatar, nombre, hotel, campana) │
├───────────────────────────────────┤
│                                   │
│  SECCIÓN 2 — Alertas urgentes     │
│  (top 5, con "Ver todas")         │
│                                   │
├───────────────────────────────────┤
│                                   │
│  SECCIÓN 3 — Estado del equipo    │
│  (lista vertical trabajadores)    │
│                                   │
│                                   │
├───────────────────────────────────┤
│                    [FAB Copilot]  │  ← Flotante
│  BOTTOM TAB BAR                   │
│  Inicio | Auditoría | Tickets |   │
│  Ajustes                          │
└───────────────────────────────────┘
```

En desktop/tablet (Fase 2), las secciones se reorganizan con sidebar colapsable, mejor aprovechamiento de espacio.

---

## 4. Sección 1 — Header

### 4.1 Layout

Header fijo en la parte superior, ocupa todo el ancho. Altura aproximada: 72-80px.

```
┌──────────────────────────────────────────┐
│  [A]  Buenos días, María      [H] [🔔]  │
│       Supervisora                        │
│       Atankalama Inn (selector)          │
└──────────────────────────────────────────┘
```

### 4.2 Elementos de izquierda a derecha

**4.2.1 Avatar circular**
- Diámetro: 48px en móvil
- Fondo: color sólido determinístico basado en hash del RUT (igual que Trabajador)
- Contenido: primera letra del nombre en mayúscula, blanco, font-bold
- Tappable: navega a "Mi perfil" / Ajustes

**4.2.2 Bloque de saludo**

Línea 1 — Saludo contextual
- Texto: `{saludo}, {primer_nombre}` (sin línea de rol — la supervisora ya sabe su rol)
- Saludo según hora local: "Buenos días" (00-11), "Buenas tardes" (12-18), "Buenas noches" (19-23)
- Tipografía: `text-lg font-semibold text-gray-900 dark:text-gray-100`

Línea 2 — Rol del usuario
- Texto: "Supervisora" (o "Recepcionista", "Administrador", "Trabajador" según rol)
- Tipografía: `text-sm text-gray-600 dark:text-gray-400`

Línea 3 — Hotel actual (selector)
- Texto: nombre del hotel seleccionado
- Tappable: abre selector de hotel (dropdown o modal)
- Opciones: "Atankalama Inn", "Atankalama (1 Sur)", **"Ambos hoteles"**
- Cuando selecciona "Ambos hoteles": vista única con agrupación visual por hotel
- Si solo hay un hotel: no es tappable, es solo label
- Tipografía: `text-xs text-gray-500 dark:text-gray-500`

**4.2.3 Icono de notificaciones (campana)**
- Icono Lucide: `bell`
- Tamaño: `w-6 h-6`
- Botón circular tappable: `min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800`
- Si hay notificaciones sin leer: dot rojo en esquina superior derecha (`absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full`)
- Tappable: abre **panel deslizable rápido** con últimas notificaciones (no navega a otra pantalla)

### 4.3 Estilo del header

```html
<header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
  <!-- Avatar + saludo (izquierda) -->
  <!-- Selector hotel + campana (derecha) -->
</header>
```

**Sticky:** se queda fijo arriba al hacer scroll.

---

## 5. Sección 2 — Alertas urgentes

### 5.1 Propósito

Mostrar de un vistazo qué necesita **acción inmediata** de la supervisora. Las alertas están priorizadas y ordenadas por antigüedad dentro de cada prioridad.

### 5.2 Jerarquía de alertas

**Prioridad 0 (Crítica máxima):**
- 🔄 Sincronización Cloudbeds falló

**Prioridad 1 (Crítica):**
- ⚠️ Alerta predictiva: "X trabajador NO va a alcanzar su turno"
- ❌ Habitación rechazada por Recepción (necesita reasignación)

**Prioridad 2 (Importante):**
- 📍 Trabajador disponible sin carga
- 🔧 Ticket de mantenimiento (nuevo/sin atender)

**Prioridad 3 (Menos urgente):**
- ⏸️ Trabajador disponible (indicador de que tiene capacidad para recibir carga)

**Nota:** Los niveles de prioridad son **editables desde Ajustes** por Admin y Supervisora. En MVP todos los tickets salen como Prioridad 2.

### 5.3 Cantidad visible

- **Máximo 5 alertas visibles** en la Home
- Si hay más de 5: botón "Ver todas las alertas" → abre pantalla completa con todas
- Dentro de las 5, ordenadas por: **Prioridad (0→3) y antigüedad (más viejas primero)**

### 5.4 Cada alerta es una tarjeta con:

```
┌─────────────────────────────────────┐
│ [ICONO]  TÍTULO DE ALERTA           │
│                                     │
│ Descripción detallada (1-2 líneas)  │
│                                     │
│ [BOTÓN 1]          [BOTÓN 2]        │
└─────────────────────────────────────┘
```

**Elementos:**
- Icono según tipo (⚠️ ❌ 📍 🔧 ⏸️)
- Título claro y accionable
- Descripción: números exactos, contexto
- **2 botones máximo** (acciones para resolver)
- **NO hay botón "descartar"** — las alertas persisten hasta resolverse

### 5.5 Flujos de resolución por tipo de alerta

#### **Alerta: Trabajador en riesgo (Prioridad 1)**

Ejemplo: "⚠️ Juan Pérez no va a alcanzar — 3 habitaciones × 25 min = 75 min de trabajo. Turno termina en 45 min"

Botones:
- **"Ver carga"** → abre panel con habitaciones pendientes del trabajador, ordenadas (última asignada primero). Botones: "No modificar" | "Llamar ayuda" (si hay libres) | "Reasignar seleccionadas"
- **Panel de Reasignación:** muestra trabajadores ordenados de menor a mayor carga. Toca uno → asigna habitación(es) a ese trabajador.

#### **Alerta: Habitación rechazada (Prioridad 1)**

Ejemplo: "❌ Habitación 305 rechazada — Espejo del baño sucio"

Botones:
- **"Reasignar"** → abre panel con trabajadores (menor a mayor carga). Toca uno → asigna.
- **"Resolver ahora"** → marca habitación como en auditoría por la supervisora (ella la resuelve en <30 segundos). Queda registro de que el trabajador no completó correctamente (impacta KPIs).

#### **Alerta: Trabajador disponible (Prioridad 2)**

Ejemplo: "📍 María Inés está sin asignaciones — Disponible hace 12 min"

Propósito: indicador de que hay capacidad disponible para apoyar a trabajadores atrasados.

Botón:
- **"Asignar habitaciones"** → abre panel. Sistema identifica al **trabajador más atrasado**, muestra sus habitaciones pendientes (última asignada primero). Supervisora toca una → se asigna a María. **Si la habitación es asignada a dos trabajadores simultáneamente, la carga se reparte por igual (50% cada uno en tiempo promedio y KPIs).**

#### **Alerta: Sincronización Cloudbeds falló (Prioridad 0)**

Ejemplo: "🔄 No pudimos actualizar Cloudbeds — Última sincronización: 28 min"

Botones:
- **"Reintentar ahora"** → intenta sync inmediata. Si falla: muestra error específico
- **"Ir a Tickets"** → abre panel de tickets (puede crear uno de urgencia)

Si la sincronización no se recupera en 60 min, **escalar a Admin**.

#### **Alerta: Ticket de mantenimiento (Prioridad 2)**

Ejemplo: "🔧 Aire acondicionado Hab 205 — No enciende"

Botones:
- **"Marcar atendido"** → marca como resuelto (sin reasignar a otro equipo)
- **"Escalar a Mantenimiento"** → asigna a equipo de mantenimiento (futuro, Fase 2)

**Registro automático:** toda acción sobre una alerta queda registrada en `bitacora_alertas` con: `alerta_id`, `accion`, `usuario_id`, `timestamp`, `datos_json` (detalles de la acción).

---

## 6. Sección 3 — Estado del equipo

### 6.1 Propósito

Mostrar en un vistazo cómo va el equipo hoy: cuántas habitaciones completadas, en progreso, pendientes. Y quién necesita atención.

### 6.2 Resumen visual (barra de progreso)

```
Habitaciones completadas:  15/45   ████████░░░░░░░░░░░░░░░  33%
(Aprobadas + Aprobadas con observación)
```

**Colores:**
- **Verde:** `aprobada` + `aprobada_con_observacion` (ambas cuentan como completadas)
- **Gris/neutro:** `pendiente` + `en_progreso`
- **Rojo:** `rechazada`

### 6.3 Lista de trabajadores

Debajo de la barra, **lista vertical de trabajadores** con estado individual.

**Orden de presentación:**

**Primer nivel (filtrado principal):** agrupa por estado
1. **`en_riesgo`** (van a no completar a tiempo)
2. **`en_tiempo`** (van bien)
3. **`disponible`** (sin asignaciones, capacidad para recibir carga)

**Segundo nivel (subfiltro dentro de cada grupo):** ordenar por **grado de atraso** (más atrasado arriba)
- En `en_riesgo`: el que falta más tiempo para terminar turno → arriba
- En `en_tiempo`: el que tiene menos margen → arriba
- En `disponible`: orden alfabético (todos sin carga)

**Propósito:** supervisora identifica al instante a quién enfocarse sin esperar alertas automáticas.

### 6.4 Cada trabajador es una tarjeta con:

```
┌─────────────────────────────────────────┐
│ [AVATAR]  María García      [BADGE]    │
│                                         │
│ 2/5 habitaciones | 45 min restantes    │
│                                         │
│ [BARRA PROGRESO]  ████████░░░░░░░░░░   │
│                                         │
│ [VER CARGA]  [REASIGNAR]               │
└─────────────────────────────────────────┘
```

**Elementos:**

**4.4.1 Avatar + nombre**
- Avatar circular (48px), fondo por hash del RUT
- Nombre completo
- Tipografía: `text-sm font-semibold`

**4.4.2 Badge de estado**
- Texto: estado actual
- Colores:
  - **Rojo intenso** (`bg-red-500`): `en_riesgo` — "En riesgo"
  - **Naranja** (`bg-amber-500`): `en_tiempo` (pero con poco margen) — "En tiempo"
  - **Verde** (`bg-green-500`): `en_tiempo` (con margen) — "En tiempo"
  - **Azul claro** (`bg-blue-400`): `disponible` — "Disponible"
- Tamaño: `px-2 py-1 rounded text-xs font-semibold`

**4.4.3 Resumen de carga**
- Texto: `{completadas}/{total} habitaciones | {tiempo_restante} min restantes`
- Tipografía: `text-xs text-gray-600 dark:text-gray-400`

**4.4.4 Barra de progreso individual**
- Verde: habitaciones completadas (aprobadas + aprobadas_con_observacion)
- Gris: en progreso + pendientes
- Rojo: rechazadas
- Altura: 6px
- Redondeada

**4.4.5 Botones de acción**
- **"Ver carga"** (gris) — abre panel con sus habitaciones pendientes
- **"Reasignar"** (si tiene permiso `asignaciones.asignar_manual`) — abre selector para mover habitaciones a otro trabajador

Botones visibles solo si: tiene permiso `asignaciones.asignar_manual`.

### 6.5 Filtrado por hotel

**Selector en header:** "Atankalama Inn" / "Atankalama (1 Sur)" / **"Ambos hoteles"**

**"Ambos hoteles":** vista única con agrupación visual por hotel
- Separador visual (línea o título) entre trabajadores de cada hotel
- Mismo doble filtrado (en_riesgo → en_tiempo → disponible) dentro de cada hotel

---

## 7. Sección 4 — FAB Copilot

### 7.1 Elemento flotante

Botón flotante en esquina inferior derecha (visible siempre, incluso al scroll).

```
         [✨] ← Icono Lucide "sparkles"
```

- Diámetro: 56px
- Fondo: azul principal (`bg-blue-600`)
- Icono: Lucide **`sparkles`** (blanco, `w-6 h-6`). Estandarizado en las 4 Homes para transmitir "asistente IA".
- Sombra: `shadow-lg`
- Tappable: abre panel del Copilot IA

### 7.2 Panel del Copilot

Ver `docs/copilot-ia.md` para especificación completa.

Permisos requeridos:
- `copilot.usar_nivel_1_consultas` — consultas de datos
- `copilot.usar_nivel_2_acciones` — acciones (asignaciones, auditoría)

---

## 8. Bottom Tab Bar (MVP)

### 8.1 Layout

Barra fija en la parte inferior, ocupa todo el ancho. Altura: 60-64px (considerando safe area en notch).

```
┌──────────────────────────────────────────┐
│  Inicio  │ Auditoría │ Tickets │ Ajustes  │
└──────────────────────────────────────────┘
```

### 8.2 Tabs principales

**Tab 1 — Inicio**
- Icono: `home`
- Destino: esta pantalla (Home de Supervisora)
- Active: fondo azul claro, icono azul

**Tab 2 — Auditoría**
- Icono: `check-circle-2` o `clipboard-check`
- Destino: módulo de auditoría (nueva pantalla)
- Flujo: selector de hotel → lista de habitaciones completadas → seleccionar una → pantalla de auditoría con checklist

**Tab 3 — Tickets**
- Icono: `wrench` o `ticket`
- Destino: lista de tickets (nueva pantalla o panel expandible)
- Contiene: tickets de mantenimiento asignados, sin atender

**Tab 4 — Ajustes**
- Icono: `settings` o `sliders`
- Destino: módulo de Ajustes / Configuración
- Contiene: perfil, preferencias, módulos adicionales (KPIs, Reportes, etc.)

### 8.3 Estilo

```html
<nav class="fixed bottom-0 left-0 right-0 z-30 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 flex justify-around items-stretch h-16 safe-area-inset-bottom">
  <!-- Cada tab es un botón con icono + label pequeño -->
</nav>
```

**Características:**
- Sticky en la parte inferior
- Respeta safe-area-inset-bottom (notch en iOS)
- Tab activo: icono + label en azul (`text-blue-600`)
- Tab inactivo: icono + label en gris (`text-gray-500`)
- Label: `text-xs font-medium`
- Transición suave entre tabs (sin flash)

### 8.4 Acceso a módulos adicionales

Dentro de **Ajustes**, opción "**Más módulos**" o "**Acceso rápido**":
- KPIs y reportes
- Configuración de alertas
- Gestión de roles (solo Admin)
- Integración Cloudbeds
- etc.

**Nota para Fase 2:** estos módulos se reorganizarán en sidebar colapsable cuando pasemos a tablet/desktop.

---

## 9. Módulo de Auditoría

### 9.1 Acceso

**Desde:** Bottom tab bar → "Auditoría"

### 9.2 Pantalla 1 — Selector de hotel

Selector dropdown o modal:
- "Atankalama Inn"
- "Atankalama (1 Sur)"
- "Ambos hoteles"

Botón: "Continuar" → Pantalla 2

### 9.3 Pantalla 2 — Lista de habitaciones completadas

Muestra todas las habitaciones del día. Las que ya tienen estado de auditoría (`aprobada`, `aprobada_con_observacion`, `rechazada`) aparecen como **solo lectura** (visualmente diferenciadas, más opacas, sin botones de acción, con badge "Auditada"). Solo las que están en estado `completada` (sin auditar todavía) son interactivas y muestran los 3 botones de auditoría. Esto mantiene la trazabilidad histórica para KPIs sin permitir re-auditoría.

**Listado:**
- Número de habitación
- Nombre del trabajador que la completó
- Estado: icono + color
  - ✅ Verde: `aprobada`
  - ⚠️ Naranja: `aprobada_con_observacion`
  - ❌ Rojo: `rechazada`
- Timestamp de finalización

Tappable: entra a Pantalla 3 (auditoría detallada).

### 9.4 Pantalla 3 — Auditoría detallada

**Header:**
- Número de habitación
- Nombre del trabajador
- Timestamp de finalización

**Contenido principal — Checklist original:**

Muestra el **mismo checklist que el trabajador completó**, con sus marcas originales.

Cada item del checklist:
- Checkbox (marcado ✓ si el trabajador lo completó)
- Descripción (ej: "Aspirar piso", "Limpiar espejo", etc.)
- Foto (si existe) — tappable para ampliar

### 9.5 Acciones de auditoría

**Tres opciones:**

**Opción 1 — ✅ Aprobada**
- Botón: "Aprobar"
- Acción: marca habitación como `aprobada`
- Bitácora: registra aprobación, usuario, timestamp
- Resultado: habitación sale del flujo de auditoría

**Opción 2 — ⚠️ Aprobada con observación**
- Botón: "Aprobar con observación"
- Panel expandible: **mismo checklist con checkboxes desmarcables**
  - Supervisora **desmarca items que encontró problemáticos** (ej: espejo sucio, basura en esquina)
  - Items desmarcados = observaciones
  - Items que quedan marcados = aprobados

- Acción: marca habitación como `aprobada_con_observacion` + guarda lista de items con observación
- Bitácora: registra observación, items afectados, usuario, timestamp
- **Flujo de resolución:** supervisora puede resolver el problema en <30 segundos ahí mismo (limpiar espejo, etc.)
- Resultado: habitación pasa a completada (verde en barra), pero con anotación en KPIs (impacta evaluación futura del trabajador)

**Opción 3 — ❌ Rechazada**
- Botón: "Rechazar"
- Modal: selector de razón de rechazo
  - "Alcoba sucia"
  - "Baño sucio"
  - "No hay toallas"
  - "Otra" (con campo de texto)
- Acción: marca habitación como `rechazada` + guarda razón
- Bitácora: registra rechazo, razón, usuario, timestamp
- **Alerta generada:** aparece en Home como "Habitación rechazada" (Prioridad 1)
- Resultado: habitación vuelve a cola de asignación, puede ser reasignada a otro trabajador o resuelta por supervisora

### 9.6 Permisos

**Requeridos:**
- `auditoria.ver_bandeja` — ver lista de habitaciones
- `auditoria.editar_checklist_durante_auditoria` — desmarcar items del checklist (Opción 2)

---

## 10. Comportamientos generales

### 10.1 Refresco automático

- Home se refresca cada **60 segundos** automáticamente (sin intervención del usuario)
- Actualiza: alertas, estado del equipo, números de completadas/pendientes
- **Sin mostrar banner de "actualizando"** — cambios se integran suavemente

### 10.2 Refresco inmediato tras acción

Cuando la supervisora ejecuta una acción (Reasignar, Aprobar, Rechazar, etc.):
- Refresco inmediato de la Home (no espera 60 seg)
- Los números y alertas se actualizan al instante
- Panel de modal/panel se cierra automáticamente

### 10.3 Persistencia de datos

Todas las acciones se persisten en la BD inmediatamente:
- Auditorías
- Reasignaciones
- Resolución de alertas
- Anotaciones

**No hay "guardar manual".**

### 10.4 Pull-to-refresh (móvil)

Si el navegador soporta pull-to-refresh nativo, dejarlo funcionar. NO implementar custom para MVP.

---

## 11. Estados de carga y error

### 11.1 Estado de carga inicial

Mientras se cargan los datos, mostrar spinner centrado:

```html
<div class="min-h-screen flex items-center justify-center">
  <div class="flex flex-col items-center gap-3">
    <svg class="animate-spin h-8 w-8 text-blue-600" ...></svg>
    <p class="text-gray-600 dark:text-gray-400">Cargando Home...</p>
  </div>
</div>
```

### 11.2 Error al cargar

Si algún endpoint falla:

```html
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="text-center max-w-xs">
    <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Error al cargar</h2>
    <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión e intenta de nuevo.</p>
    <button onclick="location.reload()" class="min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
      Reintentar
    </button>
  </div>
</div>
```

### 11.3 Sin internet (offline)

Banner persistente:

```html
<div class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
  Sin conexión a internet. Los datos se actualizarán cuando vuelva.
</div>
```

---

## 12. Comportamientos críticos a no olvidar

Esta lista es un checklist final para que Claude Code valide al terminar la pantalla:

- [ ] **Las alertas urgentes están ARRIBA DE TODO**
- [ ] **Se muestran máximo 5 alertas** + botón "Ver todas"
- [ ] **El estado del equipo muestra números reales** (completadas / en progreso / pendientes)
- [ ] **La supervisora ve solo datos del hotel seleccionado** (o ambos si selecciona esa opción)
- [ ] **Doble filtrado de trabajadores:** grupos por estado (en_riesgo → en_tiempo → disponible), subfiltro por atraso dentro de cada grupo
- [ ] **Opción "Ambos hoteles"** muestra datos de ambos con agrupación visual por hotel
- [ ] **Las acciones rápidas en cada alerta funcionan** (Reasignar, Ver carga, Resolver, etc.)
- [ ] **La Home se refresca cada 60 segundos automáticamente**
- [ ] **Al ejecutar una acción, refresco inmediato** (sin esperar 60 seg)
- [ ] **Las secciones se ocultan dinámicamente según permisos**
- [ ] **El FAB Copilot está arriba del contenido**, siempre visible
- [ ] **Bottom tab bar (4 tabs: Inicio / Auditoría / Tickets / Ajustes)** en lugar de sidebar (Fase 2)
- [ ] **Modo día/noche funciona** sin flash
- [ ] **Áreas tappables mínimo 44x44px**
- [ ] **Contraste WCAG AA en todo**
- [ ] **Scroll vertical limpio** (no horizontal)
- [ ] **Bitácora de alertas se registra correctamente** (timestamp creación, resolución, usuario, acción)
- [ ] **Badge de estado cambia color según riesgo** (rojo/naranja/verde/azul)
- [ ] **Trabajadores disponibles tienen badge distintivo** (azul - "Disponible")
- [ ] **Panel de "Ver carga" muestra habitaciones** ordenadas correctamente (última asignada primero)
- [ ] **Botón "Asignar" muestra trabajadores** ordenados de menor a mayor carga
- [ ] **Header sticky** no desaparece al scroll
- [ ] **FAB nunca desaparece**, incluso al scroll
- [ ] **Auditoría desde tab "Auditoría"** (no botón en home)
- [ ] **Módulo de auditoría: selector hotel → lista completadas → detalle → checklist con desmarcables → acciones (Aprobar / Aprobar con observación / Rechazar)**
- [ ] **`aprobada_con_observacion` cuenta como verde (completada)** en barra de progreso**
- [ ] **Dos trabajadores en misma habitación:** carga se reparte 50% cada uno en KPIs
- [ ] **Header muestra:** `{saludo}, {primer_nombre}` (línea 1) + hotel selector (línea 2)

---

## 13. Vinculación con otros módulos

Esta pantalla **depende** de los siguientes módulos/documentos:

- `docs/auth.md` — autenticación y usuario logueado
- `docs/asignacion.md` — flujo de reasignación de habitaciones
- `docs/alertas-predictivas.md` — cálculo de alertas en tiempo real
- `docs/tickets.md` — tickets de mantenimiento (MVP simple)
- `docs/cloudbeds.md` — integración y sincronización
- `docs/copilot-ia.md` — panel del copilot IA
- `docs/rbac-dinamico.md` — permisos dinámicos por sección
- `docs/auditoria.md` — especificación completa del módulo de auditoría (nueva)

Esta pantalla **es referenciada por:**

- `docs/home-recepcion.md` — flujo compartido de auditoría
- `docs/home-admin.md` — gestión de roles/permisos que afectan esta Home
- `docs/reportes-kpis.md` — datos para reportes mensuales

---

## 14. Notas finales para Claude Code

### 14.1 Modo de codificación

Este módulo es de **supervisión por módulo**: 
- Proponer los archivos que vas a crear/modificar
- Esperar aprobación de Nicolás
- NO commitar nada hasta que lo diga explícitamente

### 14.2 Archivos sugeridos a crear

```
src/Controllers/HomeController.php
  → método supervisora()

src/Services/Home/SupervisoraHomeService.php
  → lógica de negocio

src/Services/Alertas/AlertasService.php
  → cálculo y obtención de alertas

src/Services/Auditoria/AuditoriaService.php
  → lógica de auditoría, checklist, aprobación

src/Views/home/supervisora.php
  → vista principal con HTML + Tailwind + Alpine

src/Views/layouts/app-supervisora.php
  → layout con header sticky, bottom tab bar, FAB

src/Views/auditoria/
  - index.php (selector hotel)
  - habitaciones.php (lista completadas)
  - detalle.php (pantalla de auditoría con checklist)

src/Views/partials/
  - alerta-card.php
  - trabajador-card.php
  - badge-estado.php
  - avatar-usuario.php
  - bottom-tab-bar.php

public/js/home-supervisora.js
  → lógica de refresco, eventos, modales

public/js/auditoria.js
  → lógica de auditoría, checklist desmarcables

public/css/home-supervisora.css (si necesario)
  → estilos adicionales (poco probable con Tailwind)
```

### 14.3 Tests sugeridos

- Test del cálculo de top 5 alertas (orden por prioridad + antigüedad)
- Test del filtrado por hotel seleccionado (incluyendo "Ambos hoteles")
- Test del doble filtrado de trabajadores (en_riesgo → en_tiempo → disponible, subfiltro por atraso)
- Test del refresco automático (60 seg)
- Test de permisos dinámicos (secciones se ocultan si no tiene permisos)
- Test de refresco inmediato al ejecutar acciones
- Test del cálculo del estado del trabajador (en riesgo, en tiempo, disponible)
- Test de la bitácora de alertas (registro correcto de timestamp, usuario, acción)
- Test del módulo de auditoría: selector → lista → detalle → checklist desmarcable
- Test que `aprobada_con_observacion` aparece verde en barra de progreso
- Test que dos trabajadores en misma habitación reparten carga 50-50

### 14.4 Si encuentras algo no especificado

Sigue los **defaults razonables** del `CLAUDE.md` raíz y deja un comentario `// DECISIÓN AUTÓNOMA: ...`.

Casos típicos donde puede pasar:
- Color exacto del avatar según hash del RUT
- Animación del cambio de estado de un badge
- Exacto behavior de transiciones en tabs
- Easing de las animaciones (si es que las hay)

---

## 15. Anotaciones pendientes para futuro

Estos temas NO entran en el MVP pero deben resolverse en sesiones separadas:

**📌 Sidebar colapsable (Fase 2)**
- Cuando pasemos a tablet/desktop (>768px)
- Mejor aprovechamiento de espacio
- Módulos adicionales (KPIs, Reportes, etc.) accesibles desde sidebar
- Bottom tab bar se reemplaza por sidebar en desktop

**📌 Análisis profundo de KPIs y reportes**
- Definición exacta de KPIs por rol
- Cómo se generan reportes mensuales
- Dashboards de productividad
- Impacto de `aprobada_con_observacion` en evaluación del trabajador
- (Ver `docs/kpis-definicion.md` y `docs/kpis-reportes.md` cuando se creen)

**📌 Diferencias de auditoría entre Supervisora y Recepción**
- Las auditorías impactan en metas salariales/bonos
- ¿Permisos distintos? ¿Datos distintos en pantalla?
- ¿Reportes de desempeño por rol?
- (A resolver cuando diseñemos `docs/auditoria.md` completo)

**📌 Motivos de rechazo y perfil de Recepción**
- Los motivos de alerta deben coincidir con los que ve Recepción
- Consistencia de datos crítica
- (A revisar cuando diseñemos `docs/auditoria.md` completo)

**📌 Trabajador disponible: NO solicita automáticamente**
- El sistema NO reasigna automáticamente
- El trabajador PUEDE solicitar más trabajo (botón "Solicitar")
- La supervisora decide si asignarle o mantenerlo en standby
- (Especificación en `docs/trabajador-disponibilidad.md` futuro)

**📌 Sincronización Cloudbeds: arquitectura offline-first**
- Backoff exponencial + retry automático
- Cola local en SQLite para acciones offline
- Commit al volver la conexión
- (Especificación en `docs/cloudbeds-sincronizacion.md` futuro)

**📌 Botones críticos: No modificar / Llamar ayuda / Reasignar**
- Estos 3 botones son core del sistema
- Definen cómo se resuelven atrasos
- Impactan KPIs y desempeño
- (Sesión separada cuando diseñemos pantalla de "Carga del trabajador")

**📌 Integración con módulo de Mantenimiento (Fase 2)**
- Tickets ahora van a alertas + info a supervisora
- Futuro: módulo separado, equipo de mantenimiento los ve
- Supervisora solo notificada, no aprueba
- (A diseñar cuando toque módulo de mantenimiento)

**📌 Tabla `bitacora_alertas` en schema**
- Definir cuando se cree `database-schema.sql`
- Campos: `id`, `alerta_id`, `accion`, `usuario_id`, `timestamp`, `datos_json`
- Indices: por `alerta_id`, `usuario_id`, `timestamp`

---

## 16. Resumen de cambios respecto a v1.0

**Auditoría completada el 13 de abril de 2026. Cambios consolidados:**

1. ✅ Permiso dinámico: eliminado `home.supervisora.acceder`, reemplazado por chequeo de permisos granulares
2. ✅ Auditoría: movida a tab del bottom bar, nuevo flujo de selector hotel → lista → detalle → checklist desmarcable
3. ✅ Sin botón "Lo haré yo": confusión aclarada, se usa "Resolver ahora" en auditoría con observación
4. ✅ Alerta "Trabajador inactivo" → "Trabajador disponible": indicador de disponibilidad, no vigilancia
5. ✅ Bottom tab bar (MVP): Inicio / Auditoría / Tickets / Ajustes (sidebar colapsable → Fase 2)
6. ✅ Header: nombre del usuario + rol + selector de hotel
7. ✅ Doble filtrado de trabajadores: grupos por estado + subfiltro por atraso
8. ✅ Opción "Ambos hoteles" con agrupación visual
9. ✅ Bottom tab bar resuelto automáticamente
10. ✅ Tabla `bitacora_alertas` documentada para schema
11. ✅ Anotaciones pendientes (sidebar, KPIs, mantenimiento) trasladadas a sección 15
12. ✅ `aprobada_con_observacion` = verde en barra de progreso, impacta KPIs

---

## 17. Cambios v2.1 (13 de abril de 2026)

Auditoría rápida post-v2.0 con Opus, 3 ajustes finales:

1. ✅ **Header simplificado a 2 líneas** — eliminada la línea de rol (redundante). Ahora solo: `{saludo}, {primer_nombre}` (línea 1) + selector de hotel (línea 2)
2. ✅ **Habitaciones ya auditadas son solo lectura** — aparecen en la lista de auditoría con badge "Auditada" pero sin botones de acción. Mantiene trazabilidad histórica para KPIs sin permitir re-auditoría. Aplica tanto a Supervisora como a Recepción
3. ✅ **`alertas.configurar_umbrales` solo para Admin** — la Supervisora NO tiene este permiso por defecto. Si la empresa quiere dárselo en el futuro, se hace desde la matriz RBAC sin tocar código

---

*Fin de la especificación de Home de la Supervisora v2.1. Aprobado definitivamente para codificación con Claude Code.*
