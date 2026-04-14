# Home del Administrador — Especificación detallada

**Módulo:** Home / Dashboard
**Rol destinatario:** Administrador (Nicolás + Jefe IT)
**Versión:** 1.0
**Fecha:** 14 de abril de 2026
**Estado:** ✅ Aprobado para codificación
**Modo de codificación de este módulo:** Supervisión por módulo (UI crítica para el MVP)

> Esta es la **especificación ejecutable** que Claude Code debe seguir al codificar la Home del Administrador. Léela completa antes de empezar. Cualquier decisión no especificada aquí debe seguir los **defaults razonables** del `CLAUDE.md` raíz, marcando con comentarios `// DECISIÓN AUTÓNOMA: ...`.

---

## 0. Decisiones marco

Resolución explícita de las decisiones P0 del guión original:

- **P0.1 — Filosofía:** **Dashboard ejecutivo con toques de centro de control**. Vista macro de la operación + alertas técnicas P0-P1 + salud del sistema. No es Home minimalista (el Admin necesita ver datos) ni centro de control puro (la configuración vive en Ajustes).
- **P0.2 — Estructura:** **Home propia y distinta** de Trabajador/Supervisora/Recepción. Comparte sistema de alertas y selector de hotel con Supervisora, pero su contenido es único (KPIs + indicadores técnicos).
- **P0.3 — Selector de hotel:** Incluye opción **"Ambos hoteles" (default)** además de ATAN Inn y ATAN. Consolida métricas cuando está en "Ambos" con agrupación visual por hotel.

---

## 1. Contexto y propósito

### 1.1 Quién usa esta pantalla

Administradores del sistema hotelero. Perfil tipo: Nicolás (gerente IT) y su jefe, que son la última capa de control del sistema. NO son operativos — no limpian ni auditan. Usan la app para verificar que la operación va bien a nivel macro y que el sistema funciona correctamente.

Acceden más desde **desktop/tablet** que desde móvil, pero mobile-first sigue siendo la regla.

### 1.2 Qué responde esta pantalla

Múltiples preguntas simultáneamente (jerarquía clara):
- **"¿Hay algún problema del sistema que deba atender?"** → Tab "Inicio" (alertas técnicas)
- **"¿Cómo va la operación general hoy?"** → Tab "Operativas" (métricas + KPIs)
- **"¿Cuál es la salud técnica del sistema?"** → Tab "Técnicas" (5 indicadores)
- **"¿Qué necesito configurar?"** → Tab "Ajustes" (configuración)

A diferencia del Trabajador (una pregunta) y la Supervisora (operativa + alertas operativas), el Admin tiene **visibilidad panorámica técnica + operativa** con jerarquía clara: lo urgente arriba, lo estratégico en el medio, lo técnico disponible, configuración siempre accesible.

### 1.3 Filosofía UX — Control total

**Decisión de diseño crítica:** el Admin es IT. Necesita **máximo control y visibilidad** de todo lo que pasa en el sistema. La Home no oculta información — expone datos completos, cálculos exactos, alertas críticas, estado técnico.

No hay simplificación por "no generar ansiedad" — el Admin está exactamente ahí para manejar presión técnica.

**Esto es un requisito de diseño deliberado.**

### 1.4 Dispositivo principal

**Mobile-first para MVP** (celular vertical, 375px base). Aunque el Admin probablemente usa más desktop, el acceso desde móvil mientras se mueve debe funcionar perfectamente. Desktop tiene layout optimizado de 2 columnas (Operativas | Técnicas).

---

## 2. Permisos requeridos

Esta pantalla es visible para usuarios que tengan **al menos uno** de los siguientes permisos:

- `alertas.recibir_predictivas` → ve tab "Inicio" (alertas técnicas P0-P1 **y** alertas operativas P0-P1 relevantes: trabajador en riesgo, habitación rechazada, fin de turno con pendientes). Mismo permiso que usa la Supervisora — unificado en todo el sistema.
- `kpis.ver_operativas` → ve tab "Operativas" (contadores + KPIs)
- `sistema.ver_salud` → ve tab "Técnicas" (estado Cloudbeds, errores, BD, usuarios, versión)
- `ajustes.acceder` → ve tab "Ajustes"

**Alertas técnicas vs operativas:** el tab "Inicio" del Admin mezcla ambos tipos ordenados por prioridad. Un error de sync Cloudbeds (técnica P0) y una habitación rechazada (operativa P1) aparecen en la misma bandeja. Esto es deliberado: el Admin necesita visión panorámica sin tener que saltar entre pestañas.

**Si no tiene ninguno:** redirección a página genérica de "sin acceso".

**Permisos dinámicos:** Cada tab se oculta graciosamente si el usuario no tiene su permiso. Si un "sub-admin" no tiene `sistema.ver_salud`, el tab "Técnicas" desaparece del tab bar.

**El Admin por defecto tiene TODOS los permisos.**

---

## 3. Layout general

La pantalla se divide en secciones principales, de arriba a abajo, en una sola columna en móvil:

```
┌──────────────────────────────────────────┐
│  SECCIÓN 1 — Header                      │
│  (avatar, saludo, rol, hotel, estado,   │
│   indicador sistema, campana)            │
├──────────────────────────────────────────┤
│                                          │
│  SECCIÓN 2 — Contenido dinámico (por tab)│
│                                          │
│  Tab "Inicio": Alertas técnicas          │
│  Tab "Operativas": Contadores + KPIs     │
│  Tab "Técnicas": 5 indicadores salud     │
│  Tab "Ajustes": Configuración            │
│                                          │
│                     [FAB Copilot]        │  ← Flotante
│  BOTTOM TAB BAR                          │
│  [Inicio] [Operativas] [Técnicas]        │
│  [Ajustes]                               │
└──────────────────────────────────────────┘
```

En desktop/tablet (≥md: 768px), el layout se reorganiza:
- Header igual
- Alertas técnicas en fila superior (ancho completo)
- Grid de 2 columnas: Operativas (izq) | Técnicas (der)
- FAB igual
- Bottom tab bar igual

---

## 4. Sección 1 — Header

### 4.1 Layout

Header fijo en la parte superior, ocupa todo el ancho. Altura aproximada: 72-80px.

```
┌──────────────────────────────────────────────┐
│  [N]  Buenos días, Nicolás   [🟢] [🔔]     │
│       Administrador                          │
│       Ambos hoteles (selector)               │
└──────────────────────────────────────────────┘
```

### 4.2 Elementos de izquierda a derecha

**4.2.1 Avatar circular**
- Diámetro: 48px en móvil, 56px en desktop
- Fondo: color sólido determinístico basado en hash del RUT del usuario
- Contenido: **iniciales del nombre completo** (primera letra del primer nombre + primera letra del primer apellido), mayúsculas, blanco, font-bold. Ejemplo: Nicolás Campos → `NC`.
- Tappable: navega a "Mi perfil" / Ajustes

> Consistencia: el saludo de la línea 1 usa solo el primer nombre ("Buenos días, Nicolás"); el avatar muestra las iniciales del nombre completo. No confundir ambos elementos.

**4.2.2 Bloque de saludo**

Línea 1 — Saludo contextual + nombre
- Texto: `{saludo}, {primer_nombre}`
- Saludo según hora local: "Buenos días" (00-11), "Buenas tardes" (12-18), "Buenas noches" (19-23)
- Tipografía: `text-lg font-semibold text-gray-900 dark:text-gray-100`

Línea 2 — Rol del usuario
- Texto: "Administrador"
- Tipografía: `text-sm text-gray-600 dark:text-gray-400`

Línea 3 — Hotel selector
- Texto: nombre del hotel seleccionado
- Tappable: abre selector de hotel (dropdown o modal)
- Opciones: "Atankalama Inn", "Atankalama (1 Sur)", **"Ambos hoteles"** (default)
- Cuando selecciona "Ambos": vista única con agrupación visual por hotel
- Tipografía: `text-xs text-gray-500 dark:text-gray-500`

**4.2.3 Indicador de estado del sistema (derecha)**
- Pequeño dot circular (`w-3 h-3`)
- Colores:
  - 🟢 `bg-green-500` — Todo OK (Cloudbeds sincronizado, sin errores críticos)
  - 🟡 `bg-yellow-500` — Advertencia (sync retrasada >30 min, errores menores)
  - 🔴 `bg-red-500` — Problema (sync fallida, errores críticos)
- NO es tappable, solo indicador visual
- Posicionado en esquina superior del header

**4.2.4 Icono de notificaciones (campana)**
- Icono Lucide: `bell`
- Tamaño: `w-6 h-6`
- Botón circular tappable: `min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800`
- Si hay notificaciones sin leer: dot rojo en esquina superior derecha (`absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full`)
- Tappable: abre **panel deslizable rápido** con últimas notificaciones o navega a pantalla de notificaciones completa

### 4.3 Estilo del header

```html
<header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
  <!-- Avatar + saludo (izquierda) -->
  <!-- Indicador + campana (derecha) -->
</header>
```

**Sticky:** el header se queda fijo arriba al hacer scroll.

---

## 5. Sección 2 — Tab "Inicio" (Alertas técnicas)

### 5.1 Propósito

Mostrar de un vistazo qué necesita **acción inmediata técnica**. Solo alertas críticas P0-P1 que afectan la operación del sistema.

### 5.2 Jerarquía de alertas técnicas

**Prioridad 0 (Crítica máxima):**
- 🔄 Sincronización Cloudbeds falló
- 🔴 Base de datos casi llena / llena

**Prioridad 1 (Crítica):**
- ⚠️ Errores críticos en logs (contador > umbral)
- ⚠️ Token de API expirado / a punto de expirar
- ⚠️ Problemas de conectividad / uptime

### 5.3 Cantidad visible

- **Máximo 5 alertas visibles** en la Home (tab Inicio)
- Si hay más de 5: botón "Ver todas las alertas" → abre pantalla dedicada con todas
- Dentro de las 5, ordenadas por: **Prioridad (0→1) y antigüedad (más viejas primero)**

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
- Icono según tipo (🔄 🔴 ⚠️)
- Título claro y accionable
- Descripción: números exactos, contexto, timestamp
- **2 botones máximo** (acciones directas para resolver)
- **NO hay botón "descartar"** — las alertas persisten hasta resolverse

### 5.5 Flujos de resolución por tipo de alerta

| Tipo | Prioridad | Ejemplo de descripción | Botón 1 | Botón 2 |
|---|---|---|---|---|
| `cloudbeds_sync_failed` | P0 | "🔄 No pudimos actualizar Cloudbeds — Última sync: 45 min" | **Reintentar ahora** (sync inmediata; si falla muestra error) | **Ver error detallado** (logs + respuesta API) |
| `base_datos_llena` | P0 | "🔴 Base de datos al 95% — 480 MB de 512 MB" | **Ver tamaño** (breakdown por tabla) | **Revisar logs** (tab Técnicas → BD) |
| `errores_criticos_logs` | P1 | "⚠️ 12 errores críticos hoy — Último 09:30" | **Ver detalles** (últimos 5 con stack trace) | **Limpiar logs** (archiva viejos, opcional) |
| `token_api_expirado` | P1 | "⚠️ Token de Cloudbeds expira en 2 horas" | **Renovar ahora** (intenta renovar) | **Ir a configuración** (Ajustes → Cloudbeds) |
| `conectividad_intermitente` | P1 | "⚠️ Conectividad intermitente — Última request fallida hace 10 min" | **Diagnosticar** (test conectividad/DNS/latencia) | **Ver estado** (tab Técnicas → conectividad) |

**Comportamiento común a todas:**
- Los botones ejecutan la acción y registran en `bitacora_alertas` con timestamp, usuario y resultado.
- Sin botón "descartar": las alertas persisten hasta resolverse o hasta que la condición desaparezca (sync OK, BD baja de umbral, etc.).
- Borde izquierdo coloreado por prioridad (ver §5.6).

### 5.6 Estilo de la tarjeta

```html
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 mx-4 my-2">
  <!-- Icono + título + descripción + botones -->
</div>
```

**Borde izquierdo coloreado por prioridad:**
- P0: `border-l-4 border-red-500`
- P1: `border-l-4 border-yellow-500`

---

## 6. Sección 2 — Tab "Operativas" (Métricas operativas)

### 6.1 Propósito

Mostrar la "foto macro" de la operación del día: cuántas habitaciones, auditorías, trabajadores, tickets. Incluye 3 KPIs críticos para la toma de decisiones.

### 6.2 Visualización

**Móvil:** lista vertical de tarjetas de conteo + KPIs

**Desktop (≥md):** grid de 2-3 columnas

### 6.3 Contenido — Contadores base (4)

Cada contador es una tarjeta con:
- Icono + título
- Número grande
- Subtexto (si aplica)
- Color o badge de estado

**Contador 1: Habitaciones hoy**
```
┌──────────────────────────────────┐
│  🏠  Habitaciones hoy             │
│                                  │
│  Total: 50                       │
│  ✅ Limpias: 28  ⏳ Progreso: 8  │
│  ⏸️  Pendientes: 10  ❌ No asign: 4 │
└──────────────────────────────────┘
```
- Desglose por estado: limpias (verde), en progreso (azul), pendientes (gris), no asignadas (rojo)
- **Por hotel:** si selecciona un hotel, solo muestra datos de ese hotel
- **Consolidado:** si selecciona "Ambos hoteles", muestra totales + agrupación visual

**Contador 2: Auditorías del día**
```
┌──────────────────────────────────┐
│  ✔️  Auditorías del día           │
│                                  │
│  ✅ Aprobadas: 25                │
│  📝 Con observación: 2           │
│  ❌ Rechazadas: 1                │
└──────────────────────────────────┘
```

**Contador 3: Trabajadores activos**
```
┌──────────────────────────────────┐
│  👥  Trabajadores                │
│                                  │
│  En turno: 5  |  Disponibles: 1  │
│  Fuera de turno: 0               │
└──────────────────────────────────┘
```

**Contador 4: Tickets abiertos**
```
┌──────────────────────────────────┐
│  🎫  Tickets abiertos             │
│                                  │
│  Total: 2                        │
│  (Sin atender)                   │
└──────────────────────────────────┘
```

> **Nota:** "Tiempo promedio de limpieza" NO es un contador — es un KPI con meta y estado. Ver §6.4 KPI 1.

### 6.4 Contenido — KPIs (3)

Cada KPI es una tarjeta con:
- Título
- Valor actual (grande)
- Meta (línea de referencia)
- Barra visual
- Estado (🟢 OK / 🟡 ALERTA / 🔴 CRÍTICO)

**KPI 1: Tiempo promedio de limpieza**
```
┌────────────────────────────────────┐
│  ⏱️  Tiempo promedio                │
│                                    │
│  28 min  [meta: 30 min]  🟢 OK    │
│  ████████████░░░░░░░░░░░░ 93%     │
│                                    │
│  Estado: Dentro de meta            │
└────────────────────────────────────┘
```
- Cálculo: promedio de (timestamp_fin - timestamp_inicio) para habitaciones completadas hoy
- Estados: 🟢 OK ≤ meta, 🟡 ALERTA = meta ± 5%, 🔴 CRÍTICO > meta + 5%
- **Por hotel:** si selecciona un hotel, KPI solo de ese hotel

**KPI 2: Tasa de rechazo**
```
┌────────────────────────────────────┐
│  📊  Tasa de rechazo               │
│                                    │
│  3.3%  [meta: <5%]  🟢 OK         │
│  ███░░░░░░░░░░░░░░░░░░░░░░░░░ 66% │
│                                    │
│  (1 rechazada de 30 auditadas)    │
└────────────────────────────────────┘
```
- Cálculo: (rechazadas / total_auditadas) × 100
- Estados: 🟢 OK ≤ 5%, 🟡 ALERTA = 5-7%, 🔴 CRÍTICO > 7%

**KPI 3: Eficiencia de equipo**
```
┌────────────────────────────────────┐
│  💪  Eficiencia de equipo          │
│                                    │
│  85%  [meta: ≥85%]  🟢 OK         │
│  ██████████████████░░░░░░░░░░░░░░ │
│                                    │
│  (28 completadas / 33 asignadas)  │
└────────────────────────────────────┘
```
- Cálculo: (completadas / asignadas) × 100 — NO incluye no_asignadas
- Estados: 🟢 OK ≥ 85%, 🟡 ALERTA = 75-85%, 🔴 CRÍTICO < 75%

### 6.5 Estilos

Las tarjetas de conteo y KPIs siguen el mismo patrón:

```html
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 mx-4 my-2">
  <!-- Icono + título -->
  <!-- Contenido (número, estado, barra) -->
</div>
```

**Barras de progreso:**
```html
<div class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
  <div style="width: 85%" class="h-full bg-green-500"></div>
</div>
```

---

## 7. Sección 2 — Tab "Técnicas" (Salud del sistema)

### 7.1 Propósito

Mostrar la salud técnica completa del sistema para que el Admin tenga control total. 5 indicadores críticos.

### 7.2 Indicadores (5)

Cada indicador es una tarjeta detallada:

**Indicador 1: Cloudbeds**
```
┌────────────────────────────────────┐
│  🔄  Sincronización Cloudbeds      │
│                                    │
│  Estado: 🟢 OK                     │
│  Última sync: 2026-04-14 10:45     │
│  Próxima: 2026-04-14 11:15 (30 min)│
│  Lag: <1 min                       │
└────────────────────────────────────┘
```
- Estados:
  - 🟢 OK = última sync exitosa < 30 min
  - 🟡 ALERTA = última sync 30-60 min
  - 🔴 ERROR = última sync > 60 min o última sync falló
- Timestamps legibles: "10:45" o "hace 5 min"
- Botón "Reintentar" (igual a alerta)

**Indicador 2: Errores en logs**
```
┌────────────────────────────────────┐
│  ⚠️  Errores en logs hoy           │
│                                    │
│  Total: 12                         │
│  Críticos: 3                       │
│  Último error: 2026-04-14 09:30    │
│  Severidad: Alta ⚠️                │
└────────────────────────────────────┘
```
- Contador simple + breakdown por severidad
- Botón "Ver detalles" (muestra últimos 10 errores con stack trace)

**Indicador 3: Estado de Base de Datos**
```
┌────────────────────────────────────┐
│  💾  Base de datos                 │
│                                    │
│  Tamaño: 256 MB / 512 MB (50%)     │
│  Estado: 🟢 OK                     │
│  ██████████░░░░░░░░░░░░░░░░░░░░░  │
│                                    │
│  Crecimiento: +5 MB/día            │
└────────────────────────────────────┘
```
- Porcentaje usado
- Barra visual
- Estados: 🟢 OK < 70%, 🟡 ALERTA 70-85%, 🔴 CRÍTICO > 85%
- Proyección si crece al ritmo actual
- Botón "Ver breakdown por tabla"

**Indicador 4: Usuarios activos**
```
┌────────────────────────────────────┐
│  👥  Usuarios activos              │
│                                    │
│  Ahora: 3                          │
│  - Trabajador (María Inés)         │
│  - Trabajador (Juan Pérez)         │
│  - Recepción (Carlos)              │
│                                    │
│  Último turno: 8 usuarios          │
└────────────────────────────────────┘
```
- Lista de sesiones activas actuales (excluye al Admin que está viendo)
- Nombres + roles
- Última actividad de cada uno
- Comparación con último turno

**Indicador 5: Versión de la app**
```
┌────────────────────────────────────┐
│  📦  Versión de la app             │
│                                    │
│  Actual: 1.0.0                     │
│  Ambiente: Producción              │
│  Último deploy: 2026-04-10 14:30   │
│  Commit: a3f8e2c                   │
└────────────────────────────────────┘
```
- Versión + ambiente (dev/staging/prod)
- Timestamp del último deploy
- Commit hash (corto)
- **Read-only** — no editable desde UI

### 7.3 Estilo

Mismo contenedor que las tarjetas de §6.5. Pueden ser más densas (más datos por tarjeta) pero respetan el mismo padding, border-radius y colores dark/light.

---

## 8. Bottom Tab Bar

Ubicado en la parte inferior de la pantalla, siempre visible.

```
┌──────────────────────────────────────┐
│  [🏠 Inicio] [📊 Operativas]        │
│  [⚙️ Técnicas] [🔧 Ajustes]          │
└──────────────────────────────────────┘
```

**Tabs:**
1. **Inicio** — Alertas técnicas + operativas P0-P1 (página actual)
2. **Operativas** — Contadores + KPIs
3. **Técnicas** — 5 indicadores de salud
4. **Ajustes** — **Navega al módulo Ajustes separado** (no es un tab con contenido embebido — redirige a `/ajustes`). Consistente con las otras Homes.

**Estilos:**
- Tab activo: fondo coloreado, texto bold
- Tab inactivo: texto gris
- Altura: ~56px (safe area para thumb en móvil)
- En desktop: se mantiene igual (no cambia a sidebar)

---

## 9. FAB Copilot

Flotante en esquina inferior derecha, siempre visible.

```html
<button class="fixed bottom-20 right-4 md:bottom-6 md:right-6 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center z-50" aria-label="Abrir copilot">
  <i data-lucide="sparkles" class="w-6 h-6"></i>
</button>
```

**Propiedades:**
- Flotante: `fixed bottom-20 right-4` en móvil (sobre el tab bar), `md:bottom-6 md:right-6` en desktop. Posición estandarizada en las 4 Homes.
- Icono: Lucide **`sparkles`** (estandarizado en las 4 Homes — transmite "asistente IA").
- Al tocar: abre panel copilot (deslizable desde abajo en móvil, lateral en desktop)
- Siempre accesible
- Respeta permisos dinámicos (Admin tiene todos)
- Voz vía Web Speech API (micrófono dentro del panel del copilot, no en el FAB)

---

## 10. Refresco de datos

**Automático:** cada 30 minutos en background

**Manual (desktop y móvil):** botón de refresco en el header (ícono Lucide `rotate-cw`)

**Pull-to-refresh (solo móvil):** gesto de arrastrar hacia abajo desde la parte superior del contenido para forzar refresco. Muestra un spinner circular mientras se ejecuta el fetch. Mismo patrón que `docs/home-supervisora.md` §10.4 — las 4 Homes son consistentes en este gesto.

**Indicador visual:** spinner pequeño en el botón de refresco y, durante pull-to-refresh, spinner arriba del contenido.

**Comportamiento:** actualiza datos sin recargar la pantalla (sin flash), usando AJAX/fetch al endpoint `GET /api/home/admin`

**Persistencia:** la selección de hotel persiste en localStorage entre refreshes

---

## 11. Responsive

### 11.1 Móvil (< 768px)

- Una columna
- Scroll vertical
- Header igual
- Tabs igual
- Alertas: tarjetas apiladas
- Contadores: apilados verticalmente
- KPIs: apilados verticalmente
- FAB: sobre tab bar (`bottom-20`)

### 11.2 Desktop (≥ md: 768px)

Layout de 2 columnas:
```
┌────────────────────────────────────────┐
│  Header (ancho completo)               │
├────────────────────────────────────────┤
│  Alertas técnicas (ancho completo)     │
├──────────────────┬──────────────────┤
│  Operativas      │  Técnicas        │
│  (izq, ancho)    │  (der, ancho)    │
│                  │                  │
│  - Contadores    │  - Cloudbeds     │
│  - KPIs          │  - Errores       │
│                  │  - BD            │
│                  │  - Usuarios      │
│                  │  - Versión       │
└──────────────────┴──────────────────┘
```

**Ambas columnas tienen altura equilibrada** (no deben quedar desequilibradas visualmente).

FAB: `bottom-4 right-4` (abajo del tab bar)

---

## 12. Modo día/noche

Aplicar el mismo patrón que las otras Homes:
- `dark:` classes en todas las tarjetas, texto, bordes
- Persistencia en localStorage (`tema-preferido`)
- Sin flash al cargar (leer localStorage antes de renderizar)
- Toggle en tab Ajustes

---

## 13. Accesibilidad

**Lineamientos obligatorios:**
- Áreas tappables ≥ 44x44px
- Tipografía mínima 14px en móvil
- Contraste WCAG AA (mínimo 4.5:1 para texto normal, 3:1 para texto grande)
- `aria-label` en botones con solo icono
- Foco visible (outline o highlight)
- Números grandes (KPIs) tienen suficiente contraste en dark mode
- Etiquetas descriptivas acompañan cada número (no solo "28", sino "28 min")

---

## 14. Datos del backend

### 14.1 Endpoint

```
GET /api/home/admin
```

**Headers (si aplica):**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Query params (opcionales):**
- `hotel` — filtrar: "atan_inn" | "atan" | "ambos" (default: "ambos")
- `refresh` — forzar refresh sin cache (si aplicable)

### 14.2 Response schema

```json
{
  "status": "success",
  "data": {
    "usuario": {
      "id": "rut_12345678",
      "nombre": "Nicolás Campos",
      "primer_nombre": "Nicolás",
      "rol": "Administrador",
      "permisos": ["alertas.recibir_predictivas", "kpis.ver_operativas", "sistema.ver_salud", "ajustes.acceder"]
    },

    "hotel_seleccionado": "ambos", // "atan_inn" | "atan" | "ambos"

    "indicador_estado_sistema": "OK", // "OK" | "ALERTA" | "ERROR"

    "alertas_tecnicas": [
      {
        "id": "alert_001",
        "prioridad": 0,
        "tipo": "cloudbeds_sync_failed",
        "titulo": "Sincronización Cloudbeds falló",
        "descripcion": "Última sincronización exitosa: hace 45 minutos",
        "timestamp": "2026-04-14T10:30:00Z",
        "acciones": [
          {
            "id": "action_retry",
            "label": "Reintentar ahora",
            "tipo": "POST",
            "endpoint": "/api/cloudbeds/sync/retry"
          },
          {
            "id": "action_details",
            "label": "Ver error detallado",
            "tipo": "GET",
            "endpoint": "/api/cloudbeds/sync/last-error"
          }
        ]
      }
      // máximo 5 alertas
    ],

    "metricas_operativas": {
      "atan_inn": {
        "hotel_id": "atan_inn",
        "hotel_nombre": "Atankalama Inn",
        "habitaciones": {
          "limpias": 15,
          "en_progreso": 3,
          "pendientes": 5,
          "no_asignadas": 2,
          "total": 25
        },
        "auditorias": {
          "aprobadas": 12,
          "con_observacion": 2,
          "rechazadas": 1,
          "total": 15
        },
        "trabajadores": {
          "en_turno": 5,
          "disponibles": 1,
          "fuera_turno": 2,
          "total": 8
        },
        "tickets_abiertos": 2,
        "tiempo_promedio_minutos": 28
      },
      "atan": {
        // igual estructura
      },
      "consolidado": {
        // suma de ambos hoteles
      }
    },

    "kpis": {
      "atan_inn": {
        "tiempo_promedio": {
          "valor": 28,
          "unidad": "minutos",
          "meta": 30,
          "estado": "OK", // "OK" | "ALERTA" | "CRITICO"
          "porcentaje": 93
          // "trending": Fase 2 — comparativa vs histórico
        },
        "tasa_rechazo": {
          "valor": 3.3,
          "unidad": "%",
          "meta": 5,
          "estado": "OK",
          "porcentaje": 66,
          "contexto": "1 rechazada de 30 auditadas"
        },
        "eficiencia_equipo": {
          "valor": 85,
          "unidad": "%",
          "meta": 85,
          "estado": "OK",
          "porcentaje": 85,
          "contexto": "28 completadas de 33 asignadas"
        }
      },
      "atan": {
        // igual estructura
      },
      "consolidado": {
        // promedio ponderado de ambos hoteles
      }
    },

    "sistema": {
      "cloudbeds": {
        "estado": "OK", // "OK" | "ALERTA" | "ERROR"
        "ultima_sync": "2026-04-14T10:45:00Z",
        "ultima_sync_relativa": "hace 5 min",
        "proxima_sync_programada": "2026-04-14T11:15:00Z",
        "lag_segundos": 45
      },
      "errores_logs": {
        "cantidad_hoy": 3,
        "cantidad_criticos": 0,
        "cantidad_warnings": 2,
        "timestamp_ultimo_error": "2026-04-14T09:30:00Z",
        "severidad_maxima": "warning" // "critical" | "error" | "warning" | "info"
      },
      "base_datos": {
        "tamaño_mb": 256,
        "limite_mb": 512,
        "porcentaje_usado": 50,
        "estado": "OK", // "OK" | "ALERTA" | "CRITICO"
        "crecimiento_mb_por_dia": 5,
        "dias_para_llenar": 51
      },
      "usuarios_activos": {
        "ahora": 3,
        "listado": [
          {
            "usuario_id": "rut_98765432",
            "nombre": "María Inés García",
            "rol": "Trabajador de limpieza",
            "ultima_actividad": "2026-04-14T10:47:00Z"
          }
          // más usuarios
        ],
        "ultimo_turno": 8
      },
      "version_app": {
        "actual": "1.0.0",
        "ambiente": "produccion", // "desarrollo" | "staging" | "produccion"
        "timestamp_deploy": "2026-04-10T14:30:00Z",
        "commit_hash": "a3f8e2c"
      }
    },

    "timestamp_request": "2026-04-14T10:50:00Z",
    "timestamp_proximo_refresh": "2026-04-14T11:20:00Z"
  }
}
```

### 14.3 Notas importantes del endpoint

- **NO expone API keys, tokens completos ni credenciales** — solo estados
- **Respuesta en <500ms** (performance crítica)
- **Todos los timestamps en ISO 8601 UTC**, plus campo "relativo" en legible ("hace 5 min")
- **Alertas ordenadas por prioridad** (0 primero, luego 1)
- **Permisos se validan en backend** — si usuario no tiene permiso, sección no aparece en JSON
- **Cálculos verificables:** cada métrica/KPI tiene contexto numérico (ej: "28 completadas de 33 asignadas")

---

## 15. Checklist de comportamientos críticos

**El Admin es el controlador del sistema — máxima confiabilidad requerida.**

Estos son los 10 comportamientos **no negociables** que deben verificarse antes de aprobar el módulo:

1. [ ] **Eficiencia = (completadas / asignadas) × 100**, NO incluye `no_asignadas`.
2. [ ] **Tasa de rechazo = (rechazadas / total_auditadas) × 100** — % sobre lo auditado, no sobre todas las habitaciones.
3. [ ] **Tiempo promedio** solo suma habitaciones completadas (excluye en progreso y pendientes).
4. [ ] Las **métricas consolidadas** ("Ambos hoteles") suman correctamente; las métricas por hotel NO incluyen datos del otro.
5. [ ] Los **permisos se validan en backend** — si el usuario no tiene un permiso, la sección NO aparece en el JSON (no solo se oculta en UI).
6. [ ] **NO se exponen API keys, tokens completos ni credenciales** en el JSON del endpoint, ni siquiera parciales (solo estados).
7. [ ] Las **alertas persisten** hasta resolverse — no desaparecen automáticamente ni tienen botón "descartar".
8. [ ] Los **botones de acción en alertas** registran la acción en `bitacora_alertas` (timestamp, usuario, resultado).
9. [ ] El **indicador 🟢/🟡/🔴** del header refleja estado real en tiempo real (actualiza con cada refresco).
10. [ ] El **endpoint `GET /api/home/admin`** retorna <500ms incluso con 100+ habitaciones (índices bien usados, sin full table scans).

**Checklist QA exhaustiva (75+ items):** ver `docs/home-admin-qa-checklist.md` — comportamientos completos organizados por área (cálculos, filtrado, KPIs, refresco, alertas, permisos, responsive, header, FAB, UI, tab bar, seguridad, performance).

---

## 16. Vinculaciones con otros módulos

### Dependencias (deben existir antes)

- `docs/auth.md` — autenticación RUT, sesiones
- `docs/habitaciones.md` — estados (completada, en_progreso, pendiente, rechazada, aprobada, aprobada_con_observacion)
- `docs/cloudbeds.md` — integración, API keys, sync schedule, estados de sync
- `docs/copilot-ia.md` — copilot con permisos dinámicos
- `docs/alertas-predictivas.md` — sistema de alertas (P0-P3), cálculos de riesgo
- `docs/roles-permisos.md` — RBAC dinámico, matriz de permisos
- `docs/auditoria.md` — los 3 estados de auditoría
- `docs/trabajadores.md` — datos de trabajadores, asignaciones, carga
- `docs/tickets.md` — sistema de tickets, estados, prioridades
- `docs/logs.md` — sistema de logging, errores, eventos, audit trail

### Accede a (desde tabs/secciones)

- `docs/usuarios.md` — tab Ajustes: CRUD usuarios, asignación de roles
- `docs/turnos.md` — tab Ajustes: crear/editar turnos, horarios
- `docs/ajustes.md` — tab Ajustes completo
- `docs/checklist.md` — tab Ajustes: editar checklists
- `docs/cloudbeds-config.md` — tab Ajustes: credenciales Cloudbeds
- `docs/alertas-config.md` — tab Ajustes: umbrales de alertas
- `docs/logs-viewer.md` — tab Técnicas: ver logs filtrados

### Comparte componentes con

- `docs/home-supervisora.md` — sistema de alertas (tarjetas P0-P1, botones)
- `docs/home-supervisora.md` — patrón de selector de hotel
- `docs/home-recepcion.md` — contadores de habitaciones
- `docs/home-trabajador.md` — patrón header (avatar, saludo)
- Componentes reutilizables: `components/tarjeta-alerta.php`, `components/contador-metrica.php`, `components/badge-estado.php`

### Genera/modifica (datos que el Admin controla)

- `database/seeds/permisos.php` — catálogo de permisos
- `database/seeds/roles.php` — roles por defecto
- `tables/usuarios` — gestión completa
- `tables/roles` — crear roles nuevos
- `tables/permisos_rol` — matriz RBAC
- `tables/turnos` — crear/editar turnos
- `tables/checklists` — editar checklists
- `tables/alertas_config` — configurar umbrales
- `tables/cloudbeds_config` — credenciales
- `tables/audit_log` — registro de acciones del Admin

### Monitorea (datos de solo lectura)

- `tables/habitaciones` — estado actual, asignaciones
- `tables/trabajadores` — activos, carga, histórico
- `tables/asignaciones` — quién está asignado a qué
- `tables/auditorias` — veredictos, observaciones
- `tables/alertas_activas` — alertas P0-P3
- `tables/tickets` — tickets abiertos
- `tables/cloudbeds_sync_history` — histórico de syncs
- `tables/errores_logs` — errores con stacktrace
- `tables/usuarios_sesiones` — quiénes están activos
- `tables/copilot_historial` — historial de copilot (auditoría)

### Controla flujos críticos

- Sincronización Cloudbeds — puede forzar sync desde alerta
- Tokens/credenciales — Admin renueva, configura, ve errores
- Sistema de permisos — Admin ajusta RBAC
- Alertas — Admin configura umbrales
- Checklists — Admin define qué se audita
- Base de datos — Admin ve uso, planifica backup
- Versión de app — Admin ve qué versión está desplegada

---

## 17. Notas operativas para Claude Code

### 17.1 Modo de codificación

**Supervisión por módulo (UI crítica para el MVP)**

Claude Code codifica la Home del Admin completa, pero **antes de mergear a main**, Nicolás revisa el código line-by-line y aprueba o pide cambios.

**Razón:** El Admin es la última capa de control. Errores en queries, cálculos de KPI o permisos dinámicos son críticos. 30 minutos de revisión evita semanas debuggeando métricas falsas.

**Flujo:**
1. Claude Code termina y envía mensaje: "Home del Admin lista para revisar"
2. Nicolás revisa queries, KPI, permisos, endpoint
3. Nicolás dice ✅ o pide ajustes
4. Una vez aprobado, se mergea

### 17.2 Archivos a crear

**Controllers:**
- `app/Controllers/HomeAdminController.php`

**Services (lógica de negocio):**
- `app/Services/MetricasOperativasService.php`
- `app/Services/EstadoSistemaService.php`
- `app/Services/AlertasTecnicasService.php`

**Models:**
- `app/Models/Habitacion.php` (si no existe)
- `app/Models/Auditoria.php` (si no existe)
- `app/Models/AlertaTecnica.php` (si no existe)
- `app/Models/Usuario.php` (si no existe)

**Views:**
- `resources/views/admin/home.php`
- `resources/views/admin/partials/header-admin.php`
- `resources/views/admin/partials/tab-inicio.php`
- `resources/views/admin/partials/tab-operativas.php`
- `resources/views/admin/partials/tab-tecnicas.php`
- `resources/views/admin/partials/alerta-tecnica-tarjeta.php`
- `resources/views/admin/partials/metrica-contador.php`
- `resources/views/admin/partials/kpi-card.php`

**API Routes:**
- `routes/api.php` — endpoint `GET /api/home/admin`

**Migrations (si se necesitan):**
- `database/migrations/xxxx_create_alertas_tecnicas_table.php`
- `database/migrations/xxxx_create_audit_log_table.php`

### 17.3 Tests sugeridos

**Unit Tests:**
- `tests/Unit/Services/MetricasOperativasServiceTest.php` — cálculos de métricas
- `tests/Unit/Services/EstadoSistemaServiceTest.php` — estado Cloudbeds, errores, BD, usuarios
- `tests/Unit/Services/AlertasTecnicasServiceTest.php` — obtención y ordenamiento de alertas

**Feature Tests:**
- `tests/Feature/Admin/HomeAdminTest.php` — endpoint, tabs, filtrado por hotel, permisos

**Regression Tests:**
- `tests/Regression/AdminHomeTest.php` — datos no incluyen otros hoteles, cálculos correctos

**Security Tests:**
- `tests/Security/AdminHomeSecurityTest.php` — NO expone credenciales, permisos validados

**Performance Tests:**
- `tests/Performance/AdminHomePerformanceTest.php` — endpoint <500ms, queries optimizadas

**Total sugerido para MVP:** ~30-40 tests (suficientes, escalables después)

### 17.4 Decisiones autónomas permitidas

Si Claude Code ve algo no especificado aquí, puede tomar decisiones menores:
- Nombres exactos de clases/métodos (si siguen convención del proyecto)
- Ancho de espaciado en gráficos (si sigue Tailwind)
- Orden de métodos en clases (si es lógico)
- Nombres de variables internas (si son claros)

**NO tomar decisiones autónomas sobre:**
- Queries (deben ser validadas)
- Cálculos de KPI (deben ser exactos)
- Permisos (deben ser dinámicos)
- Endpoint schema (debe ser como se especifica)

---

## 18. Formato y estructura de entrega

### 18.1 Estado de codificación

El módulo está **LISTO PARA CODIFICACIÓN**.

### 18.2 Próximos pasos (después de aprobación de Nicolás)

1. Claude Code codifica siguiendo esta especificación
2. Nicolás revisa y aprueba
3. Se mergea a main
4. Se despliega a staging para pruebas
5. Se despliega a producción

### 18.3 Cambios futuros (Fase 2)

- Gráficos de tendencias (hoy vs ayer)
- Ranking de trabajadores
- Histórico de KPIs
- Exportar reportes (PDF/Excel)
- Más indicadores técnicos (CPU, RAM, etc.)

---

*Especificación completada: 14 de abril de 2026*
*Aprobada para codificación por Nicolás Campos*
*Versión 1.0 — Estado: ✅ Listo para Claude Code*
