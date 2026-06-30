# Plan de test visual — "¿funcionan todos los botones?"

> **Objetivo:** recorrer la app y confirmar que cada botón/acción hace lo que
> debe, sin tocar código. Pensado para correr sobre el entorno Docker local
> (http://localhost:8090) con datos demo ya cargados. Última actualización: 2026-06-30.

## Setup

1. App arriba: `docker compose up -d` → http://localhost:8090
2. Datos demo cargados (seed.php + seed-demo-data.php). Si no: ver
   `docs/migracion-mariadb-cpanel.md`.
3. Usuarios de prueba (uno por rol — cada rol ve pantallas distintas):

   | Rol | RUT | Contraseña |
   |---|---|---|
   | Admin | `11111111-1` | `Admin2026!` |
   | Supervisora (Paola) | `15234567-4` | `Demo1234!` |
   | Recepción (Daniela) | `16789012-1` | `Demo1234!` |
   | Trabajadora (Valentina) | `18502341-9` | `Demo1234!` |

4. Probar en **móvil** (DevTools → 375px) y **desktop** — el layout cambia
   (bottom-nav vs sidebar). Probá ambos al menos en el Home.

## Qué significa "el botón funciona"

Clasificá cada control en uno de estos 4 tipos y verificá lo esperado:

| Tipo | Esperado al hacer clic |
|---|---|
| **Navegación** | Cambia de pantalla / URL correcta, sin recargar en blanco |
| **Modal** | Abre el modal correcto; cierra con X / "Cancelar" / fuera |
| **Acción backend** | Dispara la request, muestra feedback (éxito/error), y el cambio se refleja al instante o al refrescar |
| **Toggle/UI** | Cambia estado visual (tema día/noche, ítem de checklist, ver/ocultar contraseña, selector de hotel) |

**Señales de "NO funciona":** clic sin efecto, error en consola (F12), spinner
infinito, modal que no cierra, respuesta 500 en Network, o el cambio no persiste.

## Checklist por pantalla y rol

### 0. Comunes a todos los roles
- [ ] **Login:** iniciar sesión (credenciales OK → entra), credenciales malas → error amable, toggle ver/ocultar contraseña
- [ ] **Cambio de contraseña forzado** (probar con un usuario con clave temporal)
- [ ] **Bottom-nav (móvil) / sidebar (desktop):** cada ítem navega a su sección
- [ ] **Toggle día/noche** (persiste al refrescar — localStorage)
- [ ] **Badge de notificaciones:** abre el popup; marcar como leído baja el contador
- [ ] **Selector de hotel** (donde aplique): cambia los datos mostrados
- [ ] **Cerrar sesión** → vuelve a /login
- [ ] *(FAB copilot está oculto por `COPILOT_HABILITADO=false` — no debe aparecer)*

### 1. Trabajadora (Valentina)
- [ ] **Home trabajador:** lista de habitaciones asignadas; tap en una → detalle
- [ ] **Detalle de habitación / checklist:**
  - [ ] Iniciar / "Continuar" una habitación
  - [ ] Marcar y desmarcar ítems (cada tap persiste — refrescá y siguen marcados)
  - [ ] "Habitación terminada" se **desbloquea solo** con todos los ítems marcados
  - [ ] Completar → la habitación sale de pendientes
- [ ] **Avisar disponibilidad** (botón "estoy disponible")
- [ ] **Crear ticket** (modal): abre, valida, envía

### 2. Supervisora (Paola)
- [ ] **Home supervisora:** progreso del equipo, cards de trabajadoras, alertas
- [ ] **Asignaciones:** asignar manual, **auto-asignar**, reasignar, reordenar cola (drag)
- [ ] **Auditoría — bandeja:** entrar a una habitación pendiente
- [ ] **Auditoría — 3 botones:** aprobar / aprobar con observación / rechazar
  - [ ] Una habitación **ya auditada** aparece solo-lectura (badge "Auditada", sin botones)
- [ ] **Habitaciones:** ver todas, filtros, ver historial
- [ ] **Tickets:** crear, cambiar estado, asignar
- [ ] **KPIs operativas** (si visibles en su Home)

### 3. Recepción (Daniela)
- [ ] **Home recepción:** vista del día
- [ ] **Tickets:** crear / ver
- [ ] **Habitaciones:** ver estado
- [ ] Acciones propias del rol (según permisos de Recepción)

### 4. Admin (acceso total)
- [ ] **Home admin**
- [ ] **Usuarios:** crear (modal), abrir detalle (modal), activar/desactivar, resetear contraseña, asignar/quitar rol
- [ ] **Ajustes → Roles y Permisos (RBAC):** togglear permisos de la matriz, crear rol, editar rol (modales) — verificar que persiste
- [ ] **Ajustes → Turnos:** editor de turnos (modal), crear/editar
- [ ] **Ajustes → Importar turnos:** wizard de 3 pasos (subir CSV → match RUTs → confirmar)
- [ ] **Ajustes → Alertas:** configurar umbral de margen (guarda)
- [ ] **Ajustes → Mi cuenta:** cambiar contraseña, suscribir push
- [ ] **Reportes:** filtros, ver KPIs, **exportar CSV/Excel** (descarga archivo)
- [ ] **Cloudbeds (Ajustes):** ver estado de sync, configurar credenciales — *NO* forzar
      sincronización real hasta tener el plan de pruebas seguras (ver
      `docs/cloudbeds-pruebas-seguras.md`)

## Dos formas de ejecutarlo

### A) Manual (lo "visual")
Seguí el checklist de arriba logueándote con cada rol. Marcá cada casilla. Anotá
abajo cualquier botón que falle (pantalla + botón + qué pasó).

### B) Automatizado (acelerador, opcional)
Con Playwright (MCP ya disponible) se puede recorrer cada pantalla, hacer clic en
cada botón y reportar automáticamente:
- errores de consola JavaScript,
- respuestas HTTP 500 en la red,
- botones "muertos" (clic sin efecto),
- modales que no abren/cierran.

Esto NO reemplaza el ojo humano para el layout, pero atrapa fallos que la vista se
pierde. Útil como primer barrido antes del recorrido manual.

## Resultados del barrido automatizado (Playwright, 2026-06-30)

**Salud de navegación — 4 roles × 23 cargas de pantalla:** ✅ TODO VERDE
- Todas las pantallas cargaron con su URL correcta (sin redirección inesperada a `/login`).
- **0 errores de consola JavaScript**, **0 respuestas HTTP ≥400** (ningún endpoint de datos roto), **0 fallos de navegación**, en todos los roles.
- Elementos interactivos por pantalla: 23–95.

**Interacción (clics seguros, sin enviar ni borrar):** ✅
- Filtro de tickets "Abiertos": funciona (lista 6 → 3 ítems).
- Botones de crear hallados con label correcto: "Nuevo usuario", "Nuevo rol", "Nuevo turno".
- Modal "Nuevo usuario" **confirmado abriendo** (campos visibles 1 → 4: RUT, Nombre, Roles) — screenshot `test-visual-modal-nuevo-usuario.png`.
- Alta de ticket: alcanzable vía botón "+" (icono) que dispara `abrir-modal-ticket` (`views/tickets.php:37,122`).

**NO cubierto por el barrido automático (verificar a ojo / clic dirigido):**
botones destructivos o de escritura — auditoría (3 botones), "Habitación terminada",
activar/desactivar usuario, reset password, exportar reportes, guardar matriz RBAC,
forzar sync Cloudbeds — además del drag-reorder de asignaciones (confirmar
manualmente). *El toggle día/noche quedó resuelto: ahora es un botón fijo en el
header de todas las pantallas — ver la tabla de hallazgos.*

## Resultados de los clics dirigidos — RBAC + Usuarios (Playwright, 2026-06-30)

Recorrido como **Admin** contra Docker `:8090`. **0 errores de consola JS** (solo el 404
de `favicon.ico`, inofensivo).

**A. Matriz RBAC (`/ajustes/rbac`):** ✅
- Toggle de un permiso (`reportes.ver` en rol *Recepción*) → aparece el banner
  "1 cambio sin guardar" con el checkbox resaltado.
- "Guardar cambios" → `PUT /api/roles/3` → 200 OK; toast de éxito.
- **Persistencia confirmada tras refresco completo de la página** (la matriz recarga
  del servidor y el permiso quedó marcado).
- Revertido (toggle off + guardar) → matriz queda como estaba (**net cero**).

**B. Usuarios — activar/desactivar (`/usuarios`):** ✅
- Sobre un usuario descartable creado para la prueba ("ZZ Test Descartable", id 17).
- "Desactivar usuario" → `confirm()` correcto → `POST /api/usuarios/17/desactivar` →
  200 OK → `activo=false`.
- "Activar usuario" → `POST /api/usuarios/17/activar` → 200 OK → `activo=true` (**net cero**).

**C. Usuarios — resetear contraseña:** ✅
- "Resetear contraseña" → `confirm()` → `POST /api/auth/reset-temporal` → 200 OK →
  el panel muestra la contraseña temporal nueva + botón copiar
  (screenshot `test-reset-password-descartable.png`).

**Limpieza:** el usuario descartable se eliminó vía `DELETE /api/usuarios/17` (soft-delete
anonimizante → "Usuario eliminado #17", inactivo, fuera de los listados).

**Crear usuario (`POST /api/usuarios`):** ✅ el alta funciona (201 + contraseña temporal),
**pero ver el bug en la tabla de hallazgos — el rol seleccionado no se persiste.**

## Registro de hallazgos

| Pantalla | Botón/acción | Rol | Qué pasó | Severidad |
|---|---|---|---|---|
| /tickets | "Nuevo ticket" | admin | OK — es botón con ícono "+" (no texto); abre modal por evento | Nota (no defecto) |
| (toggle día/noche) | tema | todos | **RESUELTO (commit `0781a6c`):** el detector no lo ubicaba porque vivía **solo** en Ajustes → Mi cuenta (engorroso). Se agregó un **botón día/noche al extremo derecho del header en las 18 pantallas** (componente `views/componentes/boton-tema.php` + `window.toggleTema()` en `app.js`; ícono luna/sol por CSS). Verificado con Playwright (clic real): alterna la clase `dark`, persiste en `localStorage('tema')` y sobrevive a la recarga. De paso se corrigió el **Service Worker** que servía `app.js` rancio (Cache First sin revalidar → el botón "no hacía nada" en clientes instalados): `CACHE_VERSION` a v2, precache/revalidación con `cache:'reload'` y `cacheFirst` ahora hace stale-while-revalidate real. | **Verificado + mejorado** |
| /usuarios | "Crear usuario" (modal Nuevo usuario) | admin | **BUG (CORREGIDO):** el usuario se creaba **sin el rol seleccionado**. El modal enviaba `roles` como **nombres** (`["Trabajador"]`); el backend (`UsuarioService::crear`) hace `(int) $rolId` esperando **IDs** → `(int)"Trabajador"` = 0 → `INSERT IGNORE` lo descartaba → "Sin roles asignados". **Fix (frontend):** `modal-usuario-nuevo.php` ahora mapea los nombres seleccionados a sus IDs antes de enviar. **Endurecimiento (backend):** `UsuarioService::crear` ahora valida que cada ID de rol exista (`ROL_NO_ENCONTRADO` 404) antes de tocar la BD, en vez de descartar en silencio — `roles` sigue opcional. **Verificado en MariaDB:** alta con rol válido → `roles: ["Trabajador"]` persistido (201); rol inexistente → 404 sin crear nada. Test `testCrearConRolInexistenteLanzaYNoCreaUsuario`; suite 201/201. | **Alta → Corregido + endurecido** |
| /ajustes/rbac | toggle permiso + "Guardar cambios" | admin | OK — `PUT /api/roles/{id}` 200, persiste tras refresco | Verificado |
| /usuarios | activar / desactivar / reset password | admin | OK — 200 en los 3 endpoints, feedback y `confirm()` correctos | Verificado |
