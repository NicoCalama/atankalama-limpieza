# Usuarios

**Versión:** 1.0 — 2026-04-14

Documenta el CRUD de usuarios, incluyendo la subsección "trabajadores" (no es entidad separada — son usuarios filtrados por rol).

---

## 1. Modelo

Un usuario representa a una persona real con acceso a la app. Campos principales (ver [database-schema.sql](database-schema.sql) tabla `usuarios`):

- `id`, `rut` (UNIQUE), `nombre`, `email` (opcional), `password_hash`, `requiere_cambio_pwd`, `activo`, `hotel_default`, `tema_preferido`, `created_at`, `updated_at`, `last_login_at`.

Un usuario puede tener **múltiples roles** (tabla `usuarios_roles`). Permisos efectivos = unión.

---

## 2. Trabajadores (no es entidad separada)

"Trabajador" es simplemente un usuario que tiene asignado el rol **Trabajador**. Toda la gestión usa los mismos endpoints de `/api/usuarios` con filtro `?rol=trabajador`.

---

## 3. CRUD

### 3.1 Listar

`GET /api/usuarios` — permiso `usuarios.ver`.

Query params:
- `?rol=trabajador` — filtra por rol.
- `?activo=1` — solo activos.
- `?hotel=1_sur|inn` — por hotel default (filtro blando — un usuario puede trabajar en otro hotel si la supervisora lo asigna).
- `?search=...` — busca en nombre/RUT/email.

Respuesta paginada (20 por página default):
```json
{
  "ok": true,
  "data": {
    "usuarios": [...],
    "total": 45,
    "pagina": 1,
    "por_pagina": 20
  }
}
```

### 3.2 Detalle

`GET /api/usuarios/{id}` — permiso `usuarios.ver` (o propietario del id).

Incluye roles asignados.

### 3.3 Crear

`POST /api/usuarios` — permiso `usuarios.crear`.

Request:
```json
{
  "rut": "12345678-9",
  "nombre": "Carmen Silva",
  "email": "carmen@ejemplo.cl",
  "roles": ["Trabajador"],
  "hotel_default": "1_sur"
}
```

Validaciones:
- RUT único y con DV válido.
- Nombre obligatorio (min 3 chars).
- Email opcional pero válido si se envía.
- Al menos 1 rol.

Comportamiento (ver [auth.md](auth.md) §5):
- Genera pwd temporal sin caracteres ambiguos.
- `requiere_cambio_pwd = 1`.
- Registra en `contrasenas_temporales` con `motivo='creacion'`.

Respuesta (201):
```json
{
  "ok": true,
  "data": {
    "usuario": {...},
    "password_temporal": "K7m4x2Qp"
  }
}
```

**La pwd se devuelve UNA VEZ.** El admin es responsable de comunicarla.

### 3.4 Editar

`PUT /api/usuarios/{id}` — permiso `usuarios.editar`.

Permite editar: nombre, email, hotel_default. **No** permite editar: rut (inmutable), password (usar endpoint específico), activo (usar endpoint específico), roles (usar endpoint específico).

### 3.5 Reset password

`POST /api/auth/reset-temporal` — permiso `usuarios.resetear_password`.

Ver [auth.md](auth.md) §4.

### 3.6 Cambiar propia contraseña

`POST /api/auth/cambiar-contrasena` — permiso `usuarios.cambiar_propia_contrasena` (todos lo tienen por default).

Ver [auth.md](auth.md) §3.

### 3.7 Activar / desactivar

`PUT /api/usuarios/{id}/activo` — permiso `usuarios.activar_desactivar`.

Request: `{ "activo": true | false }`.

Reglas:
- Al desactivar: el usuario no puede loguearse. Sus asignaciones activas se mantienen (reasignar manualmente).
- Al desactivar al propio usuario: error 400 `AUTO_DESACTIVACION_PROHIBIDA`.
- Safeguard: si es el último admin activo → error 409 `ULTIMO_ADMIN`.

### 3.8 Asignar roles

`PUT /api/usuarios/{id}/roles` — permiso `usuarios.asignar_rol`.

Request:
```json
{ "roles": ["Supervisora", "Recepción"] }
```

Reemplaza el set completo (DELETE + INSERT en `usuarios_roles`).

Safeguard: no permitir que un usuario se quite a sí mismo `permisos.asignar_a_rol` si es el último admin.

---

## 4. "Mi cuenta" (todos los usuarios)

Cada usuario puede:
- Ver sus datos (nombre, RUT, email, roles).
- Cambiar su propia contraseña.
- Cambiar tema (día/noche/auto).
- Cambiar hotel default.
- Ver historial del copilot (si tiene `copilot.ver_historial_propio`).

UI en Ajustes → Mi cuenta. Ver [ajustes.md](ajustes.md) §6.

---

## 5. Endpoints — resumen

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/usuarios` | `usuarios.ver` | Lista paginada con filtros |
| GET | `/api/usuarios/{id}` | `usuarios.ver` o propio | Detalle |
| POST | `/api/usuarios` | `usuarios.crear` | Crear + pwd temporal |
| PUT | `/api/usuarios/{id}` | `usuarios.editar` | Editar datos base |
| PUT | `/api/usuarios/{id}/activo` | `usuarios.activar_desactivar` | Activar/desactivar |
| PUT | `/api/usuarios/{id}/roles` | `usuarios.asignar_rol` | Asignar roles |
| PUT | `/api/usuarios/me` | propio | Editar propios datos (nombre, email, hotel_default, tema) |

---

## 6. Referencias cruzadas

- [auth.md](auth.md) — login, pwd, sesiones
- [roles-permisos.md](roles-permisos.md) §2.6 — permisos
- [ajustes.md](ajustes.md) — UI
- [database-schema.sql](database-schema.sql) — tabla `usuarios`, `usuarios_roles`
