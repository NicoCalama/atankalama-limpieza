# Seguridad y RGPD — Atankalama Limpieza

## Cuándo usar esta skill

Cuando el usuario invoca `/seguridad-rgpd`. Ejecuta una revisión completa de seguridad (OWASP Top 10 adaptado al stack) y cumplimiento de protección de datos (Ley 19.628 Chile + principios GDPR) del proyecto Atankalama Limpieza.

Usa Grep y Read activamente sobre los archivos reales del proyecto. **Nunca asumas el estado del código sin verificarlo.** Si un archivo no existe donde se espera, indícalo explícitamente como hallazgo.

---

## Stack de referencia

- PHP 8.4 + SQLite (PDO), sin ORM
- Tailwind CSS + Alpine.js via CDN
- Web Push API con VAPID (`minishlink/web-push ^10.0`)
- PHPMailer (`phpmailer/phpmailer ^7.0`)
- Claude API (Anthropic) para el copilot conversacional
- Caddy como servidor web / reverse proxy
- Datos personales: RUT, nombre, email de trabajadores chilenos

---

## PARTE 1 — SEGURIDAD (OWASP Top 10)

### A01 — Control de Acceso

**Verificación 1.1 — Cobertura de PermissionCheck en Kernel**

Lee `src/Core/Kernel.php` completo. Para cada ruta registrada, verifica:

- Rutas que leen datos sensibles (GET de usuarios, reportes, auditorías, alertas, copilot) deben tener `$authCheck` + `new PermissionCheck(...)`.
- Rutas de escritura (POST/PUT/DELETE) deben tener `$authCheck` + `new PermissionCheck(...)`.
- Excepciones legítimas: `POST /api/auth/login` (público por diseño), `GET /api/health` (uptime monitor, público por diseño).
- Rutas de páginas HTML usan `$optionalAuth` — esto es correcto (la auth real se valida en los endpoints de datos).

Casos a revisar especialmente:
- `POST /api/auditoria/{id}` — debe tener `$authCheck`. El permiso granular (`auditoria.aprobar`, etc.) se valida _dentro_ del controller según el veredicto: leer `src/Controllers/AuditoriaController.php` método `emitirVeredicto()` y verificar que llama `$request->usuario->tienePermiso($permisoRequerido)` antes de actuar.
- `GET /api/habitaciones/{id}` — tiene solo `$authCheck` sin PermissionCheck: evaluar si esto expone datos sensibles a cualquier usuario autenticado.
- `GET /api/ejecuciones/{id}` y `PUT /api/ejecuciones/{id}/items/{itemId}` — solo `$authCheck`: evaluar si un trabajador puede acceder a ejecuciones de otros trabajadores (riesgo de IDOR).
- `DELETE /api/copilot/conversaciones/{id}` — solo `$authCheck`: verificar que `CopilotService::borrarConversacion()` valida que `usuario_id` de la conversación coincida con el usuario autenticado.

```
Read: src/Core/Kernel.php — mapa completo de rutas y middlewares
Read: src/Controllers/AuditoriaController.php — validación granular de veredicto
Read: src/Services/Copilot/CopilotService.php — método borrarConversacion()
Read: src/Services/ChecklistService.php — si existe, verificar acceso por usuario
```

**Verificación 1.2 — Prohibición de chequeo directo de rol**

```
Grep: pattern="->rol\s*===?" en src/ — PROHIBIDO
Grep: pattern="\$usuario->rol\b" en src/ — PROHIBIDO
Grep: pattern="rol\s*==\s*['\"]" en src/ — PROHIBIDO
```

Cualquier coincidencia fuera de comentarios es 🔴 CRÍTICO.

**Verificación 1.3 — Copilot valida permisos antes de ejecutar tools**

Lee `src/Services/Copilot/CopilotToolExecutor.php`. Verifica que el método `ejecutar()`:
1. Llama `CopilotToolRegistry::buscarPorNombre($toolName)` antes de ejecutar.
2. Itera `$toolDef['permisos']` y llama `$usuario->tienePermiso($p)` para cada permiso requerido.
3. Verifica `$toolDef['nivel2']` para tools de nivel 2.

Si el executor ejecuta la tool sin verificar permisos, es 🔴 CRÍTICO.

---

### A02 — Fallas Criptográficas

**Verificación 2.1 — Hash de contraseñas**

Lee `src/Services/PasswordService.php`. Verifica:
- Método `hash()` usa `password_hash($password, PASSWORD_BCRYPT)` — no MD5, SHA1, SHA256 directo.
- Método `verificar()` usa `password_verify()`.
- No hay conversión a MD5/SHA1 en ningún punto del flujo de autenticación.

```
Grep: pattern="md5\(|sha1\(" en src/ — uso de algoritmos obsoletos para datos sensibles
Grep: pattern="PASSWORD_BCRYPT|PASSWORD_DEFAULT|PASSWORD_ARGON2" en src/ — algoritmos modernos
```

**Verificación 2.2 — Generación de tokens de sesión**

Lee `src/Services/AuthService.php` método `crearSesion()`. Verifica:
- Token generado con `bin2hex(random_bytes(32))` — produce 64 caracteres hex con 256 bits de entropía.
- NO usa `uniqid()`, `rand()`, `mt_rand()`, ni `md5(time())`.

```
Grep: pattern="random_bytes\(" en src/ — generación criptográficamente segura
Grep: pattern="uniqid\(|rand\(|mt_rand\(" en src/ — generación insegura (PROHIBIDO para tokens)
```

**Verificación 2.3 — VAPID keys en .env**

Lee `src/Services/PushService.php`. Verifica que lee `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY` desde `Config::require()` (no hardcodeadas).

```
Grep: pattern="VAPID_PUBLIC_KEY|VAPID_PRIVATE_KEY" en src/ — debe aparecer solo como Config::get/require
Grep: pattern="vapid.*=.*['\"][A-Za-z0-9+/=]{20,}" en src/ — valor hardcodeado (PROHIBIDO)
```

**Verificación 2.4 — Cookie de sesión: HTTPOnly y SameSite**

Lee `src/Controllers/AuthController.php` método `login()`. Verifica que la cookie `session` se establece con:
- `'httponly' => true`
- `'samesite' => 'Strict'` o `'samesite' => 'Lax'` (Strict es más seguro)
- `'secure' => true` cuando `APP_ENV !== 'local'`

Si la cookie no tiene HTTPOnly, es 🔴 CRÍTICO (permite robo via XSS).
Si no tiene SameSite, es 🟡 ADVERTENCIA (CSRF parcialmente mitigado por otras vías).

---

### A03 — Inyección

**Verificación 3.1 — SQL injection: ausencia de concatenación directa**

```
Grep: pattern="\"SELECT.+\\\$" en src/ — interpolación en SELECT
Grep: pattern="'SELECT.+\\\$" en src/ — interpolación en SELECT (comillas simples)
Grep: pattern="\"INSERT.+\\\$" en src/ — interpolación en INSERT
Grep: pattern="\"UPDATE.+\\\$" en src/ — interpolación en UPDATE
Grep: pattern="\"DELETE.+\\\$" en src/ — interpolación en DELETE
Grep: pattern="->query\s*\(\s*\"" en src/ — query() con string directo sin prepare
```

Cualquier query con `$variable` interpolada directamente (no como `?`) es 🔴 CRÍTICO.

**Verificación 3.2 — Confirmación de uso de prepared statements**

```
Grep: pattern="Database::execute\(" en src/ — deben usar ? como placeholders
Grep: pattern="Database::fetchOne\(" en src/
Grep: pattern="Database::fetchAll\(" en src/
```

Lee 3-5 llamadas aleatorias de cada tipo y verifica que todos los valores de usuario pasen como array de parámetros, nunca interpolados.

**Verificación 3.3 — XSS: output HTML escapado**

```
Grep: pattern="echo \\\$" en views/ — variables echadas sin escapar
Grep: pattern="<\?= \\\$" en views/ — shorthand echo sin escapar
Grep: pattern="htmlspecialchars" en views/ — las que sí escapan
```

Variables de request o de BD echadas en HTML sin `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` son 🔴 CRÍTICO.

**Verificación 3.4 — Ausencia de funciones peligrosas de sistema**

```
Grep: pattern="exec\(|shell_exec\(|system\(|passthru\(|popen\(" en src/
Grep: pattern="eval\(" en src/
```

Cualquier uso de estas funciones es 🔴 CRÍTICO salvo que esté documentado con comentario `// DECISIÓN AUTÓNOMA` justificando su necesidad.

---

### A04 — Diseño Inseguro

**Verificación 4.1 — Contraseñas temporales sin caracteres ambiguos**

Lee `src/Services/PasswordService.php`. Verifica que `CHARSET_SIN_AMBIGUOS` excluye: `0`, `O`, `1`, `l` (ele minúscula), `I` (i mayúscula).

La constante correcta es: `'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghjkmnpqrstuvwxyz'`

Verificar que `generarTemporal()` también garantiza al menos una mayúscula, una minúscula y un dígito (recursión hasta cumplir).

**Verificación 4.2 — Cambio forzado de contraseña en primer login**

```
Grep: pattern="requiere_cambio_pwd" en src/ — debe existir en AuthController y AuthService
```

Lee `src/Controllers/AuthController.php` método `login()`. La respuesta debe incluir `requiere_cambio_pwd: true/false`. El frontend debe redirigir a `/cambiar-contrasena` cuando es `true`.

Verificar también en `src/Services/AuthService.php` método `cambiarContrasena()`: debe hacer `requiere_cambio_pwd = 0` al cambiar exitosamente.

**Verificación 4.3 — Expiración de sesiones**

Lee `src/Services/AuthService.php` métodos `crearSesion()` y `calcularExpiracion()`. Verifica:
- Usa `Config::getInt('SESSION_LIFETIME_MINUTES', 480)` — default 8 horas.
- `validarSesion()` elimina sesiones expiradas con `DELETE FROM sesiones WHERE token = ?`.
- Aplica sliding window: renueva `expires_at` en cada request válido.

**Verificación 4.4 — Rate limiting en /api/auth/login**

Lee `src/Core/Kernel.php`. Verifica si `POST /api/auth/login` tiene algún middleware de rate limiting o throttling.

Si no existe ningún mecanismo (contador de intentos fallidos, lockout temporal, CAPTCHA), es 🟡 ADVERTENCIA — no crítico para MVP interno pero debe planificarse para producción expuesta.

**Verificación 4.5 — Inmutabilidad post-auditoría**

Lee `src/Services/AuditoriaService.php`. Verifica que `emitirVeredicto()` chequea si la habitación ya tiene estado auditado antes de proceder, retornando error 409 si ya fue auditada.

```
Grep: pattern="HABITACION_NO_PENDIENTE|ya.*auditad|re.*audit" en src/Services/AuditoriaService.php
```

---

### A05 — Configuración Insegura

**Verificación 5.1 — .env en .gitignore**

Lee `.gitignore`. Verifica que `.env` (sin sufijo) está listado. También verificar que `.env.local` y `.env.*.local` están excluidos.

Si `.env` no está en `.gitignore`, es 🔴 CRÍTICO.

**Verificación 5.2 — APP_DEBUG=false en producción**

Lee `.env.production.example`. Verifica que `APP_DEBUG=false` está explícitamente establecido.

```
Grep: pattern="APP_DEBUG" en .env.production.example
Grep: pattern="APP_DEBUG" en src/ — si se usa para exponer errores al cliente
```

Si PHP devuelve stack traces al cliente cuando `APP_DEBUG=true`, es 🟡 ADVERTENCIA en dev, 🔴 CRÍTICO si llega a producción.

**Verificación 5.3 — Headers de seguridad HTTP**

Lee `Caddyfile.example`. Verifica la presencia de:
- `Strict-Transport-Security` con `max-age` mínimo de 31536000 (1 año)
- `X-Frame-Options "DENY"` o CSP con `frame-ancestors 'none'`
- `X-Content-Type-Options "nosniff"`
- `Content-Security-Policy` — evaluar si es demasiado permisiva (ej: `'unsafe-inline'` en script-src)
- `-Server` para ocultar versión

Si los headers no están en Caddyfile, buscar si hay middleware PHP que los inyecte:

```
Grep: pattern="header\(" en src/ — headers PHP
Grep: pattern="Strict-Transport|X-Frame|Content-Security" en src/
```

**Verificación 5.4 — Bloqueo de rutas a archivos sensibles**

Lee `Caddyfile.example`. Verifica que hay una directiva que bloquea acceso a:
- `/.env` y variantes
- `/composer.*`
- `/database/*`
- `/src/*`, `/vendor/*`

Si el servidor sirve `database/atankalama.db` directamente, es 🔴 CRÍTICO.

**Verificación 5.5 — Permisos del archivo de base de datos**

Verificar en `.env.production.example` y en la documentación que `database/atankalama.db` debe tener permisos `600` o `640` (solo lectura del proceso PHP-FPM). Es 🟡 ADVERTENCIA si no está documentado.

---

### A06 — Componentes Vulnerables

**Verificación 6.1 — Versiones declaradas en composer.json**

Lee `composer.json`. Extrae las dependencias de producción:
- `minishlink/web-push` — versión usada
- `phpmailer/phpmailer` — versión usada
- `vlucas/phpdotenv` — versión usada
- `php` — versión mínima requerida

**Verificación 6.2 — Rangos de versión inseguros**

Evaluar si los rangos `^X.Y` permiten actualizaciones de minor que podrían introducir breaking changes de seguridad. En general `^` es seguro (no salta major). Flagear si alguna dependencia usa `*` o `>=X` sin cota superior.

**Verificación 6.3 — Nota sobre CVEs**

Indicar que para verificar CVEs se debe ejecutar:
```bash
composer audit
```
Este comando consulta el advisories database de Packagist. Si hay CVEs conocidos en las versiones instaladas, reportar como 🔴 CRÍTICO (fix inmediato) o 🟡 ADVERTENCIA (planificar actualización).

---

### A07 — Autenticación Rota

**Verificación 7.1 — Invalidación de sesiones en logout**

Lee `src/Services/AuthService.php` método `logout()`. Verifica que ejecuta `DELETE FROM sesiones WHERE token = ?` — elimina solo la sesión del token actual (correcto, no todas las sesiones del usuario).

**Verificación 7.2 — Invalidación de todas las sesiones al reset de contraseña**

Lee `src/Services/AuthService.php` método `resetearContrasenaTemporal()`. Verifica que ejecuta `DELETE FROM sesiones WHERE usuario_id = ?` dentro de la transacción — invalida todas las sesiones activas del usuario reseteado.

Si el reset no invalida sesiones, es 🔴 CRÍTICO (sesión robada permanece válida tras reset).

**Verificación 7.3 — Protección contra fuerza bruta**

Lee `src/Core/Kernel.php` — revisar si `POST /api/auth/login` tiene throttle middleware.
Lee `src/Services/AuthService.php` método `login()` — revisar si hay contador de intentos fallidos en BD o en memoria.

Si no existe ningún mecanismo: 🟡 ADVERTENCIA. Mencionar que para MVP interno con red corporativa puede ser aceptable, pero debe implementarse antes de exposición pública.

**Verificación 7.4 — Sliding window de sesiones**

Confirmar en `src/Services/AuthService.php` método `validarSesion()` que:
1. Comprueba `expires_at < time()` y borra la sesión si expiró.
2. Actualiza `expires_at` en sesiones válidas (sliding window).

---

### A08 — Integridad de Datos

**Verificación 8.1 — Importador CSV: validaciones de archivo**

Lee `src/Controllers/TurnosImportController.php` método `preview()`. Verificar:
- Valida que `$_FILES['csv_file']['error'] === UPLOAD_ERR_OK`.
- Valida extensión: solo `.csv` y `.txt` permitidos.
- Valida tamaño: máximo 5 MB (`$archivo['size'] > 5 * 1024 * 1024`).
- Lee el archivo con `file_get_contents($archivo['tmp_name'])` — no ejecuta el archivo.
- **NO** usa `include`, `require`, ni `eval()` sobre el contenido del CSV.

**Verificación 8.2 — Token anti-reenvío en importación**

En el mismo método `preview()`, verificar que:
- Genera un token con `bin2hex(random_bytes(16))`.
- Guarda los datos reales en `$_SESSION['breik_import_' . $token]`.
- Solo devuelve el token al cliente, no las filas a importar.

En `confirmar()`, verificar que:
- Lee el token del body JSON.
- Busca la clave en `$_SESSION` y falla con error amigable si no existe.
- Hace `unset($_SESSION[$clave])` después de usar — token de un solo uso.

**Verificación 8.3 — Auditoría inmutable (409 en re-auditoría)**

Lee `src/Services/AuditoriaService.php`. Verificar que hay una constraint UNIQUE o chequeo explícito que impide auditar dos veces la misma ejecución. Buscar:

```
Grep: pattern="409|UNIQUE|ya.*auditada|ejecucion_id" en src/Services/AuditoriaService.php
```

---

### A09 — Logging Insuficiente

**Verificación 9.1 — LogSanitizer aplicado en todos los logs**

Lee `src/Core/Logger.php`. Verificar que:
- El método `log()` privado llama `LogSanitizer::sanitize($contexto)` antes de persistir.
- El método `audit()` llama `LogSanitizer::sanitize($detalles)` antes de persistir.

```
Grep: pattern="LogSanitizer::sanitize" en src/Core/Logger.php
```

Si Logger persiste `$contexto` sin sanitizar, es 🔴 CRÍTICO.

**Verificación 9.2 — Campos sensibles cubiertos por LogSanitizer**

Lee `src/Helpers/LogSanitizer.php`. Verificar que `CAMPOS_SENSIBLES` incluye al menos:
- `password`, `password_hash`, `password_actual`, `password_nueva`, `password_temporal`
- `api_key`, `claude_api_key`, `anthropic_api_key`, `cloudbeds_api_key`
- `token`, `session`, `authorization`, `bearer`, `secret`

También verificar que `sanitizeStringValue()` detecta patrones de tokens:
- `Bearer <string>` → `[REDACTED]`
- `sk-<string>` (Claude API keys) → `[REDACTED]`

**Verificación 9.3 — Audit log de acciones críticas**

```
Grep: pattern="Logger::audit\(" en src/ — acciones auditadas
```

Verificar que las siguientes acciones generan entradas en `audit_log`:
- `auth.login` — login exitoso
- `auth.logout` — logout
- `auth.cambiar_password` — cambio de contraseña
- `usuario.reset_password` — reset por admin
- Acciones de auditoría (aprobar/rechazar habitación)

Si alguna de estas no tiene `Logger::audit()`, es 🟡 ADVERTENCIA.

**Verificación 9.4 — Escrituras a Cloudbeds en logs**

Lee `src/Services/CloudbedsClient.php`. Verificar que `ejecutarConReintentos()` registra:
- `Logger::info()` en respuesta exitosa con status y número de intento.
- `Logger::warning()` en reintento con status y espera.
- `Logger::error()` en fallo definitivo y en credencial inválida 401.

---

### A10 — SSRF (Server-Side Request Forgery)

**Verificación 10.1 — URL de Cloudbeds validada contra dominio esperado**

Lee `src/Services/CloudbedsClient.php` constructor. Verifica que la `$baseUrl` proviene de `Config::get('CLOUDBEDS_API_BASE_URL', 'https://api.cloudbeds.com/api/v1.1')`.

Verificar si hay validación de que la URL configurada apunta realmente a `cloudbeds.com`:
```
Grep: pattern="parse_url|filter_var.*FILTER_VALIDATE_URL|cloudbeds\.com" en src/Services/CloudbedsClient.php
```

Si `CLOUDBEDS_API_BASE_URL` puede apuntarse a una URL arbitraria (incluyendo `localhost`, rangos privados, servicios internos), es 🟡 ADVERTENCIA. Para MVP interno puede ser aceptable; documentar como limitación.

**Verificación 10.2 — URL de Claude API**

Lee `src/Services/Copilot/CopilotClient.php`. Verificar que la URL de Anthropic está hardcodeada como `'https://api.anthropic.com/v1/messages'` y no es configurable desde `.env` (reduce superficie de SSRF).

Si la URL es configurable desde `.env` sin validación de dominio, es 🟡 ADVERTENCIA.

---

## PARTE 2 — RGPD / PROTECCIÓN DE DATOS

> Contexto legal: La Ley 19.628 (Chile, vigente) regula protección de datos personales. La Ley 21.096 de 2018 elevó a rango constitucional la protección de datos. El proyecto además sigue principios GDPR como buena práctica.

### RGPD-1 — Inventario de Datos Personales

Lee `docs/database-schema.sql`. Mapear todas las tablas que contienen datos personales:

**Datos directos de trabajadores:**
- `usuarios`: `rut` (identificador único nacional), `nombre`, `email` (opcional), `password_hash`, `last_login_at`
- `sesiones`: `ip`, `user_agent` — datos de conexión vinculados a usuario
- `audit_log`: registro de todas las acciones por `usuario_id` + `ip`
- `contrasenas_temporales`: trazabilidad de resets (sin password en claro)

**Datos de actividad laboral:**
- `ejecuciones_checklist`: `timestamp_inicio`, `timestamp_fin` por trabajador y habitación — tracking de tiempo
- `asignaciones`: qué habitaciones se asignaron a cada trabajador
- `auditorias`: resultado de auditoría vinculado al auditor

**Datos del copilot:**
- `copilot_conversaciones`: `titulo` (primer mensaje truncado), `usuario_id`
- `copilot_mensajes`: `contenido` — texto libre que puede contener PII (nombres de huéspedes, situaciones laborales, etc.)

**Notificaciones:**
- `notificaciones`: `titulo`, `cuerpo` — pueden contener nombres de trabajadores o habitaciones

Presentar este inventario como tabla en el reporte. Indicar la base legal de procesamiento para cada categoría (relación laboral / interés legítimo del empleador).

### RGPD-2 — Minimización de Datos

**Verificación RGPD-2.1 — Email opcional**

```
Grep: pattern="email.*NOT NULL|email.*required" en docs/database-schema.sql
```

Verificar que `email` en tabla `usuarios` es nullable (no `NOT NULL`). Si es obligatorio, es 🟡 ADVERTENCIA (email no es imprescindible para la operación).

**Verificación RGPD-2.2 — IP en sesiones: necesidad vs. riesgo**

La columna `ip` en `sesiones` permite geolocalización de trabajadores. Evaluar si:
- Se usa activamente para detección de sesiones sospechosas (justifica retención).
- Solo se guarda para auditoría sin procesamiento activo (considera pseudonimizar con hash).

Indicar como 🟡 ADVERTENCIA si la IP se guarda indefinidamente sin mecanismo de depuración.

**Verificación RGPD-2.3 — Conversaciones del copilot con PII**

Lee `src/Services/Copilot/CopilotService.php` método `guardarMensaje()`. Los mensajes del usuario se guardan en `copilot_mensajes.contenido`. Un trabajador puede escribir texto libre que mencione nombres de huéspedes, números de habitación, situaciones personales.

Evaluar:
- ¿Hay aviso al usuario de que los mensajes se guardan?
- ¿Los admins pueden ver conversaciones de otros (`copilot.ver_historial_todos`)? Verificar en `src/Core/Kernel.php` ruta `GET /api/copilot/conversaciones/todas`.
- Indicar como 🟡 ADVERTENCIA: documentar en política de privacidad que las conversaciones son monitoreables por administración.

### RGPD-3 — Retención de Datos

**Verificación RGPD-3.1 — Notificaciones: limpieza automática**

Lee `src/Services/NotificacionesService.php`. Verificar que `limpiarAntiguas()` se llama en `crear()` y mantiene máximo 50 notificaciones por usuario.

**Verificación RGPD-3.2 — Sesiones expiradas: limpieza**

```
Grep: pattern="DELETE FROM sesiones WHERE.*expires" en src/
```

Verificar si hay un mecanismo (cron job o limpieza en cada request) que elimine sesiones expiradas. Si no hay limpieza automática, la tabla crece indefinidamente con datos de IP/user_agent de sesiones viejas. Es 🟡 ADVERTENCIA.

**Verificación RGPD-3.3 — Logs de eventos: política de rotación**

Lee `src/Core/Logger.php`. Los logs se guardan en `logs_eventos` en SQLite. Verificar:
- ¿Hay un campo `expires_at` o un job que archive/elimine logs viejos?
- ¿Hay límite de tamaño o tiempo en `audit_log`?

Si los logs crecen indefinidamente sin política de retención, es 🟡 ADVERTENCIA. Proponer: rotar logs de más de 90 días; `audit_log` de más de 2 años.

**Verificación RGPD-3.4 — Contraseñas temporales: limpieza tras uso**

Lee `src/Services/AuthService.php` método `cambiarContrasena()`. Verifica que marca `usada = 1` y registra `usada_at` en `contrasenas_temporales` al cambiar la contraseña.

Verificar si hay limpieza periódica de registros `usada = 1` con más de N días. Si no existe, indicar como 🟢 CUMPLE (el registro ya no contiene la contraseña, solo metadata de auditoría) pero sugerir limpieza de registros de más de 180 días.

### RGPD-4 — Derechos del Titular

**Verificación RGPD-4.1 — Derecho de acceso**

Verificar si existe algún endpoint que permita a un trabajador descargar todos sus datos personales:

```
Grep: pattern="exportar|mis.datos|portabilidad" en src/Controllers/
Grep: pattern="GET /api/reportes/exportar" en src/Core/Kernel.php
```

Si `GET /api/reportes/exportar` solo está disponible para roles con `reportes.ver` (supervisoras/admin) y no para el propio trabajador, indicar como 🟡 ADVERTENCIA — el trabajador no puede acceder a su propio historial de KPIs.

**Verificación RGPD-4.2 — Derecho de rectificación**

Verificar que existe `PUT /api/usuarios/{id}` con `PermissionCheck('usuarios.editar')` para que admins puedan corregir datos, y que el propio usuario puede editar su perfil (nombre, email) desde algún endpoint.

```
Grep: pattern="ajustes/mi-cuenta|perfil|editar.*propio" en src/
```

**Verificación RGPD-4.3 — Derecho de supresión (derecho al olvido)**

Verificar si existe algún endpoint de eliminación de usuario:

```
Grep: pattern="DELETE.*usuarios|eliminar.*usuario|borrar.*usuario" en src/
```

En `docs/database-schema.sql`, verificar que `usuarios` tiene FKs con `ON DELETE CASCADE` para que al eliminar un usuario se eliminen también sus sesiones, asignaciones, etc.

Si no existe endpoint de eliminación de usuario, es 🟡 ADVERTENCIA — para MVP interno puede ser aceptable (baja/desactivación), pero la Ley 19.628 reconoce el derecho a solicitar eliminación.

**Verificación RGPD-4.4 — Portabilidad de datos**

Evaluar si el trabajador puede exportar su historial de trabajo (habitaciones limpiadas, KPIs propios). El endpoint `GET /api/reportes/exportar` parece ser solo para supervisoras/admin.

Indicar como ⚪ NO APLICA / FUERA DE SCOPE MVP si no se implementó, pero documentar como deuda para Fase 2.

### RGPD-5 — Transferencias a Terceros

**Verificación RGPD-5.1 — Cloudbeds: qué datos se envían**

Lee `src/Services/CloudbedsClient.php`. Verificar qué datos se envían a Cloudbeds:
- `propertyID`, `roomID`, `roomCondition` — datos operacionales de habitaciones, sin PII de trabajadores.

Evaluar si algún endpoint de Cloudbeds recibe RUT, nombre o email del trabajador. Si no, es 🟢 CUMPLE (mínima transferencia).

**Verificación RGPD-5.2 — Claude API (Anthropic): transferencia de conversaciones**

Lee `src/Services/Copilot/CopilotClient.php`. Verificar que los mensajes completos del usuario (incluyendo texto libre que puede contener PII) se envían a `https://api.anthropic.com/v1/messages`.

Esto constituye transferencia de datos personales a un procesador de datos (Anthropic, EE.UU.). Evaluar:
- ¿Existe cláusula de procesador de datos (DPA) con Anthropic? (Verificar en docs/ si existe referencia)
- ¿Se informa al trabajador en algún aviso de privacidad que sus conversaciones con el copilot son procesadas por Anthropic?
- El `system_prompt` en `CopilotService::construirSystemPrompt()` menciona roles pero no incluye RUT ni nombres directamente — verificar.

```
Read: src/Services/Copilot/CopilotService.php — método construirSystemPrompt()
```

Si las conversaciones incluyen PII y no hay aviso al usuario, es 🟡 ADVERTENCIA (obligación de informar bajo Ley 19.628 Art. 4).

**Verificación RGPD-5.3 — PHPMailer/SMTP: emails con contraseñas temporales**

Lee `src/Services/EmailService.php`. Verificar:
- Qué proveedor SMTP se usa (leer en `.env.example` las variables `SMTP_*`).
- El email enviado al trabajador contiene nombre, RUT y contraseña temporal.

El proveedor SMTP tiene acceso a estos datos en tránsito. Es 🟢 CUMPLE si se usa SMTP con TLS (verificar que se fuerza `SMTPSecure = 'tls'` o `ssl`).

```
Grep: pattern="SMTPSecure|smtp_secure|STARTTLS|ssl" en src/Services/EmailService.php
```

### RGPD-6 — Consentimiento y Transparencia

**Verificación RGPD-6.1 — Aviso de privacidad / política de datos**

```
Glob: pattern="privacidad*|privacy*|terminos*" en views/ y docs/
```

Si no existe ninguna página ni documento de aviso de privacidad, es 🟡 ADVERTENCIA. La Ley 19.628 Art. 4 exige informar al titular sobre el tratamiento de sus datos.

**Verificación RGPD-6.2 — Transparencia sobre tracking de tiempo oculto**

El proyecto registra `timestamp_inicio` y `timestamp_fin` en `ejecuciones_checklist` para calcular tiempos promedio del trabajador. Según `CLAUDE.md`, "El trabajador NUNCA debe ver estos valores en su pantalla."

Evaluar si esta práctica de monitoreo oculto del desempeño está documentada en:
- Política de privacidad / aviso al trabajador
- Contrato laboral o reglamento interno

Indicar como 🟡 ADVERTENCIA: el tracking de tiempo laboral (aunque oculto en la UI) debe mencionarse en la política de privacidad conforme a la Ley 19.628 y normativa laboral chilena.

**Verificación RGPD-6.3 — Información al usuario sobre monitoreo por IA**

Las conversaciones con el copilot se usan para responder preguntas operacionales. Si el copilot tiene acceso a KPIs del propio trabajador (tiempos, habitaciones), verificar que el trabajador sabe que el asistente tiene acceso a esos datos.

```
Read: src/Services/Copilot/CopilotService.php — método construirSystemPrompt()
Read: src/Services/Copilot/CopilotToolExecutor.php — qué tools están disponibles para rol trabajador
```

### RGPD-7 — Brechas de Seguridad

**Verificación RGPD-7.1 — Procedimiento documentado de notificación de brechas**

```
Glob: pattern="brecha*|incidente*|breach*" en docs/
```

Si no existe documentación de procedimiento ante brechas:
- Bajo Ley 21.096 (Chile) y principios GDPR: notificación a afectados en plazo razonable (referencia GDPR: 72 horas a autoridad).
- Indicar como 🟡 ADVERTENCIA para MVP; documentar como tarea pendiente para producción.

---

## Procedimiento de ejecución completo

Sigue este orden para minimizar lecturas redundantes:

1. Lee `src/Core/Kernel.php` — mapa completo de rutas (base para A01, A04, A07)
2. Lee `src/Services/AuthService.php` — autenticación y sesiones (A01, A02, A07)
3. Lee `src/Controllers/AuthController.php` — cookie de sesión (A02)
4. Lee `src/Services/PasswordService.php` — hashing y temporales (A02, A04)
5. Lee `src/Services/Copilot/CopilotToolExecutor.php` — validación de permisos en tools (A01)
6. Lee `src/Services/Copilot/CopilotClient.php` — URL de Anthropic y logs (A10, RGPD-5.2)
7. Lee `src/Services/CloudbedsClient.php` — URL y logging de Cloudbeds (A09, A10, RGPD-5.1)
8. Lee `src/Controllers/TurnosImportController.php` — validación de CSV (A08)
9. Lee `src/Services/AuditoriaService.php` — inmutabilidad 409 (A04, A08)
10. Lee `src/Core/Logger.php` — verificar LogSanitizer (A09)
11. Lee `src/Helpers/LogSanitizer.php` — campos cubiertos (A09)
12. Lee `src/Services/PushService.php` — VAPID keys (A02)
13. Lee `.gitignore` — .env excluido (A05)
14. Lee `.env.production.example` — APP_DEBUG y otras vars (A05)
15. Lee `Caddyfile.example` — headers HTTP y bloqueo de rutas (A05)
16. Lee `composer.json` — versiones de dependencias (A06)
17. Lee `docs/database-schema.sql` — inventario de PII (RGPD-1)
18. Lee `src/Services/NotificacionesService.php` — retención (RGPD-3)
19. Lee `src/Services/Copilot/CopilotService.php` — system prompt y historial (RGPD-5.2, RGPD-6.3)
20. Ejecutar búsquedas Grep para inyección, tokens inseguros, rol hardcodeado (A01, A02, A03)

---

## Formato de salida del reporte

```
## Reporte de Seguridad y RGPD — Atankalama Limpieza
Fecha: <fecha actual>

---

## PARTE 1 — SEGURIDAD (OWASP Top 10)

### A01 — Control de Acceso

🔴 CRÍTICO — <descripción con archivo:línea>
🟡 ADVERTENCIA — <descripción>
🟢 CUMPLE — <qué está bien implementado>
⚪ NO APLICA — <qué está fuera de scope>

---

### A02 — Fallas Criptográficas
[misma estructura]

---

### A03 — Inyección
[misma estructura]

---

### A04 — Diseño Inseguro
[misma estructura]

---

### A05 — Configuración Insegura
[misma estructura]

---

### A06 — Componentes Vulnerables
[misma estructura]

---

### A07 — Autenticación Rota
[misma estructura]

---

### A08 — Integridad de Datos
[misma estructura]

---

### A09 — Logging Insuficiente
[misma estructura]

---

### A10 — SSRF
[misma estructura]

---

## PARTE 2 — RGPD / PROTECCIÓN DE DATOS

### Inventario de Datos Personales

| Tabla | Datos Personales | Base Legal | Sensibilidad |
|-------|-----------------|------------|--------------|
| usuarios | RUT, nombre, email | Relación laboral | Alta |
| sesiones | IP, user_agent | Interés legítimo (seguridad) | Media |
| audit_log | Acciones + IP por usuario_id | Obligación legal | Media |
| copilot_mensajes | Texto libre (puede incluir PII) | Ejecución del contrato | Media-Alta |
| ejecuciones_checklist | Tiempos de trabajo por trabajador | Interés legítimo (KPIs) | Media |

### RGPD-2 — Minimización
[hallazgos]

### RGPD-3 — Retención
[hallazgos]

### RGPD-4 — Derechos del Titular
[hallazgos]

### RGPD-5 — Transferencias a Terceros
[hallazgos]

### RGPD-6 — Transparencia
[hallazgos]

### RGPD-7 — Brechas
[hallazgos]

---

## Resumen Ejecutivo

<3-5 líneas describiendo el nivel general de seguridad y cumplimiento, señalando los puntos más fuertes y las brechas más urgentes>

## Las 3 acciones más urgentes

1. 🔴 <acción concreta con archivo/endpoint a modificar>
2. 🔴 <acción concreta>
3. 🟡 <acción concreta de prioridad media>

## Tabla de estado general

| Área | Estado |
|------|--------|
| A01 Control de acceso | 🔴/🟡/🟢 |
| A02 Criptografía | 🔴/🟡/🟢 |
| A03 Inyección | 🔴/🟡/🟢 |
| A04 Diseño inseguro | 🔴/🟡/🟢 |
| A05 Configuración | 🔴/🟡/🟢 |
| A06 Componentes | 🔴/🟡/🟢 |
| A07 Autenticación | 🔴/🟡/🟢 |
| A08 Integridad | 🔴/🟡/🟢 |
| A09 Logging | 🔴/🟡/🟢 |
| A10 SSRF | 🔴/🟡/🟢 |
| RGPD Inventario | 🔴/🟡/🟢 |
| RGPD Minimización | 🔴/🟡/🟢 |
| RGPD Retención | 🔴/🟡/🟢 |
| RGPD Derechos | 🔴/🟡/🟢 |
| RGPD Terceros | 🔴/🟡/🟢 |
| RGPD Transparencia | 🔴/🟡/🟢 |
| RGPD Brechas | 🔴/🟡/🟢 |
```

---

## Escala de severidad

| Símbolo | Significado |
|---------|-------------|
| 🔴 CRÍTICO | Vulnerabilidad explotable o incumplimiento legal directo. Resolver **antes de producción** sin excepción. |
| 🟡 ADVERTENCIA | Riesgo medio o brecha de cumplimiento parcial. Planificar solución en próxima iteración. |
| 🟢 CUMPLE | Implementado correctamente según las mejores prácticas del proyecto. |
| ⚪ NO APLICA | Fuera de scope del MVP o no relevante para este stack. |

---

## Reglas de la revisión

- **Nunca asumas** el estado del código sin haberlo verificado con Grep o Read.
- Reporta hallazgos con **archivo y número de línea** cuando sea posible.
- Si un archivo esperado no existe, indícalo como hallazgo con severidad según el impacto.
- La revisión es **descriptiva, no prescriptiva**: reporta, no corrijas (salvo que el usuario lo pida después de ver el reporte).
- Para RGPD: el contexto es Chile (Ley 19.628 + Ley 21.096). Menciona estándares GDPR solo como referencia de buenas prácticas, no como obligación directa.
- Si hay un hallazgo que ya está cubierto por otro mecanismo compensatorio (ej: rate limiting ausente pero solo accesible desde VPN), menciónalo como mitigación parcial con 🟡 en lugar de 🔴.
