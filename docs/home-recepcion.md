# Home de Recepción — Especificación detallada

**Módulo:** Home / Dashboard
**Rol destinatario:** Recepción
**Versión:** 1.0
**Fecha:** 14 de abril de 2026
**Estado:** ✅ Aprobado para codificación
**Modo de codificación de este módulo:** Supervisión por módulo (UI crítica para el MVP)

> Esta es la **especificación ejecutable** que Claude Code debe seguir al codificar la Home de Recepción. Léela completa antes de empezar. Cualquier decisión no especificada aquí debe seguir los **defaults razonables** del `CLAUDE.md` raíz, marcando con comentarios `// DECISIÓN AUTÓNOMA: ...`.

---

## 1. Contexto y propósito

### 1.1 Quién usa esta pantalla

Personal de recepción del hotel. Perfil tipo: una recepcionista sentada en el mostrador que necesita auditar habitaciones que el trabajador de limpieza marcó como completadas. Abre la app pocas veces al día, pero cuando la abre, necesita una acción inmediata.

### 1.2 Qué responde esta pantalla

Una sola pregunta: **"¿Cuáles son las habitaciones que necesito auditar ahora?"**

**Contexto de uso (no negociable):** Recepción usa **Cloudbeds como herramienta principal** para consultar estado de habitaciones, disponibilidad, check-ins y check-outs. Esta app se usa específicamente para auditar habitaciones completadas en sus tiempos libres — **no reemplaza a Cloudbeds**. Por eso la Home tiene foco en auditoría y no en estado general de habitaciones, ni incluye buscador ni filtros de disponibilidad. Refresco de 5 minutos (no 60s) porque Recepción no vive en esta app; la abre puntualmente cuando tiene un momento para auditar.

### 1.3 Filosofía UX clave — auditoría rápida

La Home de Recepción es una **bandeja visual de auditoría pendiente**, optimizada para:
- Ver de un vistazo qué hay para auditar
- Tocar una habitación → auditar inmediatamente
- Marcar como aprobada/rechazada → desaparece del grid
- Alineación con KPI: habitaciones auditadas por turno/día

**No hay métricas ocultas, no hay presión innecesaria.** Recepción ve exactamente lo que necesita hacer.

### 1.4 Dispositivo principal

**Mobile-first** (celular vertical, 375px base). Aunque Recepción probablemente usa la app detrás de un mostrador en tablet o PC, el MVP está optimizado para móvil. Escalado a tablet/desktop automático con grid adaptable.

---

## 2. Permisos requeridos

Esta pantalla es visible para usuarios que tengan **al menos uno** de los siguientes permisos:

- `auditoria.ver_bandeja` — ve la lista de pendientes
- `auditoria.aprobar` — puede dar veredicto "aprobada"
- `auditoria.aprobar_con_observacion` — puede dar veredicto "aprobada con observación"
- `auditoria.rechazar` — puede dar veredicto "rechazada"

**Si no tiene ninguno:** redirección a página genérica de "sin acceso".

**Permisos adicionales (dinámicos):**
- `auditoria.editar_checklist_durante_auditoria` — puede desmarcar items del checklist
- `habitaciones.ver_todas` — ver estado de habitaciones como contexto durante la auditoría (definido en plan.md §5.4.4 como permiso por defecto de Recepción). No habilita una sección principal de "estado de habitaciones" en esta Home — Recepción usa Cloudbeds para esa consulta general. Se usa solo dentro del detalle de auditoría para contexto.

Los 3 botones de auditoría se muestran u ocultan dinámicamente según los permisos específicos del usuario.

---

## 3. Layout general

La pantalla se divide en secciones principales, de arriba a abajo, en una sola columna en móvil:

```
┌───────────────────────────────┐
│  SECCIÓN 1 — Header           │
│  (saludo, nombre, hotel)      │
├───────────────────────────────┤
│                               │
│  SECCIÓN 2 — Grid de          │
│  habitaciones pendientes      │
│  (adaptable: 2/3/4 columnas)  │
│                               │
│                               │
├───────────────────────────────┤
│                    [FAB IA]   │  ← Flotante
│  BOTTOM TAB BAR               │
│  Inicio | Ajustes             │
└───────────────────────────────┘
```

En tablet/desktop, el grid se expande automáticamente a 3-4 columnas sin cambiar la estructura del layout.

---

## 4. Sección 1 — Header

### 4.1 Layout

Header fijo en la parte superior, ocupa todo el ancho. Altura aproximada: 64-72px (tres líneas de texto).

```
┌──────────────────────────────────────────┐
│  Buenos días                       [🔄]   │
│  María Pérez                             │
│  ATAN (selector)                         │
└──────────────────────────────────────────┘
```

### 4.2 Elementos de izquierda a derecha

**4.2.1 Bloque de saludo y nombre (izquierda)**

Línea 1 — Saludo contextual
- Texto: `{saludo}`
- `saludo` se calcula según hora local actual:
  - `00:00 - 11:59` → "Buenos días"
  - `12:00 - 18:59` → "Buenas tardes"
  - `19:00 - 23:59` → "Buenas noches"
- Tipografía: `text-base font-semibold text-gray-900 dark:text-gray-100`

Línea 2 — Nombre del usuario
- Texto: nombre completo del usuario
- Tipografía: `text-lg font-semibold text-gray-900 dark:text-gray-100`

Línea 3 — Hotel selector
- Texto: nombre del hotel seleccionado (ATAN, INN, o "Ambos")
- Tappable: abre selector de hotel
- Opciones: "ATAN", "INN", **"Ambos hoteles"**
- Cuando selecciona "Ambos": grid muestra habitaciones de ambos con prefijo (ATAN-302, INN-305)
- Tipografía: `text-sm text-gray-600 dark:text-gray-400`

**4.2.2 Botón de refresco (derecha)**

- Icono Lucide: `rotate-cw` o similar
- Tamaño: `w-6 h-6`
- Botón circular tappable: `min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800`
- Funcionalidad: al tocar, refresca inmediatamente la lista de habitaciones pendientes
- Mostrar estado de carga (spinner) mientras se refresca

### 4.3 Estilo del header

```html
<header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
  <!-- Saludo + nombre + selector (izquierda) -->
  <!-- Botón refresco (derecha) -->
</header>
```

**Sticky:** el header se queda fijo arriba al hacer scroll.

---

## 5. Sección 2 — Grid de habitaciones pendientes

### 5.1 Propósito

Mostrar visualmente, en formato grid, TODAS las habitaciones que están pendientes de auditar (completadas por trabajador pero no revisadas aún). Es la bandeja de auditoría.

### 5.2 Contenido

Solo habitaciones con estado = "completada_pendiente_auditoria" (nombre del estado a confirmar con schema).

**NO aparecen:**
- Habitaciones ya auditadas (aprobadas o rechazadas)
- Habitaciones en limpieza
- Habitaciones pendientes de asignación
- Habitaciones ocupadas o fuera de servicio

### 5.3 Grid adaptable

**Estructura:**
- Móvil (< 768px): **2 columnas**
- Tablet (768px - 1023px): **3 columnas**
- Desktop (≥ 1024px): **4 columnas**

Espacio entre columnas: 12px
Espacio entre filas: 12px
Padding del contenedor: 12px

```css
/* Pseudocódigo */
display: grid;
grid-template-columns: repeat(auto-fill, minmax(target-width, 1fr));
gap: 12px;
padding: 12px;
```

### 5.4 Cada tarjeta del grid

```
┌──────────────┐
│   302        │  ← Si es un hotel
└──────────────┘

┌──────────────┐
│ ATAN-302     │  ← Si son ambos hoteles
└──────────────┘
```

**Elementos:**
- Contenedor: background `bg-white dark:bg-gray-800`, border `border-2 border-gray-300 dark:border-gray-600`, border-radius `rounded-lg`
- Contenido: solo el número de habitación (con prefijo hotel si aplica)
- Tipografía: `text-2xl font-bold text-gray-900 dark:text-gray-100`
- Padding: `p-4`
- Altura mínima: `h-20` (para que sea cuadrada aproximadamente)
- Alineación: centrado verticalmente y horizontalmente

**Interacción:**
- Tappable: `cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 active:ring-2 active:ring-blue-500`
- Al tocar → abre pantalla de auditoría directamente
- Sin pasos intermedios

### 5.5 Prefijo de hotel (cuando selecciona "Ambos")

Formato:
- "ATAN-302" para Atankalama (1 Sur)
- "INN-305" para Atankalama Inn

Separador: guion `-`

---

## 6. Estados especiales

### 6.1 Sin habitaciones pendientes

Si no hay nada para auditar:

```html
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="text-center max-w-xs">
    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No hay habitaciones</p>
    <p class="text-gray-600 dark:text-gray-400">Pendientes de auditar</p>
  </div>
</div>
```

Mensajes simples, sin celebración ("✅ todo listo") porque Recepción sigue trabajando.

### 6.2 Error al cargar

Si el endpoint falla:

```html
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="text-center max-w-xs">
    <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar las habitaciones</h2>
    <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión a internet e intenta de nuevo.</p>
    <button onclick="location.reload()" class="min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
      Reintentar
    </button>
  </div>
</div>
```

### 6.3 Sin internet (offline)

Banner persistente arriba (debajo del header):

```html
<div class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
  Sin conexión a internet. Los cambios se sincronizarán cuando vuelva.
</div>
```

---

## 7. Bottom Tab Bar

La Home de Recepción tiene 2 tabs de navegación:

- **Inicio** — muestra el grid de habitaciones pendientes (pantalla actual)
- **Ajustes** — navega a la pantalla de ajustes/perfil del usuario

No hay tab separada de "Auditoría" — la auditoría se abre al tocar una habitación desde el grid.

**Estilo:**
- Fixed en la parte inferior
- Altura: ~60px
- Fondo: `bg-white dark:bg-gray-800`
- Borde superior: `border-t border-gray-200 dark:border-gray-700`
- Cada tab: área tappable ≥ 44x44px, con icono + etiqueta (móvil) o solo icono (desktop)

---

## 8. FAB — Copilot IA

El FAB flotante del copilot está siempre visible en la esquina inferior derecha, por encima del bottom tab bar.

Icono: Lucide **`sparkles`** (estandarizado en las 4 Homes — transmite "asistente IA")
Tamaño: `w-14 h-14`
Posición: `bottom-20 right-4` en móvil, `md:bottom-6 md:right-6` en desktop
Comportamiento: al tocar, abre panel deslizable del copilot (definido en `docs/copilot-ia.md`)

---

## 9. Refresco de datos

### 9.1 Refresco automático

La pantalla debe refrescar la data cada **5 minutos** mientras esté visible, para que Recepción vea nuevas habitaciones completadas que entran a la bandeja.

Implementación con Alpine.js:
```html
<div x-data="{
  data: null,
  intervalId: null,
  cargar() {
    fetch('/api/home/recepcion')
      .then(r => r.json())
      .then(json => this.data = json.data);
  }
}"
  x-init="
    cargar();
    intervalId = setInterval(() => cargar(), 300000);
  "
  x-destroy="clearInterval(intervalId)">
  <!-- Contenido -->
</div>
```

### 9.2 Pull-to-refresh (móvil)

Si el navegador soporta pull-to-refresh nativo, dejarlo funcionar normalmente.

### 9.3 Botón de refresco manual

Botón 🔄 fijo en el header (esquina superior derecha). Al tocar:
- Dispara refresco inmediato
- Muestra spinner mientras carga
- Actualiza el grid

### 9.4 Refresco post-auditoría

Cuando Recepción completa una auditoría (toca uno de los 3 botones), la Home se refresca automáticamente. La habitación auditada desaparece del grid inmediatamente.

---

## 10. Flujo de auditoría

### 10.1 Acceso

Recepción toca una habitación en el grid → abre la pantalla de auditoría directamente.

La pantalla de auditoría es compartida con la Supervisora (misma lógica, mismos 3 botones).

### 10.2 Los 3 botones de auditoría

1. **✅ Aprobar** — habitación está impecable, pasa a "aprobada", vuelve a Cloudbeds como "Clean"
2. **⚠️ Aprobar con observación** — encontró algo menor, lo resolvió, deja constancia. Expande checklist, auditor desmarca items específicos
3. **❌ Rechazar** — necesita re-limpieza, vuelve a "sucia" en Cloudbeds, supervisora recibe notificación

### 10.3 Inmutabilidad post-auditoría

Una vez que Recepción (o la Supervisora) audita una habitación, nadie más puede re-auditarla. Backend: `POST /api/auditoria/{habitacion_id}` responde `409 Conflict` si ya existe registro en `auditorias` para esa ejecución.

**Cómo se ve en la UI de Recepción:**

- La tarjeta de la habitación aparece con **opacidad 50%** (clase `opacity-50`).
- Badge azul **"Auditada"** arriba a la derecha (reemplaza el badge "Pendiente").
- **Sin los 3 botones de acción** (aprobar / observación / rechazar). La zona donde vivían queda en blanco o muestra el resumen (ver abajo).
- Muestra un resumen compacto debajo del nombre de la habitación: `✅ Aprobada por Javiera · 10:42` / `⚠️ Observación por Javiera · 10:42` / `❌ Rechazada por Javiera · 10:42`.
- **Tap en la tarjeta** → abre modal/pantalla de detalle histórico con:
  - Auditor (nombre y avatar)
  - Fecha y hora exacta
  - Resultado (aprobado / aprobado con observación / rechazado)
  - Comentario del auditor (si lo hay)
  - Ítems desmarcados del checklist (si fue "aprobado con observación" o "rechazado")
  - Trabajador que limpió y hora de completación
- **No hay botón para re-abrir auditoría** en ningún caso.

Patrón idéntico al definido en `docs/home-supervisora.md` §9 — las 4 Homes comparten esta convención visual para habitaciones ya auditadas.

---

## 11. Selector de hotel

### 11.1 Comportamiento

El selector en el header permite que Recepción elija:
- **"ATAN"** → ve solo habitaciones de Atankalama (1 Sur)
- **"INN"** → ve solo habitaciones de Atankalama Inn
- **"Ambos hoteles"** → ve habitaciones de ambos con prefijo (ATAN-302, INN-305)

### 11.2 Persistencia

La selección del hotel se guarda en localStorage, para que cuando Recepción vuelva a abrir la app, vea el hotel que estaba consultando.

### 11.3 Nota futura (Fase 2)

Cuando la aplicación evolucione y se asigne personal específico por edificio/hotel, se puede reemplazar este selector por asignación automática. Por ahora es manual.

---

## 12. Datos del backend (endpoint)

### 12.1 Endpoint

**`GET /api/home/recepcion`**

Retorna la data necesaria para renderizar la Home de Recepción.

### 12.2 Request

Query parameters (opcionales):
- `hotel` — filtrar por hotel (valores: "ATAN", "INN", o no pasar para "ambos")

### 12.3 Response

```json
{
  "ok": true,
  "data": {
    "usuario": {
      "id": 5,
      "nombre": "María Pérez",
      "rut": "12.345.678-9"
    },
    "hotel_seleccionado": "ATAN",
    "habitaciones_pendientes": [
      {
        "id": 102,
        "numero": "302",
        "hotel": "ATAN",
        "estado": "completada_pendiente_auditoria",
        "completada_por_trabajador": "María Salinas",
        "hora_completada": "2026-04-14T10:35:00Z"
      },
      {
        "id": 105,
        "numero": "305",
        "hotel": "INN",
        "estado": "completada_pendiente_auditoria",
        "completada_por_trabajador": "Carla Rojas",
        "hora_completada": "2026-04-14T10:48:00Z"
      },
      {
        "id": 112,
        "numero": "312",
        "hotel": "ATAN",
        "estado": "completada_pendiente_auditoria",
        "completada_por_trabajador": "María Salinas",
        "hora_completada": "2026-04-14T11:02:00Z"
      }
    ],
    "total_pendientes": 3,
    "permisos": {
      "auditoria_ver_bandeja": true,
      "auditoria_aprobar": true,
      "auditoria_aprobar_con_observacion": true,
      "auditoria_rechazar": true,
      "auditoria_editar_checklist": true
    }
  }
}
```

Si hay error (no hay acceso, servidor error):

```json
{
  "ok": false,
  "error": "No tienes permisos para acceder a auditoría",
  "code": "UNAUTHORIZED"
}
```

### 12.4 HTTP Status Codes

- **200 OK** — respuesta exitosa
- **401 Unauthorized** — usuario no autenticado
- **403 Forbidden** — no tiene permisos
- **500 Server Error** — error del servidor

---

## 13. Estados de carga y error

### 13.1 Estado de carga inicial

Mientras se carga la data:

```html
<div class="min-h-screen flex items-center justify-center">
  <div class="flex flex-col items-center gap-3">
    <svg class="animate-spin h-8 w-8 text-blue-600" ...></svg>
    <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
  </div>
</div>
```

### 13.2 Error al cargar

(Ver sección 6.2)

### 13.3 Sin internet

(Ver sección 6.3)

---

## 14. Modo día/noche

Aplica igual que en las otras Homes:

- Clases `dark:` en todos los elementos
- Persistencia de preferencia en localStorage
- Sin flash al cargar (verificar preferencia antes de renderizar)
- Toggle en Ajustes

---

## 15. Accesibilidad

### 15.1 Requisitos

- Áreas tappables: mínimo **44x44px**
- Tipografía legible: mínimo **14px** en textos secundarios, **16px** en principales
- Contraste: **WCAG AA** mínimo
- `aria-label` en botones con solo icono
- Foco visible (outline o ring)
- Orden de tabulación lógico

### 15.2 Validación de densidad

Con muchas habitaciones (20-40 en un hotel grande), verificar que el grid no queda demasiado denso. Tarjetas de 80-100px de lado mínimo para que sean fáciles de tocar.

---

## 16. Comportamientos críticos — Checklist

Esta lista es un checklist final para que Claude Code valide al terminar la pantalla:

- [ ] Grid solo muestra habitaciones PENDIENTES de auditar
- [ ] Grid es adaptable: 2 cols móvil, 3 cols tablet, 4 cols desktop
- [ ] Cada tarjeta muestra SOLO número (con prefijo hotel si es "ambos")
- [ ] Toque en habitación → abre auditoría directamente (sin pasos intermedios)
- [ ] Auditoría tiene 3 botones: Aprobar / Aprobar con observación / Rechazar
- [ ] Post-auditoría, refresco inmediato (habitación desaparece del grid)
- [ ] Refresco automático cada 5 minutos
- [ ] Pull-to-refresh funciona en móvil
- [ ] Botón 🔄 en header funciona
- [ ] Estado vacío: "No hay habitaciones pendientes de auditar"
- [ ] Estado error: icono + mensaje + botón Reintentar
- [ ] Banner offline si no hay conexión
- [ ] Bottom tab bar: Inicio | Ajustes
- [ ] FAB Copilot siempre visible por encima del tab bar
- [ ] Selector hotel en header (uno o ambos)
- [ ] Botones de auditoría ocultos dinámicamente según permisos del usuario
- [ ] Modo día/noche funciona sin flash
- [ ] Áreas tappables ≥ 44x44px
- [ ] Contraste WCAG AA
- [ ] Header sticky
- [ ] Inmutabilidad post-auditoría respetada (habitaciones auditadas son solo lectura)
- [ ] Permisos dinámicos: secciones se ocultan si no tiene permisos

---

## 17. Vinculación con otros módulos

Esta pantalla **depende** de los siguientes módulos/documentos:

- `docs/auth.md` — autenticación y usuario logueado
- `docs/auditoria.md` — especificación completa del flujo de auditoría (compartido con Supervisora)
- `docs/habitaciones.md` — estados de habitaciones
- `docs/cloudbeds.md` — sincronización de datos
- `docs/copilot-ia.md` — FAB del copilot IA
- `docs/rbac-dinamico.md` — permisos dinámicos por usuario

Esta pantalla **comparte flujo con:**

- `docs/home-supervisora.md` — auditoría con 3 botones idénticos, inmutabilidad post-auditoría, lógica de permisos

Esta pantalla **NO depende** de:

- `docs/asignacion.md` — Recepción no asigna habitaciones
- `docs/alertas-predictivas.md` — solo Supervisora ve alertas
- `docs/tickets.md` — Recepción no crea tickets en MVP

---

## 18. Notas finales para Claude Code

### 18.1 Modo de codificación

Este módulo es de **supervisión por módulo**:
- Propón los archivos que vas a crear/modificar antes de hacerlos
- Espera aprobación de Nicolás
- NO commites nada hasta que lo diga explícitamente

### 18.2 Archivos sugeridos a crear

```
src/Controllers/HomeController.php
  → método recepcion()

src/Services/Home/RecepcionHomeService.php
  → lógica de obtener habitaciones pendientes

src/Views/home/recepcion.php
  → vista principal con HTML + Tailwind + Alpine

src/Views/layouts/app-recepcion.php
  → layout base con header sticky, bottom tab bar, FAB

src/Views/auditoria/detalle-recepcion.php
  → pantalla de auditoría (checklist + 3 botones)

src/Views/partials/
  - habitacion-card.php (tarjeta del grid)
  - bottom-tab-bar.php
  - selector-hotel.php

public/js/home-recepcion.js
  → lógica de refresco automático, botón manual, eventos del grid

public/js/auditoria-recepcion.js
  → lógica de auditoría, 3 botones
```

### 18.3 Tests sugeridos

- Test del cálculo de habitaciones pendientes por hotel
- Test del filtrado cuando selecciona "un hotel" vs "ambos hoteles"
- Test del refresco automático cada 5 minutos
- Test del refresco inmediato post-auditoría
- Test de permisos dinámicos (botones se ocultan según permisos)
- Test del shape del endpoint `GET /api/home/recepcion`
- Test del estado vacío (sin pendientes)
- Test del estado error
- Test del grid adaptable (2/3/4 columnas)
- Test del selector de hotel y persistencia en localStorage

### 18.4 Reutilización de componentes

La auditoría de Recepción reutiliza lógica ya definida en `docs/home-supervisora.md`:
- Checklist desmarcable (para "Aprobar con observación")
- Lógica de los 3 botones
- Inmutabilidad post-auditoría
- Flujo de permisos dinámicos para botones

Evitar duplicación de código. Extraer a servicios compartidos si es necesario.

### 18.5 Si encuentras algo no especificado

Sigue los **defaults razonables** del `CLAUDE.md` raíz y deja un comentario `// DECISIÓN AUTÓNOMA: ...`. Casos típicos donde puede pasar:

- Animación exacta del spinner de refresco
- Color del border del grid (gris vs azul suave)
- Easing de transiciones al abrir auditoría
- Exacto timing de desaparición de tarjeta post-auditoría (inmediato vs fade out)

---

## 19. Anotaciones pendientes para futuro

Estos temas NO entran en el MVP pero deben resolverse en sesiones separadas:

**📌 Asignación de personal por edificio/hotel (Fase 2)**
- Cuando se defina que cada recepcionista trabaja en un hotel específico
- Reemplazar selector manual por asignación automática
- Permisos específicos por hotel

**📌 Reportes y KPIs de auditoría (Fase 2)**
- Definir KPIs del rol Recepción (habitaciones auditadas por turno, tasa de rechazo, etc.)
- Dashboards de desempeño
- Cómo impacta `aprobada_con_observacion` en evaluación

**📌 Integración con módulo de Mantenimiento (Fase 2)**
- Recepción podría reportar mantenimiento necesario durante auditoría
- Tickets emergentes desde la auditoría
- Coordinación con Supervisora

**📌 Sidebar colapsable (Fase 2)**
- Cuando pasemos a versiones mayores de desktop
- Mejor aprovechamiento de espacio horizontal
- Acceso a otros módulos (reportes, KPIs)

---

## 20. Resumen de decisiones principales

| Decisión | Valor |
|----------|-------|
| **Contenido principal** | Bandeja de auditoría (grid de pendientes) |
| **Hoteles** | Selector: uno o ambos (default: todos) |
| **Grid** | Adaptable: 2 cols móvil, 3 tablet, 4 desktop |
| **Info por habitación** | Solo número (con prefijo si ambos hoteles) |
| **Acceso auditoría** | Toque directamente (sin pasos intermedios) |
| **Botones auditoría** | 3: Aprobar / Aprobar con observación / Rechazar |
| **Refresco** | Automático cada 5 min + manual 🔄 + post-auditoría |
| **Bottom tabs** | Inicio \| Ajustes |
| **FAB Copilot** | Siempre visible |
| **Permisos** | Dinámicos (botones se ocultan según permisos) |
| **Modo día/noche** | Sí, persistente |
| **Mobile-first** | Sí |

---

*Fin de la especificación de Home de Recepción v1.0. Aprobado para codificación con Claude Code.*

*Documento generado el 14 de abril de 2026 basado en decisiones de diseño colaborativas.*
