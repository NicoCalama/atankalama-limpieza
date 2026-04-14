# Turnos

**Versión:** 1.0 — 2026-04-14

Documenta los turnos de trabajo, su asignación a usuarios y las reglas de cálculo "en turno / fuera de turno / disponible".

---

## 1. Turnos base (MVP)

Dos turnos por defecto (seeder):

| Nombre | Hora inicio | Hora fin |
|---|---|---|
| `mañana` | `08:00` | `16:00` |
| `tarde` | `14:00` | `22:00` |

**Overlap** 14:00-16:00 es intencional (hand-off de turnos). Un trabajador no está en ambos turnos en el mismo día — la overlap existe a nivel de cobertura del equipo, no de individuos.

---

## 2. Modelo

### 2.1 `turnos` (catálogo)

- `id`, `nombre` (UNIQUE), `hora_inicio`, `hora_fin` (formato `HH:MM`).
- Editables con `turnos.crear_editar`.

### 2.2 `usuarios_turnos` (asignación diaria)

- `usuario_id`, `turno_id`, `fecha` (YYYY-MM-DD).
- UNIQUE (usuario_id, fecha) — un usuario tiene **un solo turno por día**.
- Asignable con `turnos.asignar_a_usuario`.

---

## 3. Flujo de asignación

### 3.1 Asignación semanal/diaria

- Admin / Supervisora (con `turnos.asignar_a_usuario`) asigna turnos desde Ajustes → Turnos.
- UI: calendario semanal con trabajadores en filas, días en columnas. Click en celda → selector de turno.

### 3.2 Asignación masiva

- Botón "Copiar semana anterior" — duplica la asignación de la semana pasada.
- Botón "Limpiar semana" — borra asignaciones de la semana (con confirmación).

### 3.3 Sin asignación

Si un trabajador no tiene fila en `usuarios_turnos` para una fecha → se considera **fuera de turno** ese día. No aparece en cálculos de alertas predictivas, no recibe asignaciones automáticas de round-robin.

---

## 4. Estados derivados

Para un trabajador y un momento dado:

```
AHORA = now() en America/Santiago
turno_hoy = SELECT * FROM usuarios_turnos WHERE usuario_id = ? AND fecha = CURDATE()

SI turno_hoy IS NULL:
    estado = 'fuera_de_turno'
ELSIF AHORA < turno.hora_inicio:
    estado = 'pre_turno'
ELSIF AHORA > turno.hora_fin:
    estado = 'post_turno'
ELSE:
    SI tiene habitaciones_activas > 0:
        estado = 'activo'
    ELSE:
        estado = 'disponible'
```

### 4.1 "disponible"

El trabajador está en turno pero terminó su cola. Puede pedir más habitaciones con `disponibilidad.notificar_supervisora`. Eso dispara alerta P2 `trabajador_disponible`.

### 4.2 Uso en Home Supervisora

El "Estado del Equipo" muestra a cada trabajador con su estado derivado. Ver [home-supervisora.md](home-supervisora.md) §5.

---

## 5. Reglas especiales

### 5.1 Cambio de turno a mitad de día

No soportado en MVP. Si el turno cambia mid-day, hay que editar la fila en `usuarios_turnos` (requiere `turnos.asignar_a_usuario`).

### 5.2 Overtime

No tracking formal en MVP. Si un trabajador trabaja después de `hora_fin`, el estado pasa a `post_turno` pero puede seguir completando habitaciones. Solo efecto: deja de estar en los cálculos de alertas predictivas.

### 5.3 Turnos fuera del horario comercial

MVP soporta turnos que cruzan medianoche (ej: 22:00-06:00) solo si se modela como dos filas separadas (22:00-23:59 del día A + 00:00-06:00 del día B). Esto es feo pero evita complejidad de manejo de fechas.

**Para atankalama MVP:** los dos turnos están dentro de un mismo día, no hay overnight shifts.

---

## 6. Endpoints

| Método | Endpoint | Permiso | Descripción |
|---|---|---|---|
| GET | `/api/turnos` | `turnos.ver` | Lista catálogo |
| POST | `/api/turnos` | `turnos.crear_editar` | Crear turno nuevo |
| PUT | `/api/turnos/{id}` | `turnos.crear_editar` | Editar |
| GET | `/api/usuarios-turnos` | `turnos.ver` | Query: `?fecha_inicio&fecha_fin&usuario_id` |
| POST | `/api/usuarios-turnos` | `turnos.asignar_a_usuario` | Asignar turno a usuario |
| DELETE | `/api/usuarios-turnos/{id}` | `turnos.asignar_a_usuario` | Quitar asignación |
| POST | `/api/usuarios-turnos/copiar-semana` | `turnos.asignar_a_usuario` | Copia semana anterior |

---

## 7. Referencias cruzadas

- [alertas-predictivas.md](alertas-predictivas.md) §4 — `tiempo_restante_turno` usa `usuarios_turnos.hora_fin`
- [habitaciones.md](habitaciones.md) §6.2 — round-robin usa trabajadores con turno
- [database-schema.sql](database-schema.sql) — tablas `turnos`, `usuarios_turnos`
- [roles-permisos.md](roles-permisos.md) §2.8
- [ajustes.md](ajustes.md) — UI de gestión
