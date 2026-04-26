# Skill: /debug — Diagnóstico de bugs en Atankalama Limpieza

## Cuándo usar esta skill

Cuando el usuario reporte un bug, error, comportamiento inesperado o cualquier cosa que "no funcione" en el proyecto Atankalama Limpieza. Invoca este skill antes de tocar cualquier código.

---

## Protocolo general de diagnóstico

Antes de proponer cualquier hipótesis, sigue este orden:

1. **Pregunta diagnóstica inicial** — ¿Qué síntoma exacto ves? ¿HTTP status? ¿Mensaje de error? ¿Comportamiento en pantalla?
2. **Lee los logs primero** — `storage/logs/` es la primera parada siempre.
3. **Formula hipótesis ordenadas** — de más probable a menos probable según el síntoma.
4. **Propón el comando o cambio de código exacto** para resolver cada hipótesis.

Nunca propongas "puede ser X o Y, prueba los dos" sin leer primero los logs.

---

## 1. Errores PHP / HTTP

### Leer los logs

Los logs **se escriben a SQLite**, no a archivos JSONL. Hay dos tablas:

- `logs_eventos` — eventos técnicos (INFO, WARNING, ERROR) con módulo + contexto JSON
- `audit_log` — acciones de usuario (login, cambios, etc.) con detalles JSON

`storage/logs/fallback.log` solo se escribe cuando el INSERT a `logs_eventos` falla (ej. FK violation por `usuario_id` inexistente). Si tiene contenido reciente, hay un problema con el logging mismo.

Leer los últimos registros vía PHP CLI:

```bash
# Ultimas 20 entradas del log
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
foreach (\$db->query('SELECT created_at, nivel, modulo, mensaje FROM logs_eventos ORDER BY id DESC LIMIT 20') as \$r) {
    echo \"[{\$r['created_at']}] [{\$r['nivel']}] [{\$r['modulo']}] {\$r['mensaje']}\n\";
}
"

# Solo errores
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
foreach (\$db->query(\"SELECT created_at, modulo, mensaje, contexto_json FROM logs_eventos WHERE nivel='ERROR' ORDER BY id DESC LIMIT 20\") as \$r) {
    echo \"[{\$r['created_at']}] [{\$r['modulo']}] {\$r['mensaje']} | {\$r['contexto_json']}\n\";
}
"

# Filtrar por módulo
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
\$stmt = \$db->prepare('SELECT created_at, nivel, mensaje FROM logs_eventos WHERE modulo = ? ORDER BY id DESC LIMIT 20');
\$stmt->execute(['auth']);
foreach (\$stmt as \$r) { echo \"[{\$r['created_at']}] [{\$r['nivel']}] {\$r['mensaje']}\n\"; }
"
```

Cada fila tiene: `id`, `created_at` (ISO 8601 UTC), `nivel`, `modulo`, `mensaje`, `contexto_json`, `usuario_id`.

### Si `fallback.log` tiene entradas

Indica que el logger no pudo escribir a `logs_eventos`. Causas comunes:
- FK constraint en `logs_eventos.usuario_id` (usuario referenciado no existe)
- `database is locked` por otro proceso
- Permisos de escritura en el archivo `database/atankalama.db`

El `db_error` adjunto en cada línea de `fallback.log` indica la causa exacta.

### Identificar el tipo de error por HTTP status

| Status | Causa probable | Dónde buscar |
|--------|---------------|--------------|
| **500** | Excepción no capturada | Log con `"level":"ERROR"` + stack trace en `contexto` |
| **400** | Validación fallida | Log con `"level":"WARNING"` y `"modulo":"validacion"` |
| **401** | Sin sesión o token expirado | Tabla `sesiones` — ver sección 2 |
| **403** | Permiso insuficiente | Tabla `rol_permisos` — ver sección 2 |
| **404** | Ruta no registrada | `src/Core/Kernel.php` — ver abajo |

### Verificar que la ruta existe

El router se construye en `src/Core/Kernel.php`, método `construirRouter()`. El flujo completo es:

```
public/index.php
  → App::run()
    → Kernel::construirRouter()
      → middleware chain (auth → permission → rate-limit)
        → Controller::metodo()
```

Si el error es 404, buscar si la ruta está registrada en `Kernel.php`:

```bash
grep -n "'/api/tu-ruta'" src/Core/Kernel.php
```

Si no aparece, hay que registrarla. Si aparece pero sigue fallando, verificar que el middleware de auth no está rechazando antes de llegar al controller.

### Variables de entorno

Se cargan con `vlucas/phpdotenv` desde `.env` al inicio. Si una variable no se encuentra:

```bash
# Verificar que la variable existe en .env (sin mostrar el valor)
grep "^NOMBRE_VARIABLE=" .env | cut -d'=' -f1
```

Comparar contra `.env.example` para detectar variables faltantes.

---

## 2. Autenticación y sesiones

### Flujo de sesión

La autenticación usa una cookie `session_token` HTTPOnly. Para diagnosticar:

1. **Verificar la cookie en el navegador** — DevTools → Application → Cookies → buscar `session_token`
2. Si no existe la cookie: el usuario no está logueado, debe hacer login
3. Si existe pero hay 401: el token expiró o fue invalidado

### Consultar la tabla sesiones

```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
\$stmt = \$db->prepare('SELECT s.*, u.rut, u.nombre FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id WHERE s.token = ? LIMIT 1');
\$stmt->execute(['\$TOKEN_AQUI']);
print_r(\$stmt->fetch(PDO::FETCH_ASSOC));
"
```

Verificar que:
- El registro existe
- `fecha_expiracion` es futura
- `activa = 1`

### Forzar cambio de contraseña

Si el usuario es redirigido siempre a `/cambiar-contrasena`, verificar:

```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
\$stmt = \$db->prepare('SELECT id, rut, nombre, requiere_cambio_pwd FROM usuarios WHERE rut = ?');
\$stmt->execute(['\$RUT_AQUI']);
print_r(\$stmt->fetch(PDO::FETCH_ASSOC));
"
```

Si `requiere_cambio_pwd = 1`, el middleware redirige antes de permitir cualquier otra página. Es el comportamiento correcto para usuarios nuevos.

### Diagnóstico de permisos

`tienePermiso()` hace un JOIN sobre `usuarios_roles` + `rol_permisos`. Si un usuario no tiene acceso a algo que debería:

```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
# Ver roles del usuario
\$stmt = \$db->prepare('SELECT r.nombre, r.codigo FROM roles r JOIN usuarios_roles ur ON ur.rol_id = r.id WHERE ur.usuario_id = ?');
\$stmt->execute([\$USUARIO_ID]);
print_r(\$stmt->fetchAll(PDO::FETCH_ASSOC));
"
```

```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
# Ver permisos del rol
\$stmt = \$db->prepare('SELECT p.codigo FROM permisos p JOIN rol_permisos rp ON rp.permiso_id = p.id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = ?');
\$stmt->execute(['\$CODIGO_ROL']);
print_r(\$stmt->fetchAll(PDO::FETCH_ASSOC));
"
```

Si el permiso no aparece, agregarlo en la tabla `rol_permisos` o revisar `database/seeds/permisos.php`.

---

## 3. Base de datos SQLite

### Ubicación del archivo

Por defecto: `database/atankalama.db`. Configurable vía `DB_PATH` en `.env`.

```bash
# Verificar que el archivo existe y tiene tamaño > 0
ls -lh database/atankalama.db
```

### Errores comunes y solución

| Error SQLite | Causa | Solución |
|-------------|-------|----------|
| `UNIQUE constraint failed: usuarios.rut` | RUT duplicado | Buscar el usuario existente y decidir qué hacer |
| `FOREIGN KEY constraint failed` | El registro padre no existe | Verificar que el ID referenciado existe en la tabla padre |
| `no such table: nombre_tabla` | Falta ejecutar migración | Correr `php scripts/init-db.php` |
| `database is locked` | Otro proceso tiene el archivo abierto | Cerrar otras conexiones; reiniciar servidor de desarrollo |
| `attempt to write a readonly database` | Permisos de archivo | `chmod 664 database/atankalama.db` |

### Inspeccionar la BD directamente

```bash
# Modo interactivo sqlite3 (si disponible)
sqlite3 database/atankalama.db

# O desde PHP
php -r "
require 'vendor/autoload.php';
\$db = new PDO('sqlite:database/atankalama.db');
\$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
# Listar tablas
\$tablas = \$db->query('SELECT name FROM sqlite_master WHERE type=\"table\" ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
print_r(\$tablas);
"
```

### Recrear la BD desde cero

```bash
php scripts/init-db.php
```

**Advertencia:** borra todos los datos. Solo usar en desarrollo.

---

## 4. Frontend Alpine.js

### `x-cloak` — elementos que parpadean al cargar

Si un elemento aparece brevemente visible antes de ocultarse, falta el CSS de `x-cloak`. Verificar en el HTML base (normalmente `views/layouts/base.php`):

```html
<style>[x-cloak] { display: none !important; }</style>
```

Debe estar en el `<head>`, antes de que cargue Alpine.

### `$store.notif` — badge de notificaciones no se actualiza

El store se inicializa en el evento `alpine:init`. Si el badge no refleja el conteo correcto:

1. Verificar en DevTools → Console que no hay errores de JS al cargar
2. Verificar el orden de carga: el script que define `Alpine.store('notif', ...)` debe ejecutarse **antes** del `<script src="alpinejs CDN">` si se usa `defer`, o el `alpine:init` listener debe estar antes del script de Alpine

```javascript
// Orden correcto
document.addEventListener('alpine:init', () => {
    Alpine.store('notif', { count: 0, items: [] });
});
// Luego el CDN de Alpine
```

3. Si el fetch falla, revisar en Network tab que `/api/notificaciones` devuelve 200 con datos.

### Lucide icons no aparecen después de actualizar el DOM

Después de cualquier operación que agregue nuevos elementos con iconos Lucide al DOM, llamar:

```javascript
// Dentro de un $nextTick para esperar que Alpine actualice el DOM
this.$nextTick(() => lucide.createIcons());
```

Si los iconos aparecen en la carga inicial pero no después de un fetch/render dinámico, este es el problema.

### Eventos globales no funcionan

Si `$dispatch('toggle-notif')` no tiene efecto, verificar que el listener usa el modificador `.window`:

```html
<!-- Correcto -->
<div @toggle-notif.window="abierto = !abierto">

<!-- Incorrecto — solo escucha eventos del elemento hijo directo -->
<div @toggle-notif="abierto = !abierto">
```

---

## 5. Push Notifications (Web Push / VAPID)

### Checklist de diagnóstico — las notificaciones no llegan

Seguir este orden:

**Paso 1 — Verificar variables de entorno VAPID**
```bash
# Solo verificar que existen, no mostrar valores
grep "^VAPID_" .env | cut -d'=' -f1
```
Deben existir `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY`. Si faltan, generarlos:
```bash
php -r "
require 'vendor/autoload.php';
\$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
echo 'VAPID_PUBLIC_KEY=' . \$keys['publicKey'] . PHP_EOL;
echo 'VAPID_PRIVATE_KEY=' . \$keys['privateKey'] . PHP_EOL;
"
```

**Paso 2 — Verificar que el Service Worker está registrado**

En la consola del navegador:
```javascript
navigator.serviceWorker.ready.then(r => console.log('SW listo:', r.scope));
```
Si no hay respuesta o lanza error, el SW no está registrado.

**Paso 3 — Verificar suscripción en BD**
```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
\$stmt = \$db->prepare('SELECT id, usuario_id, creado_en FROM push_subscriptions WHERE usuario_id = ?');
\$stmt->execute([\$USUARIO_ID]);
print_r(\$stmt->fetchAll(PDO::FETCH_ASSOC));
"
```
Si no hay filas, el usuario nunca aceptó los permisos de notificación o el endpoint de suscripción falló.

**Paso 4 — Endpoints caídos (410 Gone)**

Los endpoints caídos se limpian automáticamente en `PushService` cuando el proveedor devuelve 410. Si una suscripción fue limpiada, el usuario debe volver a suscribirse.

**Paso 5 — Filtro de turno activo**

Si las notificaciones llegan en algunos turnos pero no en otros, revisar `PushService::filtrarPorTurnoActivo()` en `src/Services/PushService.php`. El filtro puede estar excluyendo al usuario si no hay un turno activo registrado para él.

---

## 6. Email (PHPMailer / SMTP)

### Los emails no llegan

**Paso 1 — Verificar configuración SMTP**
```bash
grep "^SMTP_" .env | cut -d'=' -f1
```
Deben existir: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`.

Si `SMTP_HOST` está vacío, el sistema no intentará enviar. Es el comportamiento esperado en desarrollo sin SMTP configurado.

**Paso 2 — Gmail requiere App Password**

Si usas Gmail como SMTP, `SMTP_PASS` debe ser una **App Password** de 16 caracteres (Cuenta Google → Seguridad → Verificación en 2 pasos → Contraseñas de aplicación), NO la contraseña normal de la cuenta.

**Paso 3 — Revisar logs**

Los errores SMTP se logean como WARNING en `logs_eventos` (no interrumpen la operación principal):
```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
foreach (\$db->query(\"SELECT created_at, mensaje, contexto_json FROM logs_eventos WHERE modulo='email' ORDER BY id DESC LIMIT 20\") as \$r) {
    echo \"[{\$r['created_at']}] {\$r['mensaje']} | {\$r['contexto_json']}\n\";
}
"
```

Los errores de PHPMailer aparecen en el campo `error` del JSON de `contexto_json`.

**Paso 4 — Prueba manual de envío**

```bash
php -r "
require 'vendor/autoload.php';
\$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
\$dotenv->load();
// Instanciar NotificacionEmailService y llamar enviarTest()
"
```

---

## 7. Importador de turnos (Breik CSV)

### Los RUTs del CSV no matchean con la BD

El problema más común: el CSV de Breik incluye los puntos del RUT (`19.867.090-1`) pero la BD los almacena sin puntos (`19867090-1`).

Verificar cómo está el método de normalización:

```bash
grep -n "normalizarRut" src/Services/TurnosImportService.php
```

Debe implementar:
```php
private function normalizarRut(string $rut): string
{
    return strtoupper(str_replace('.', '', trim($rut)));
}
```

Si el CSV tiene otro formato (espacios, guion al revés, etc.), actualizar la normalización.

### El archivo CSV ya no está disponible en la sesión

El archivo se almacena temporalmente en sesión PHP. Si el usuario tardó mucho o la sesión expiró:

1. Verificar que `session_start()` se llama al inicio del controller del importador
2. Verificar `session.gc_maxlifetime` en `php.ini` (default 1440 segundos = 24 min)
3. Solución: pedir al usuario que re-suba el archivo

### La importación falla silenciosamente

Revisar logs buscando el módulo del importador:
```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
foreach (\$db->query(\"SELECT created_at, nivel, mensaje, contexto_json FROM logs_eventos WHERE modulo='turnos_import' ORDER BY id DESC LIMIT 30\") as \$r) {
    echo \"[{\$r['created_at']}] [{\$r['nivel']}] {\$r['mensaje']} | {\$r['contexto_json']}\n\";
}
"
```

---

## 8. Cloudbeds API

### Checklist de diagnóstico

**Timeout (default 10s)**

Si las llamadas a Cloudbeds son lentas o fallan por timeout:
```bash
grep "^CLOUDBEDS_TIMEOUT_SECONDS" .env
```
Si no existe, el default es 10. Para aumentarlo temporalmente en debugging: `CLOUDBEDS_TIMEOUT_SECONDS=30`.

**401 Unauthorized**

Credenciales inválidas. Verificar que las API keys del hotel correcto están en `.env`:
```bash
grep "^CLOUDBEDS_API_KEY_" .env | cut -d'=' -f1
```
Deben existir `CLOUDBEDS_API_KEY_INN` y `CLOUDBEDS_API_KEY_PRINCIPAL`. Nunca mezclar las keys entre propiedades.

**Historial de sincronización**

Cada operación a Cloudbeds queda registrada en `cloudbeds_sync_historial` con timestamp, payload, respuesta y resultado. Ver últimos eventos fallidos:
```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
\$stmt = \$db->query(\"SELECT created_at, accion, exito, mensaje_error FROM cloudbeds_sync_historial WHERE exito = 0 ORDER BY id DESC LIMIT 20\");
print_r(\$stmt->fetchAll(PDO::FETCH_ASSOC));
"
```

**Revisar logs de escritura a Cloudbeds**

Toda operación de escritura a Cloudbeds se registra en `logs_eventos`:
```bash
php -r "
\$db = new PDO('sqlite:database/atankalama.db');
foreach (\$db->query(\"SELECT created_at, nivel, mensaje, contexto_json FROM logs_eventos WHERE modulo='cloudbeds' ORDER BY id DESC LIMIT 20\") as \$r) {
    echo \"[{\$r['created_at']}] [{\$r['nivel']}] {\$r['mensaje']} | {\$r['contexto_json']}\n\";
}
"
```

---

## 9. PWA / Service Worker

### Cache desactualizado — los cambios no se reflejan

En Chrome/Edge DevTools:
- Application → Service Workers → marcar "Update on reload"
- O: Application → Storage → "Clear site data"

Para forzar actualización desde código, incrementar la versión del cache en `public/sw.js`:
```javascript
const CACHE_NAME = 'atankalama-v2'; // incrementar el número
```

### El Service Worker no se registra

1. Verificar que `public/sw.js` existe:
   ```bash
   ls -la public/sw.js
   ```
2. El SW solo se registra desde HTTPS o `localhost`. En desarrollo con `php -S localhost:8000` funciona correctamente.
3. Revisar la consola del navegador — errores de sintaxis en `sw.js` impiden el registro.

### Assets faltantes en precaché

El SW cachea `/, /login, /home` y sus assets. Si hay un error de red al cargar un recurso cacheado, revisar la lista en `public/sw.js`:
```bash
grep -A 20 "urlsToCache\|precache" public/sw.js
```

Si falta un asset crítico, agregarlo a la lista de precaché y actualizar la versión del cache.

---

## Hipótesis ordenadas por síntoma — referencia rápida

| Síntoma | Hipótesis 1 (más probable) | Hipótesis 2 | Hipótesis 3 |
|---------|---------------------------|-------------|-------------|
| Pantalla en blanco | Error 500 — leer log de hoy | Ruta no registrada en Kernel.php | JS bloqueado (ver consola) |
| "No tienes permiso" inesperado | Permiso no asignado al rol | Rol no asignado al usuario | Token de sesión expirado |
| Datos no se guardan | Error 400/422 — log de validación | FOREIGN KEY constraint en BD | Permiso de escritura insuficiente |
| Notificaciones push no llegan | VAPID keys faltantes en .env | Usuario no tiene suscripción en BD | Filtro de turno bloqueando |
| Emails no llegan | SMTP_HOST vacío | Gmail necesita App Password | Error SMTP en logs (WARNING) |
| Icons no aparecen | `lucide.createIcons()` falta en render dinámico | CDN de Lucide no carga | Nombre de icono incorrecto |
| Badge de notif no actualiza | Error en fetch `/api/notificaciones` | `alpine:init` después de CDN Alpine | `$store` no inicializado |
| CSV import no matchea RUTs | Puntos en RUT del CSV (`19.123.456-7`) | Espacios o caracteres extra | `normalizarRut()` no aplicado |

---

## Reglas de esta skill

- SIEMPRE leer logs antes de proponer hipótesis
- NUNCA loggear ni mostrar valores de API keys, tokens ni passwords al diagnosticar
- SIEMPRE terminar con el comando o cambio de código exacto para resolver
- Si el problema no está en esta guía, revisar `docs/<modulo>.md` del módulo afectado
