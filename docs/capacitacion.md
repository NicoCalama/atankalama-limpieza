# Programa de capacitación — App de Limpieza Atankalama

Documento del **programa de capacitación** con el que se le enseña la app de limpieza al
equipo de las dos propiedades de Atankalama. No describe un módulo de código: describe el
**material y el plan de dictado**. El entregable vivo es un **artifact web interactivo**
(guía del facilitador con checklist por navegador y pantallazos reales de la app).

> Este programa **NO** se porta al LMS de la empresa ("University"). University es para
> contenido teórico + evaluación certificable (tipo control de alimentos, seguridad, legal).
> El recorrido práctico de la app se dicta **en vivo, función por función**, y esa guía es
> este artifact. Decisión tomada el 29/07/2026; no re-proponer portarlo salvo pedido explícito.

## Modalidad de dictado

- **Formato:** videollamada de **30–40 min cada dos semanas** (el facilitador está en otro país).
- **Audiencia directa:** la **supervisora** (aprende) con el **jefe de área como observador**.
- **Cascada:** la supervisora, una vez que domina cada función, la baja a los **encargados de limpieza**.
- **Ritmo:** una función/tema por reunión. La **Sesión 1 arranca liviano** (la app ya se usa a
  diario): primero se afina el sistema y se hace un **repaso general por arriba** del día a día;
  cada tema se profundiza después en su propia sesión.
- **Apoyo:** la guía es interactiva (checklist con persistencia en `localStorage`, modo día/noche)
  y, por ser la supervisora muy visual, cada punto lleva un **pantallazo real de la app**
  (clic → se expande en modo lightbox).

## El entregable (artifact)

- **URL (privado en claude.ai):** https://claude.ai/code/artifact/4c7bdcf5-8032-468c-b725-a5ef92bf232f
- **Fuente en el repo:** [`docs/capacitacion/programa-capacitacion.html`](capacitacion/programa-capacitacion.html)
  — HTML autocontenido (~2,2 MB) con todos los pantallazos incrustados como data-URI JPEG.
- **Cómo re-editar y re-publicar (importante para no perder el URL):** editar el HTML fuente y
  volver a publicarlo con la herramienta Artifact. Para **conservar el mismo URL** hay que pasarle
  el parámetro `url` con el enlace guardado (el de arriba). Reusar el mismo `file_path` solo
  mantiene el URL dentro de la **misma** conversación; en una sesión futura, publicar sin `url`
  acuña un URL **nuevo** (y este archivo del repo tiene un `file_path` distinto al que se publicó
  originalmente, así que sin `url` daría un link nuevo sí o sí y se orfanaría el ya entregado). Si
  se perdió el enlace, se ubica con la herramienta Artifact en `action:"list"`. El HTML se publica
  como *body-only* (sin `<!doctype>`, `<head>` ni `<body>` propios).

### Estructura del HTML (para mantenimiento)

Todo el contenido se renderiza desde arrays de datos en JS (no hay HTML de sesiones escrito a mano):

- `const __SHOTS__` — objeto `clave → data-URI` con **45 pantallazos** (JPEG).
- `PREP` — los 8 puntos de preparación del sistema (Sesión 1).
- `REPASO` — los 5 puntos del repaso del día a día (Sesión 1).
- `ARR` — objetivo y metas de la Sesión 1 (Arranque).
- `BLOQUES` — los 3 bloques (rango de sesiones `from`/`to`).
- `S` — las sesiones 2 a 11 (cada una con `funcs`). En los Bloques A y B (S2–S9) cada función
  lleva su captura resaltada `hl`; las de S10–S11 (Bloque C) no llevan `hl` y van como lista de chips.

## Sesión 1 — Arranque

Arranca liviano: primero se deja el sistema afinado y después un repaso general **por arriba**
de lo que se hace todos los días. Cada tema se profundiza en su sesión.

### Preparación del sistema (checklist de 8 puntos)

1. Las dos propiedades cargadas y el inventario sincronizado con Cloudbeds.
2. Cuentas creadas para todo el equipo (supervisor, recepción y cada encargado) con su rol.
3. RUT de cada persona cargado y clave temporal entregada.
4. Turnos del equipo cargados en Ajustes → Turnos (catálogo + semana).
5. Checklists por tipo de habitación revisados con la empresa.
6. Celulares/tablets con la PWA instalada y notificaciones activadas por dispositivo.
7. Un par de habitaciones y usuarios de **prueba** para practicar sin tocar datos reales ni Cloudbeds.
8. Definido quién es el **admin** que crea usuarios y resetea claves.

> El punto "Roles y permisos" se sacó a propósito de la preparación: es tarea del admin, no de la supervisora.

### Repaso del día a día (5 temas, con enlace "a fondo → S#")

| Tema | Dónde | A fondo |
|---|---|---|
| Asignar el trabajo del día | Asignaciones | S2 |
| Limpiar con el checklist | Trabajador → "Comenzar limpieza" | S8 |
| Revisar / auditar | Auditoría | S3 |
| Tickets de mantención | Tickets · "Reportar problema" | S9 |
| Mirar el estado del día | Inicio de supervisora · Habitaciones | S4 |

## Bloques y sesiones de profundización (2 a 11)

El orden pone primero a la **supervisora** y después al **trabajador**; la configuración avanzada al final.

- **Bloque A — La supervisora al mando** (S2–S5): repartir el trabajo, controlar la calidad y leer el estado del equipo.
- **Bloque B — Lo esencial del trabajador** (S6–S9): lo que el equipo usa a diario; la supervisora lo domina para acompañar a cada encargado.
- **Bloque C — Configuración (avanzado)** (S10–S11): personas, permisos y ajustes de la app. Rol admin.

En los Bloques A y B, cada función se muestra con su **pantallazo real y la función resaltada**
con recuadro (para la parte visual de la supervisora).

### Detalle por sesión

| # | Sesión | Dur. | Rol | Funciones que se profundizan |
|---|---|---|---|---|
| 2 | Asignar habitaciones | 35 min | Supervisora | Asignar a mano · Auto-asignar (round-robin) · Reasignar y desasignar · Ver y filtrar el listado |
| 3 | Auditoría de calidad (3 estados) | 40 min | Supervisora | Bandeja de auditoría · Aprobar (camino feliz) · Aprobar con observación · Rechazar · Detalle histórico (inmutabilidad) |
| 4 | Alertas y tablero de la supervisora | 35 min | Supervisora | Tablero (Home supervisora) · Alertas urgentes y sus acciones · Ver carga / Reasignar · Panel de notificaciones (campana) · Config de alertas (umbrales) |
| 5 | Reportes y desempeño | 35 min | Supervisora · Admin | Pantalla de Reportes (KPIs) · Créditos por ítem y re-limpieza · Resúmenes mensuales |
| 6 | Entrar y moverse | 30 min | Trabajador | Iniciar sesión · Recuperar contraseña · Cambiar contraseña (1er ingreso) · Moverse, modo día/noche y salir |
| 7 | Mi día: la cola de habitaciones | 30 min | Trabajador | Home del trabajador · Comenzar / Continuar · Sin asignaciones y reportar |
| 8 | El checklist digital | 40 min | Trabajador | Comenzar limpieza · Marcar el checklist · Habitación terminada · No puedo terminar esta ahora |
| 9 | Casos del día a día | 40 min | Trabajador · Supervisora | Áreas comunes (espacios) · Volver a limpiar (misma pieza) · Ocupación y sábanas · Tickets de mantenimiento |
| 10 | Usuarios, roles y permisos (RBAC) | 35 min | Admin | Gestionar usuarios · Matriz de roles y permisos |
| 11 | Ajustes que vas a tocar | 40 min | Admin | Entrar a Ajustes · Turnos (catálogo + semana) · Checklists por tipo · Colores y Versiones |

> La **audiencia siempre es la supervisora** (con el jefe de área observando); la columna **Rol**
> indica de qué rol son las funciones que se enseñan, no quién asiste. Trabajador y Admin aprenden
> por cascada, no asistiendo a la videollamada.

Cada sesión del artifact incluye, además de las funciones: **objetivo**, **qué se muestra**,
**reglas** y **dudas frecuentes** por función, más un bloque **"vas a lograr"** y **"antes de la próxima"**.
Los conceptos no negociables de la app aparecen reflejados: auditoría inmutable de 3 estados,
tiempos del trabajador siempre ocultos, alertas que el trabajador nunca ve, checklist que se
guarda a cada tap y funciona sin señal, y RBAC por permisos (nunca "si es admin").

## Mantenimiento de los pantallazos

Los pantallazos son de la app real (no mockups). Técnica reutilizable para regenerarlos:

1. Levantar la app: `php -S 127.0.0.1:8000 -t public/` (con la dev DB `database/atankalama.db`).
2. Montar un escenario del día en la dev DB (turnos + asignaciones + ticket por SQL) para que las
   capturas muestren datos reales; **revertir la DB al terminar**.
3. Capturar con **Playwright MCP** (`browser_navigate` + `browser_take_screenshot`). El panel
   embebido de Claude no sirve para capturas (no compositea).
4. Para **resaltar una función**: inyectar un `<div id="__hl__">` fijo con borde naranja
   (`#ff5a2e`) sobre el `getBoundingClientRect()` del elemento (vía `browser_evaluate`) y recién
   ahí capturar.
5. Incrustar como data-URI JPEG (GD: crop + resize ~1000 px + calidad ~70) dentro de `__SHOTS__`.

> ⚠️ **Nunca** inyectar/reemplazar bloques del HTML con `preg_replace`/`preg_replace_callback`
> usando cuantificadores (`.*?`): sobre un string de varios MB revienta el `pcre.backtrack_limit`
> (default 1.000.000), devuelve `NULL`, y `file_put_contents(NULL)` deja el archivo en 0 bytes.
> Usar `str_replace` (sin regex). Esta lección salió de haber borrado el archivo una vez.

## Estado y pendientes ofrecidos

- **Hecho:** las 11 sesiones, bloques reordenados (supervisora primero), 45 pantallazos reales,
  32 pantallazos de función en los Bloques A y B (31 con la función resaltada con recuadro; el de
  "Volver a limpiar" reutiliza una captura plana), lightbox en todas las imágenes.
- **Ofrecido, sin decidir:** versión PDF imprimible, versión en modo noche fija, y ajustar
  sesiones (reordenar/combinar/sumar recepción).
