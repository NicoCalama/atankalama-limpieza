# Home del Trabajador de Limpieza — Especificación detallada

**Módulo:** Home / Dashboard
**Rol destinatario:** Trabajador de Limpieza
**Versión:** 1.0
**Fecha:** 08 de abril de 2026
**Estado:** ✅ Diseño aprobado, listo para codificación
**Modo de codificación de este módulo:** Supervisión por módulo (UI crítica para el MVP)

> Esta es la **especificación ejecutable** que Claude Code debe seguir al codificar la Home del Trabajador de Limpieza. Léela completa antes de empezar. Cualquier decisión no especificada aquí debe seguir los **defaults razonables** del `CLAUDE.md` raíz, marcando con comentarios `// DECISIÓN AUTÓNOMA: ...`.

---

## 1. Contexto y propósito

### 1.1 Quién usa esta pantalla

Personal de limpieza del hotel. Perfil tipo: María Inés, una persona que llega al hotel a las 9 de la mañana, saca el celular del bolsillo del uniforme, y abre la app. Necesita saber en 2 segundos qué tiene que hacer.

### 1.2 Qué responde esta pantalla

Una sola pregunta: **"¿Qué tengo que hacer ahora?"**

No es un menú. No es un dashboard frío con métricas. Es la primera pantalla que ve al abrir la app, y muestra inmediatamente lo más urgente para ese momento. Los menús y secciones secundarias quedan en la navegación inferior.

### 1.3 Filosofía UX clave — sin generar ansiedad

**Decisión de diseño crítica:** esta pantalla deliberadamente **oculta métricas y contadores numéricos** ("te quedan 3", "vas al 67%", tiempo transcurrido) porque pueden generar ansiedad en el personal. Solo muestra **barras de progreso visuales** que transmiten avance sin presión.

La presión se gestiona desde el rol de la supervisora vía las alertas predictivas (que el trabajador NO ve).

**Esto es un requisito de diseño no negociable.** Si codificando encuentras espacio para "agregar valor mostrando al trabajador cuántas le quedan", **NO lo hagas**. Ya se discutió y se decidió en contra deliberadamente.

### 1.4 Dispositivo principal

**Mobile-first absoluto.** Diseñado para celular vertical (375px de ancho como base). Probado prioritariamente en:
- Celular vertical (Android/iOS) — uso primario
- Tablet vertical — uso secundario
- Desktop — solo testing y supervisores que abran ocasionalmente

---

## 2. Permisos requeridos

Esta pantalla solo es visible para usuarios que tengan **TODOS** estos permisos:

- `habitaciones.ver_asignadas_propias`
- `habitaciones.marcar_completada`

Estos son los permisos del rol "Trabajador de limpieza" por defecto. Otros roles (Admin, Supervisora, etc.) que tengan estos permisos también podrán ver esta pantalla, pero el flujo está optimizado para el trabajador.

---

## 3. Layout general

La pantalla se divide en 5 secciones, de arriba a abajo, en una sola columna en móvil:

```
┌───────────────────────────────────┐
│  SECCIÓN 1 — Header               │
│  (saludo, hotel, campana)         │
├───────────────────────────────────┤
│                                   │
│  SECCIÓN 2 — Tarjeta de progreso  │
│  (barra visual sin números)       │
│                                   │
├───────────────────────────────────┤
│                                   │
│  SECCIÓN 3 — Habitación actual    │
│  (tarjeta destacada con CTA)      │
│                                   │
├───────────────────────────────────┤
│                                   │
│  SECCIÓN 4 — Resto de habitaciones│
│  (lista compacta)                 │
│                                   │
│                                   │
├───────────────────────────────────┤
│                          [FAB IA] │  ← Flotante
│  SECCIÓN 5 — Bottom tab bar       │
└───────────────────────────────────┘
```

En tablet vertical, el layout es el mismo. En desktop (≥`md:`), las secciones 2, 3 y 4 se reorganizan en grid de 2 columnas (ver sección 9 — Responsive).

---

## 4. Sección 1 — Header

### 4.1 Layout

Header fijo en la parte superior, ocupa todo el ancho. Altura aproximada: 72-80px.

```
┌──────────────────────────────────────────┐
│  [M]  Buenos días, María          [🔔]   │
│       Hotel Atankalama Inn               │
└──────────────────────────────────────────┘
```

### 4.2 Elementos de izquierda a derecha

**4.2.1 Avatar circular**
- Diámetro: 48px en móvil, 56px en desktop
- Fondo: color sólido determinístico basado en el hash del RUT del usuario (para que siempre sea el mismo color para el mismo usuario)
- Contenido: la primera letra del nombre del usuario, en mayúscula, blanco, font-bold
- Si el nombre tiene varias palabras (ej: "María Inés"), tomar solo la primera letra de la primera palabra
- Tappable: al tocarlo va a la pantalla de "Mi perfil" / Ajustes

**4.2.2 Bloque de saludo (al lado derecho del avatar)**

Línea 1 — Saludo contextual + nombre
- Texto: `{saludo}, {primer_nombre}`
- `saludo` se calcula según hora local actual:
  - `00:00 - 11:59` → "Buenos días"
  - `12:00 - 18:59` → "Buenas tardes"
  - `19:00 - 23:59` → "Buenas noches"
- `primer_nombre` es la primera palabra del campo `nombre` del usuario
- Tipografía: `text-lg font-semibold text-gray-900 dark:text-gray-100`

Línea 2 — Hotel actual
- Texto: el nombre del hotel donde está trabajando hoy (de la asignación activa del trabajador)
- Si no tiene asignaciones activas todavía, mostrar el hotel asociado a su perfil
- Si trabaja regularmente en ambos hoteles, mostrar el del turno activo
- Tipografía: `text-sm text-gray-500 dark:text-gray-400`

**4.2.3 Icono de notificaciones (campana)**
- Icono Lucide: `bell`
- Tamaño: `w-6 h-6`
- Botón circular tappable: `min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800`
- Si hay notificaciones sin leer, mostrar un dot rojo en la esquina superior derecha del icono (`absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full`)
- Tappable: abre el panel/pantalla de notificaciones (a definir en otro `docs/`)

### 4.3 Estilo del header

```html
<header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
  <!-- Avatar + saludo (izquierda) -->
  <!-- Campana (derecha) -->
</header>
```

**Sticky:** el header se queda fijo arriba al hacer scroll.

---

## 5. Sección 2 — Tarjeta de progreso del día

### 5.1 Propósito

Mostrar visualmente cuánto avance lleva el trabajador en su carga del día, **sin números, sin presión**.

### 5.2 Layout

Tarjeta blanca con sombra suave, ocupa todo el ancho disponible (con padding lateral).

```
┌────────────────────────────────────────┐
│                                        │
│  Tu día de hoy                         │
│                                        │
│  ████████████████░░░░░░░░░░░░░░░░░░░  │
│  └─verde─┘└azul┘└─────gris─────┘      │
│                                        │
└────────────────────────────────────────┘
```

### 5.3 Elementos

**5.3.1 Título**
- Texto: "Tu día de hoy"
- Tipografía: `text-base font-semibold text-gray-900 dark:text-gray-100 mb-3`

**5.3.2 Barra de progreso con segmentos**

Estructura:
- Contenedor: `w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex`
- Segmento verde: habitaciones **completadas** (estado: `completada`, `aprobada`, o `aprobada_con_observacion`)
- Segmento azul: habitaciones **en progreso** (estado: `en_progreso` — el trabajador ya tocó "Comenzar limpieza" pero aún no terminó)
- Segmento gris (el fondo): habitaciones **pendientes** (estado: `pendiente` — asignadas pero no iniciadas)

Cálculo de los anchos (en porcentaje del total):
```
total = completadas + en_progreso + pendientes
ancho_verde = (completadas / total) * 100
ancho_azul = (en_progreso / total) * 100
ancho_gris = (pendientes / total) * 100  // queda como fondo
```

Colores:
- Verde: `bg-green-500`
- Azul: `bg-blue-500`
- Gris (fondo del contenedor): `bg-gray-200 dark:bg-gray-700`

Los anchos van con `style="width: X%"` (única excepción permitida al "no usar style inline" — porque es un valor dinámico que no se puede expresar en clase Tailwind).

**5.3.3 NO hay texto numérico debajo de la barra**

❌ NO escribir cosas como:
- "6 de 9 habitaciones"
- "Te quedan 3"
- "Vas al 67%"
- "Completaste X habitaciones"

Esta es una decisión de diseño deliberada. La barra es suficiente.

### 5.4 Estados especiales

**5.4.1 Estado "día completado"** — cuando todas las habitaciones del día están en estado completada/aprobada

La tarjeta cambia su apariencia:
- Fondo: `bg-green-50 dark:bg-green-900/20`
- Borde: `border-green-200 dark:border-green-800`
- En vez del título "Tu día de hoy" + barra, mostrar:
  - Icono Lucide grande `check-circle-2` o `party-popper` (`w-12 h-12 text-green-500`)
  - Texto en heading: "¡Día completado!"
  - Texto secundario: "Excelente trabajo"
  - Centrado, padding generoso

**5.4.2 Estado "sin asignaciones"** — cuando el trabajador no tiene NINGUNA habitación asignada todavía

NO mostrar la tarjeta de progreso (queda oculta). En su lugar, la sección 3 (habitación actual) se reemplaza por un estado vacío especial — ver sección 6.5.

### 5.5 Estilo de la tarjeta

```html
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 mx-4 mt-4">
  <!-- Contenido -->
</div>
```

---

## 6. Sección 3 — Habitación actual

### 6.1 Propósito

Esta es la sección más importante de la pantalla. Es el call-to-action principal: "esto es lo que tienes que hacer ahora, toca este botón gigante para empezar".

### 6.2 Concepto de "habitación actual"

La "habitación actual" es la **siguiente habitación que el sistema sugiere al trabajador**, calculada así:

1. Si el trabajador tiene una habitación en estado `en_progreso` (ya empezada pero no terminada), esa es la habitación actual
2. Si no tiene ninguna en progreso, es la primera habitación en estado `pendiente` según el orden de la cola del trabajador
3. La cola se ordena por:
   - Primero: por `orden_en_cola` que asigna la supervisora manualmente (si tiene)
   - Segundo: por prioridad (si existe)
   - Tercero: por número de habitación (orden natural)
4. Si no hay habitaciones pendientes ni en progreso, ver estado vacío (sección 6.5)

### 6.3 Layout

Tarjeta destacada, más grande que las demás, claramente marcada como la principal.

```
┌────────────────────────────────────────┐
│                                        │
│  Habitación actual                     │
│                                        │
│  ┌──────────────────────────────────┐  │
│  │                                  │  │
│  │   305                            │  │
│  │   Suite                          │  │
│  │   [Pendiente]                    │  │
│  │                                  │  │
│  └──────────────────────────────────┘  │
│                                        │
│  ┌──────────────────────────────────┐  │
│  │      Comenzar limpieza           │  │
│  └──────────────────────────────────┘  │
│                                        │
└────────────────────────────────────────┘
```

### 6.4 Elementos

**6.4.1 Título de sección**
- Texto: "Habitación actual"
- Tipografía: `text-base font-semibold text-gray-900 dark:text-gray-100 mb-3`
- Nota: este nombre puede cambiar en el futuro por sugerencia de gerencia. Si Claude Code ve que esta decisión cambió en una versión posterior del documento, respetar la nueva.

**6.4.2 Información de la habitación**

Bloque informativo con:
- **Número de habitación** — texto grande: `text-4xl font-bold text-gray-900 dark:text-gray-100`
- **Tipo de habitación** — debajo del número: `text-base text-gray-600 dark:text-gray-400` (ej: "Suite", "Doble", "VIP")
- **Badge de estado** — debajo del tipo, ver tabla de badges en sección 6.6

**6.4.3 Botón principal dinámico**

El texto del botón cambia según el estado de la habitación:

| Estado de la habitación | Texto del botón |
|------------------------|-----------------|
| `pendiente` (nunca tocada) | "Comenzar limpieza" |
| `en_progreso` (ya empezada) | "Continuar" |

Estilo del botón:
```html
<button class="w-full min-h-[56px] bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-lg font-semibold rounded-xl transition shadow-sm">
  Comenzar limpieza
</button>
```

Notas:
- **`min-h-[56px]`** — más alto que el mínimo de 44px, porque es el botón principal
- **`text-lg`** — tipografía más grande que botones secundarios
- Tap → navega al detalle de la habitación con el checklist (pantalla a definir en `docs/checklist.md`)
- Si toca "Continuar", el checklist debe abrirse exactamente en el estado donde lo dejó (con los items previamente marcados ya marcados)

### 6.5 Estado vacío — sin habitaciones asignadas

Cuando el trabajador no tiene NINGUNA habitación asignada (ni pendientes, ni en progreso, ni del día), reemplazar la sección entera por un estado vacío amigable:

```
┌────────────────────────────────────────┐
│                                        │
│              [icono grande]            │
│                                        │
│   No tienes habitaciones asignadas    │
│              todavía                   │
│                                        │
│   Espera a que tu supervisora te       │
│   asigne, o avísale que estás          │
│   disponible.                          │
│                                        │
│  ┌──────────────────────────────────┐  │
│  │   Avisar que estoy disponible    │  │
│  └──────────────────────────────────┘  │
│                                        │
└────────────────────────────────────────┘
```

Elementos:
- **Icono grande** Lucide `coffee` o `clock` o similar (`w-16 h-16 text-gray-400`) centrado
- **Título** — `text-lg font-semibold text-gray-900 dark:text-gray-100`
- **Descripción** — `text-base text-gray-600 dark:text-gray-400 text-center max-w-xs`
- **Botón** "Avisar que estoy disponible":
  - Estilo secundario: `bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600`
  - Al tocarlo, dispara una notificación interna a la supervisora del turno actual del trabajador
  - Después de tocarlo, el botón cambia a estado deshabilitado con texto "✓ Aviso enviado" durante el resto del día (el trabajador no debe poder spamear avisos)
  - El aviso se registra en la tabla `notificaciones_disponibilidad` con el `trabajador_id`, `supervisora_id_notificada`, timestamp

**Comportamiento del aviso:**
- POST a un endpoint tipo `POST /api/disponibilidad/avisar`
- El backend identifica la supervisora del turno actual del trabajador y le crea una notificación
- La supervisora la ve como badge en su campana + entrada en su bandeja de notificaciones
- El backend retorna `{ ok: true, mensaje: "Aviso enviado a tu supervisora" }`
- Mostrar un toast de confirmación al usuario y deshabilitar el botón

### 6.6 Tabla de badges de estado

| Estado | Texto del badge | Clase Tailwind |
|--------|-----------------|----------------|
| `pendiente` | "Pendiente" | `bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200` |
| `en_progreso` | "En progreso" | `bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200` |
| `completada` | "Completada" | `bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200` |
| `aprobada` | "Aprobada" | `bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200` |
| `aprobada_con_observacion` | "Aprobada" (igual que aprobada — el trabajador NO debe ver el "con observación") | `bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200` |
| `rechazada` | "Rechazada" | `bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200` |

⚠️ **Importante:** las habitaciones `aprobada_con_observacion` se muestran al trabajador EXACTAMENTE igual que las `aprobada` normales. Esta es una decisión deliberada (ver sección 1.3 — filosofía sin ansiedad). El trabajador no debe enterarse en su día a día de las observaciones; eso queda solo a nivel de KPIs y reportes para la supervisora.

---

## 7. Sección 4 — Lista del resto de habitaciones asignadas

### 7.1 Propósito

Mostrar al trabajador qué OTRAS habitaciones tiene asignadas hoy (además de la actual), en el orden en que se las va a tocar hacer.

### 7.2 Layout

Lista compacta, una habitación por fila, vertical, con scroll si hay muchas.

```
┌────────────────────────────────────────┐
│  Próximas                              │
├────────────────────────────────────────┤
│  308   Doble                [Pendiente]│
├────────────────────────────────────────┤
│  312   Doble                [Pendiente]│
├────────────────────────────────────────┤
│  315   Suite                [Pendiente]│
├────────────────────────────────────────┤
│  401   VIP                  [Pendiente]│
└────────────────────────────────────────┘
```

### 7.3 Elementos

**7.3.1 Título de la sección**
- Texto: "Próximas"
- Tipografía: `text-base font-semibold text-gray-900 dark:text-gray-100 px-4 mb-2`

**7.3.2 Filas de habitaciones**

Cada fila contiene:
- **Número de habitación** — `text-lg font-bold text-gray-900 dark:text-gray-100 w-12`
- **Tipo de habitación** — `text-base text-gray-600 dark:text-gray-400 flex-1 ml-4`
- **Badge de estado** — usa la tabla de badges de la sección 6.6

Estilo de la fila:
```html
<button class="w-full min-h-[60px] flex items-center px-4 py-3 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
  <span class="text-lg font-bold text-gray-900 dark:text-gray-100 w-12">305</span>
  <span class="text-base text-gray-600 dark:text-gray-400 flex-1 ml-4">Suite</span>
  <span class="badge ...">Pendiente</span>
</button>
```

**7.3.3 Comportamiento al tocar una fila**

- Tap → navega al detalle de esa habitación con el checklist
- Si la habitación está en `en_progreso`, abre el checklist con el progreso previo
- Si está en `pendiente`, abre el checklist desde cero

### 7.4 Estados especiales

**7.4.1 Si solo hay 1 habitación pendiente** (la "actual" de la sección 3 y nada más)
- No mostrar la sección 4 en absoluto
- La sección 3 sola es suficiente

**7.4.2 Si hay muchas habitaciones**
- La lista hace scroll vertical natural junto con el resto del contenido de la página
- NO usar scroll interno de la sección — confunde al usuario

**7.4.3 Habitaciones completadas del día**
- Por defecto, NO se muestran en esta lista (la sección se llama "Próximas")
- Para verlas, el trabajador entra a la tab "Habitaciones" del bottom bar y consulta el "Historial del día"

### 7.5 Orden de la lista

El mismo orden que se usa para calcular la "habitación actual" (ver sección 6.2). La habitación actual NO aparece en esta lista (porque ya está destacada en la sección 3).

---

## 8. Sección 5 — Bottom tab bar + FAB del copilot IA

### 8.1 Bottom tab bar

Barra inferior fija, visible siempre en móvil. **Oculta** en desktop (`md:` y arriba — en desktop usa sidebar lateral).

```
┌──────────────────────────────────────────┐
│  [🏠]    [📋]    [🛠️]    [⚙️]            │
│  Inicio  Habitac. Tickets  Ajustes       │
└──────────────────────────────────────────┘
```

**Tabs (4 elementos):**

1. **Inicio** — icono Lucide `home`, ruta `/` — está activa cuando se está en esta pantalla
2. **Habitaciones** — icono Lucide `clipboard-list`, ruta `/habitaciones` — listado completo de habitaciones del trabajador (asignadas + historial del día)
3. **Tickets** — icono Lucide `wrench`, ruta `/tickets` — tickets de mantenimiento que ha levantado
4. **Ajustes** — icono Lucide `settings`, ruta `/ajustes` — preferencias personales, cambio de contraseña, modo día/noche, historial del copilot, cerrar sesión

**Estilo:**
```html
<nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 md:hidden z-30">
  <div class="grid grid-cols-4">
    <a href="/" class="min-h-[60px] flex flex-col items-center justify-center text-blue-600 dark:text-blue-400">
      <i data-lucide="home" class="w-5 h-5"></i>
      <span class="text-xs mt-1">Inicio</span>
    </a>
    <!-- ... otras 3 tabs -->
  </div>
</nav>
```

**Estados:**
- Tab activa: `text-blue-600 dark:text-blue-400`
- Tab inactiva: `text-gray-500 dark:text-gray-400`
- Hover: `hover:bg-gray-50 dark:hover:bg-gray-700`

### 8.2 FAB del copilot IA

Botón flotante circular, siempre visible en TODAS las pantallas de la app. En esta pantalla específica, debe quedar **por encima del bottom tab bar** (no detrás).

**Ubicación:**
- En móvil: `fixed bottom-20 right-4` (20 unidades arriba del borde inferior, dejando espacio para el bottom bar de 60px de alto)
- En desktop: `fixed bottom-6 right-6`

**Estilo:**
```html
<button
  x-data
  @click="$dispatch('open-copilot')"
  aria-label="Abrir asistente IA"
  class="fixed bottom-20 right-4 md:bottom-6 md:right-6 w-14 h-14 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-full shadow-lg flex items-center justify-center transition z-50"
>
  <i data-lucide="sparkles" class="w-6 h-6"></i>
</button>
```

**Comportamiento:**
- Tap → abre el panel del copilot IA (especificación completa en `docs/copilot-ia.md`)
- En esta pantalla del Trabajador, el copilot tiene disponibles solo las herramientas correspondientes a los permisos del trabajador (`copilot.usar_nivel_1_consultas`, `copilot.usar_nivel_2_acciones`)

### 8.3 Padding inferior del contenido

Importante: como el bottom tab bar está fijo en `bottom-0`, el contenido principal de la pantalla debe tener un `padding-bottom` suficiente para que el último elemento no quede tapado por la barra.

```html
<main class="pb-24 md:pb-8">
  <!-- Secciones 1-4 -->
</main>
```

`pb-24` = 96px de padding inferior en móvil (más del alto del bottom bar de 60px + el FAB de 56px + margen).
`md:pb-8` = padding más chico en desktop donde no hay bottom bar.

---

## 9. Responsive — comportamiento en distintos tamaños

### 9.1 Móvil (base, < `md:`)

Tal cual se describió arriba: una columna, secciones apiladas verticalmente, bottom tab bar visible, sidebar oculto.

### 9.2 Tablet vertical y desktop (`md:` y arriba)

- Bottom tab bar **se oculta** (`md:hidden`)
- Aparece un **sidebar lateral izquierdo** con los mismos 4 ítems del bottom bar (ver `docs/layouts.md` cuando exista, o seguir el patrón visual del Chat Interno del hotel)
- El contenido principal se reorganiza:
  - **Sección 1 (Header)** se mantiene en la parte superior
  - **Sección 2 (Tarjeta de progreso)** ocupa el ancho completo
  - **Sección 3 (Habitación actual)** y **Sección 4 (Próximas)** se acomodan en grid de 2 columnas:
    ```
    ┌──────────────┬──────────────┐
    │ Habitación   │ Próximas     │
    │ actual       │              │
    │              │              │
    │ [CTA grande] │ • 308 Doble  │
    │              │ • 312 Doble  │
    │              │ • ...        │
    └──────────────┴──────────────┘
    ```
- FAB del copilot se mueve a `md:bottom-6 md:right-6`

### 9.3 Breakpoints específicos

Usar los breakpoints estándar de Tailwind:
- Base: 0-639px (móvil)
- `sm:` 640px+
- `md:` 768px+ (aquí cambia el layout)
- `lg:` 1024px+ (desktop pleno)

---

## 10. Modo día / modo noche

Toda la pantalla debe respetar el modo día/noche. Las clases `dark:` de Tailwind ya están en cada elemento descrito arriba.

El toggle día/noche **NO** está en la Home — está en Ajustes. Pero el estado se persiste en `localStorage` y se aplica al cargar la página (con un script inline en el `<head>` que evita el "flash" de modo claro al cargar).

```html
<script>
  // En el <head>, antes del CSS, para evitar el flash
  if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark');
  }
</script>
```

---

## 11. Accesibilidad

- Todos los botones tienen `aria-label` cuando solo tienen icono
- Los badges de estado deben tener suficiente contraste de color (los colores Tailwind elegidos cumplen WCAG AA)
- Tamaños de texto mínimos:
  - Texto principal: 16px (`text-base`)
  - Texto secundario: 14px (`text-sm`) — solo en líneas auxiliares como el nombre del hotel
  - NUNCA usar `text-xs` para información esencial
- Áreas tappables mínimas: 44x44px en todos los elementos interactivos
- El foco debe ser visible (no eliminar `:focus` styles)

---

## 12. Datos que necesita la pantalla del backend

Endpoint sugerido: `GET /api/home/trabajador`

Permiso requerido: el usuario logueado debe ser el trabajador (chequeo automático del middleware de auth).

Response sugerido:

```json
{
  "ok": true,
  "data": {
    "usuario": {
      "id": 12,
      "nombre": "María Inés Pérez",
      "primer_nombre": "María",
      "rut": "12.345.678-9"
    },
    "hotel_actual": {
      "id": 1,
      "nombre": "Hotel Atankalama Inn"
    },
    "progreso": {
      "completadas": 6,
      "en_progreso": 1,
      "pendientes": 2,
      "total": 9,
      "todas_completadas": false
    },
    "habitacion_actual": {
      "id": 105,
      "numero": "305",
      "tipo": "Suite",
      "estado": "pendiente",
      "checklist_progreso": null
    },
    "proximas": [
      { "id": 108, "numero": "308", "tipo": "Doble", "estado": "pendiente" },
      { "id": 112, "numero": "312", "tipo": "Doble", "estado": "pendiente" }
    ],
    "notificaciones_sin_leer": 0,
    "tiene_asignaciones_hoy": true,
    "aviso_disponibilidad_enviado_hoy": false
  }
}
```

Si el trabajador no tiene asignaciones del día:

```json
{
  "ok": true,
  "data": {
    "usuario": { "...": "..." },
    "hotel_actual": { "...": "..." },
    "tiene_asignaciones_hoy": false,
    "aviso_disponibilidad_enviado_hoy": false,
    "notificaciones_sin_leer": 0
  }
}
```

---

## 13. Estados de carga y error

### 13.1 Estado de carga inicial

Mientras se carga la data del backend, mostrar un spinner centrado:

```html
<div class="min-h-screen flex items-center justify-center">
  <div class="flex flex-col items-center gap-3">
    <svg class="animate-spin h-8 w-8 text-blue-600" ...></svg>
    <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
  </div>
</div>
```

### 13.2 Error al cargar

Si el endpoint falla (red, servidor caído, lo que sea), mostrar:

```html
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="text-center max-w-xs">
    <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar tu día</h2>
    <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión a internet e intenta de nuevo.</p>
    <button onclick="location.reload()" class="min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
      Reintentar
    </button>
  </div>
</div>
```

### 13.3 Sin internet (offline)

Si el navegador detecta que no hay conexión, mostrar un banner persistente arriba (debajo del header):

```html
<div class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
  Sin conexión a internet. Tus cambios se sincronizarán cuando vuelva.
</div>
```

---

## 14. Refresco de datos

### 14.1 Refresco automático

La pantalla debe refrescar la data cada **2 minutos** mientras esté visible, para que el trabajador vea cambios si la supervisora le asigna o reasigna habitaciones desde su panel.

Implementación con Alpine.js:
```html
<div x-data="{
  data: null,
  intervalId: null,
  cargar() {
    fetch('/api/home/trabajador')
      .then(r => r.json())
      .then(json => this.data = json.data);
  }
}"
  x-init="
    cargar();
    intervalId = setInterval(() => cargar(), 120000);
  "
  x-destroy="clearInterval(intervalId)">
  <!-- Contenido -->
</div>
```

### 14.2 Pull-to-refresh (móvil)

Si el navegador soporta pull-to-refresh nativo, dejarlo funcionar normalmente. NO implementar uno custom para el MVP.

### 14.3 Refresco al volver del checklist

Cuando el trabajador completa una habitación en el checklist y vuelve a la Home, la data debe estar fresca. Esto puede implementarse:
- Detectando el evento `visibilitychange` y recargando si la pestaña vuelve a estar visible
- O simplemente recargando la data al navegar de vuelta a `/`

---

## 15. Comportamientos críticos a no olvidar

Esta lista es un checklist final para que Claude Code valide al terminar la pantalla:

- [ ] **NO se muestran números de habitaciones pendientes/completadas** en la barra de progreso
- [ ] **NO se muestra cronómetro** ni tiempo transcurrido al trabajador
- [ ] **El badge de habitaciones `aprobada_con_observacion` dice "Aprobada"**, exactamente igual que `aprobada` — el trabajador no debe distinguirlas
- [ ] **El trabajador NO ve habitaciones de otros trabajadores** — el endpoint del backend solo devuelve las suyas
- [ ] **Botón principal dinámico**: "Comenzar limpieza" si nunca tocó la habitación, "Continuar" si ya la tocó
- [ ] **El estado vacío** (sin asignaciones) tiene el botón "Avisar que estoy disponible" funcionando
- [ ] **Después de avisar disponibilidad**, el botón se deshabilita por el resto del día
- [ ] **El header es sticky** y no se mueve al hacer scroll
- [ ] **El bottom tab bar es fixed** y siempre visible en móvil
- [ ] **El FAB del copilot está por encima del bottom bar** y siempre visible
- [ ] **Padding inferior suficiente** para que el contenido no quede tapado por el bottom bar
- [ ] **Modo día/noche** funciona en todos los elementos sin "flash" al cargar
- [ ] **Refresco automático cada 2 minutos**
- [ ] **Estados de carga y error** implementados
- [ ] **Áreas tappables mínimo 44x44px**
- [ ] **Tipografía legible** (mínimo 14px en secundario, 16px en principal)

---

## 16. Vinculación con otros módulos

Esta pantalla **depende** de los siguientes módulos/documentos:

- `docs/auth.md` — para la autenticación y obtener el usuario logueado
- `docs/checklist.md` — para la pantalla a la que navega cuando se toca el botón principal o una fila de habitación
- `docs/copilot-ia.md` — para el panel que se abre con el FAB
- `docs/ajustes.md` — para la pantalla a la que navega desde la tab Ajustes
- `docs/cloudbeds.md` — indirectamente, porque las habitaciones vienen de la sincronización con Cloudbeds
- `docs/asignacion.md` — para entender cómo se llena la cola del trabajador

Esta pantalla **NO depende** del módulo de:
- Auditoría (eso lo gestiona Recepción y Supervisora)
- Alertas predictivas (el trabajador no las ve)
- Gestión de usuarios (eso es Admin)

---

## 17. Notas finales para Claude Code

### 17.1 Modo de codificación
Este módulo es de **supervisión por módulo**: propón los archivos que vas a crear/modificar antes de hacerlos, espera aprobación de Nicolás, y NO commitees nada hasta que él te lo diga explícitamente.

### 17.2 Archivos sugeridos a crear

- `src/Controllers/HomeController.php` — método `trabajador()` que arma la data
- `src/Services/Home/TrabajadorHomeService.php` — lógica de negocio para armar la home del trabajador
- `src/Views/home/trabajador.php` — la vista PHP nativa con HTML + Tailwind + Alpine
- `src/Views/layouts/app.php` — layout base con header, bottom nav, FAB (si no existe ya)
- `src/Views/partials/badge_estado.php` — componente reusable del badge de estado
- `src/Views/partials/avatar_usuario.php` — componente reusable del avatar
- `public/index.php` — agregar la ruta `GET /` que apunta a `HomeController::trabajador()` (con detección automática del rol después)

### 17.3 Tests sugeridos

- Test del cálculo de "habitación actual" en distintos escenarios (con/sin progreso, con/sin pendientes, etc.)
- Test del cálculo de los porcentajes de la barra de progreso
- Test del estado vacío (sin asignaciones)
- Test del estado "día completado"

### 17.4 Si encuentras algo no especificado

Sigue los **defaults razonables** del `CLAUDE.md` raíz y deja un comentario `// DECISIÓN AUTÓNOMA: ...` para que Nicolás lo revise. Casos típicos donde puede pasar:

- Color exacto del avatar según hash del RUT (cualquier algoritmo razonable sirve)
- Animación del cambio de estado de un badge cuando cambia el estado
- Comportamiento exacto del refresco automático cuando la pestaña no está visible

---

*Fin de la especificación de Home del Trabajador de Limpieza v1.0. Próxima pantalla a especificar: Home de la Supervisora.*
