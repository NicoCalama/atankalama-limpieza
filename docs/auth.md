# Autenticación y gestión de sesión

**Versión:** 1.0 — 2026-04-14

Documenta el flujo de login, logout, cambio de contraseña, generación de contraseñas temporales, middleware `AuthCheck` y script de rescate de emergencia.

---

## 1. Login

### 1.1 Formulario

- **Campo 1:** RUT (formato libre en input, normalizado al enviar: sin puntos, con guión, ej. `12345678-9`). Validación de dígito verificador antes de hacer el POST.
- **Campo 2:** Contraseña.
- Botón **"Ingresar"** (deshabilitado hasta que ambos campos tengan valor válido).
- Link **"Olvidé mi contraseña"** → muestra tooltip: "Contacta a tu supervisor/admin para un reset."

### 1.2 Validación de RUT

Algoritmo módulo 11 chileno:

```
1. Separar número y DV (último carácter, puede ser 'K').
2. Multiplicar cada dígito del número (de derecha a izquierda) por 2,3,4,5,6,7 cíclicamente.
3. Sumar los productos.
4. Calcular: 11 - (suma % 11).
5. Si resultado == 11 → DV = '0'; si 10 → DV = 'K'; else → DV = str(resultado).
```

Implementar en `src/Helpers/Rut.php`:
- `Rut::normalizar(string $input): string` — quita puntos, lowercase K → uppercase, asegura guión.
- `Rut::validar(string $rutNormalizado): bool`.

### 1.3 Endpoint `POST /api/auth/login`

Request:
```json
{ "rut": "12345678-9", "password": "Abc123xy" }
```

Respuesta éxito (200):
```json
{
  "ok": true,
  "data": {
    "usuario": { "id": 1, "nombre": "Ana Pérez", "rut": "12345678-9" },
    "permisos": ["habitaciones.ver_asignadas_propias", "..."],
    "requiere_cambio_pwd": false,
    "home_target": "/home-trabajador"
  }
}
```

Set-Cookie: `session=<token>; HttpOnly; SameSite=Strict; Secure; Path=/`.

Errores:
- `401` `CREDENCIALES_INVALIDAS` — RUT o pwd incorrectos (mensaje genérico, no revelar cuál falló).
- `403` `USUARIO_INACTIVO` — usuario existe pero `activo=0`.
- `400` `RUT_INVALIDO` — formato o DV inválido.

### 1.4 Decisión de Home (`home_target`)

Se calcula según permisos efectivos del usuario (ver [roles-permisos.md §1.3](roles-permisos.md)). Orden de prioridad si aplica más de uno:

1. `ajustes.acceder` → `/home-admin`
2. `alertas.recibir_predictivas` + `asignaciones.asignar_manual` → `/home-supervisora`
3. `auditoria.ver_bandeja` sin permisos de supervisora → `/home-recepcion`
4. `habitaciones.ver_asignadas_propias` → `/home-trabajador`

Si ninguno coincide, `home_target = "/login"` y se muestra error "Tu usuario no tiene acceso a ninguna sección. Contacta al admin.".

### 1.5 Cambio forzado en primer login

Si `requiere_cambio_pwd = 1` después del login, el frontend redirige inmediatamente a `/cambiar-contrasena` (ruta protegida por `AuthCheck` pero no requiere permisos adicionales). El usuario no puede salir de esa pantalla hasta cambiar su contraseña.

---

## 2. Logout

### 2.1 Endpoint `POST /api/auth/logout`

- Invalida la fila en `sesiones` (DELETE por token).
- Limpia la cookie en el cliente.
- Respuesta 200 `{ "ok": true }`.

---

## 3. Cambio de contraseña

### 3.1 Endpoint `POST /api/auth/cambiar-contrasena`

Request:
```json
{ "password_actual": "...", "password_nueva": "...", "password_nueva_confirmacion": "..." }
```

Validaciones:
- Mínimo 8 caracteres.
- Al menos 1 letra y 1 número.
- `password_nueva == password_nueva_confirmacion`.
- `password_actual` verifica con `password_verify()` contra el hash guardado.

Éxito (200):
- Actualiza `password_hash = password_hash($nueva, PASSWORD_BCRYPT)`.
- Setea `requiere_cambio_pwd = 0`.
- Si había una fila en `contrasenas_temporales` con `usada=0` para este usuario, la marca `usada=1, usada_at=now`.

Errores:
- `400` `PWD_DEBIL` — no cumple requisitos.
- `400` `PWD_NO_COINCIDE` — confirmación ≠ nueva.
- `401` `PWD_ACTUAL_INCORRECTA`.

**Requiere permiso** `usuarios.cambiar_propia_contrasena` (todos lo tienen por defecto).

---

## 4. Reset de contraseña (por admin)

### 4.1 Endpoint `POST /api/auth/reset-temporal`

Request:
```json
{ "usuario_id": 42 }
```

Requiere permiso `usuarios.resetear_password`.

Comportamiento:
- Genera contraseña temporal de 8 caracteres, alfanumérica, **sin caracteres ambiguos** (sin `0/O`, `1/l/I`, etc.). Helper: `Password::generarTemporal(): string`.
- Actualiza `usuarios.password_hash` con el hash de esa pwd.
- Setea `requiere_cambio_pwd = 1`.
- Inserta fila en `contrasenas_temporales`: `motivo='reset_admin'`, `generada_por=<admin_id>`, `usada=0`.
- Registra en `audit_log` con `accion='usuario.reset_password'`.

Respuesta (200):
```json
{ "ok": true, "data": { "password_temporal": "K7m4x2Qp" } }
```

**Importante:** La pwd temporal se devuelve UNA VEZ en esta respuesta. No se guarda en claro ni se muestra después. El admin es responsable de comunicarla al usuario (verbalmente, por WhatsApp, etc.).

---

## 5. Creación de usuario con contraseña temporal

Al crear un usuario nuevo vía `POST /api/usuarios`:
- Se genera una pwd temporal con `Password::generarTemporal()`.
- Se setea `requiere_cambio_pwd = 1`.
- Se inserta fila en `contrasenas_temporales` con `motivo='creacion'`.
- La respuesta incluye la pwd temporal (una vez).

Ver [usuarios.md](usuarios.md) para el CRUD completo.

---

## 6. Middleware `AuthCheck`

`src/Middleware/AuthCheck.php`.

Flujo:
1. Lee cookie `session`.
2. Si no existe → 401 `NO_AUTENTICADO`.
3. Busca token en `sesiones`. Si no existe o `expires_at < now` → 401 `SESION_EXPIRADA` + DELETE de la fila.
4. Carga `usuario` con sus permisos y lo inyecta en el contexto del request (`$request->usuario`, `$request->permisos`).
5. Pasa al siguiente middleware.

### 6.1 Duración de sesión

- Default: **8 horas** desde último uso (sliding window).
- Cada request válido actualiza `sesiones.expires_at = now + 8h`.
- Tras 8h de inactividad → sesión expirada, requiere re-login.

---

## 7. Middleware `PermissionCheck`

`src/Middleware/PermissionCheck.php`. Uso:

```php
$router->post('/api/habitaciones/{id}/asignar', [HabitacionesController::class, 'asignar'])
    ->middleware(new PermissionCheck('asignaciones.asignar_manual'));
```

Flujo:
1. Requiere que `AuthCheck` ya haya corrido.
2. Lee `$request->permisos`. Si contiene el código requerido → pasa.
3. Else → 403 `PERMISO_INSUFICIENTE` + log en `logs_eventos` (WARNING).

Acepta OR de múltiples permisos:
```php
new PermissionCheck(['auditoria.aprobar', 'auditoria.aprobar_con_observacion'])
// El usuario debe tener AL MENOS UNO
```

---

## 8. Script de rescate de emergencia

`scripts/reset-admin-password.php` (ejecutable desde CLI):

```bash
php scripts/reset-admin-password.php
```

Flujo:
1. Pide RUT por stdin (sin usar argumentos — previene que quede en bash history).
2. Pide nueva pwd por stdin (sin echo).
3. Valida: usuario existe, está activo, tiene rol con permiso `permisos.asignar_a_rol`.
4. Actualiza password_hash, setea `requiere_cambio_pwd=0`.
5. Registra en `audit_log` con `origen='script'`, `accion='usuario.reset_password_emergencia'`.
6. Imprime confirmación.

**Uso previsto:** si el único admin queda bloqueado (olvido de pwd + no hay otro admin para reset).

---

## 9. Seguridad — reglas no negociables

1. **Password siempre hasheado** con `password_hash($pwd, PASSWORD_BCRYPT)`.
2. **Nunca loggear** passwords (ni en claro ni en hash), tokens de sesión, cookies de Authorization.
3. **Mensajes de error genéricos** en login fallido (no revelar si el RUT existe).
4. **Rate limiting** (post-MVP): máximo 5 intentos fallidos en 15 min por IP → bloqueo temporal.
5. **HTTPS obligatorio** en producción. La cookie lleva flag `Secure`.
6. **Sanitizar RUT** al recibir (quitar espacios, puntos, normalizar K a mayúscula).

---

## 10. Referencias cruzadas

- [roles-permisos.md](roles-permisos.md) — catálogo RBAC
- [usuarios.md](usuarios.md) — CRUD de usuarios
- [database-schema.sql](database-schema.sql) — tablas `usuarios`, `sesiones`, `contrasenas_temporales`
- [api-endpoints.md](api-endpoints.md) — índice de endpoints
- [CLAUDE.md](../CLAUDE.md) §"Reglas de seguridad"
