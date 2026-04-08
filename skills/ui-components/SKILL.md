# UI Components — Atankalama Limpieza

## Cuándo usar esta skill

Cuando escribas HTML/Tailwind para cualquier vista. Garantiza consistencia con el "Chat Interno" del hotel.

## Filosofía

- **Mobile-first siempre** — base 375px, escala con `sm:`, `md:`, `lg:`
- **PHP nativo + Tailwind CDN + Alpine CDN** — sin build step
- **Botones grandes** (`min-h-[44px]`)
- **Tappable areas generosas**
- **Modo día/noche** con `dark:`
- **Transiciones suaves** pero discretas
- **No generar ansiedad** — mostrar progreso sin presión numérica donde aplique

## Imports base (layout maestro)

```html
<!-- Tailwind -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<!-- Lucide icons -->
<script src="https://unpkg.com/lucide@latest"></script>

<!-- Inter font -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

## Paleta de colores (provisional, ajustar con la captura del Chat Interno)

- **Azul primario:** `bg-blue-600`, hover `bg-blue-700`
- **Verde éxito:** `bg-green-500`
- **Rojo urgente:** `bg-red-500`
- **Amarillo pendiente:** `bg-yellow-400`
- **Fondo claro:** `bg-gray-50`, `dark:bg-gray-900`
- **Tarjetas:** `bg-white`, `dark:bg-gray-800`
- **Bordes:** `border-gray-200`, `dark:border-gray-700`
- **Texto principal:** `text-gray-900`, `dark:text-gray-100`
- **Texto secundario:** `text-gray-500`, `dark:text-gray-400`

## Componentes base

### Botón primario
```html
<button class="min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
  Texto del botón
</button>
```

### Badge de estado
```html
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
  Pendiente
</span>
```

### Tarjeta
```html
<div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
  ...
</div>
```

### Bottom nav móvil (visible solo en móvil)
```html
<nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 md:hidden">
  <!-- Tabs -->
</nav>
```

### Sidebar desktop (visible desde md hacia arriba)
```html
<aside class="hidden md:block w-64 bg-gray-900 text-white">
  <!-- Menu -->
</aside>
```

### FAB del copilot IA
```html
<button
  x-data
  @click="$dispatch('open-copilot')"
  class="fixed bottom-20 right-4 md:bottom-6 md:right-6 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center transition z-50"
>
  <i data-lucide="sparkles" class="w-6 h-6"></i>
</button>
```

### Barra de progreso con segmentos (sin texto numérico)
```html
<div class="w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
  <div class="bg-green-500 h-full" style="width: 66%"></div>
  <div class="bg-blue-500 h-full" style="width: 11%"></div>
  <!-- El resto queda gris -->
</div>
```

### Toggle modo día/noche (Alpine)
```html
<button
  x-data="{
    dark: localStorage.getItem('theme') === 'dark',
    toggle() {
      this.dark = !this.dark;
      document.documentElement.classList.toggle('dark', this.dark);
      localStorage.setItem('theme', this.dark ? 'dark' : 'light');
    }
  }"
  x-init="document.documentElement.classList.toggle('dark', dark)"
  @click="toggle()"
  class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
>
  <i x-show="!dark" data-lucide="moon"></i>
  <i x-show="dark" data-lucide="sun"></i>
</button>
```

## Reglas

- Siempre incluir variantes `dark:` para colores
- Siempre `min-h-[44px]` en elementos tappables
- Siempre `transition` en hovers
- Nunca usar `style="..."` inline — todo con clases Tailwind (excepción: anchos dinámicos de barras de progreso)
- Iconos solo de Lucide
- Para interactividad simple, Alpine.js (`x-data`, `x-show`, `x-on`)
- **NO usar jQuery, React, Vue ni otro framework**
