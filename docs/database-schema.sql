-- ============================================================================
-- Atankalama Limpieza — Schema SQLite
-- Versión: 1.0 — 2026-04-14
-- Motor: SQLite 3.35+ (requiere soporte de CHECK y FK)
-- Encoding: UTF-8
--
-- Este archivo es la FUENTE DE VERDAD del esquema de datos.
-- Cualquier cambio debe reflejarse aquí antes de crear migraciones.
--
-- Orden de creación: respeta dependencias de FK (padres antes que hijos).
-- Todas las fechas/horas en ISO 8601 (TEXT). Zona horaria: America/Santiago.
-- Booleans como INTEGER 0/1.
--
-- Convenciones:
--   - snake_case para tablas y columnas
--   - PK = id INTEGER PRIMARY KEY AUTOINCREMENT (salvo catálogos con código)
--   - created_at / updated_at en TEXT (ISO 8601)
--   - FK siempre con ON DELETE explícito
-- ============================================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- ============================================================================
-- BLOQUE 1 — RBAC / AUTH
-- ============================================================================

-- Catálogo de permisos (ver docs/roles-permisos.md)
-- Se puebla vía seeder database/seeds/permisos.php con INSERT OR IGNORE.
CREATE TABLE permisos (
    codigo       TEXT PRIMARY KEY,           -- ej: 'habitaciones.ver_todas'
    descripcion  TEXT NOT NULL,
    categoria    TEXT NOT NULL,              -- ej: 'Habitaciones', 'Auditoría'
    scope        TEXT NOT NULL CHECK (scope IN ('global', 'propio')),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- Roles del sistema (4 por defecto: Trabajador, Supervisora, Recepción, Admin)
-- Editables desde Ajustes (crear/renombrar/eliminar).
CREATE TABLE roles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre       TEXT NOT NULL UNIQUE,
    descripcion  TEXT,
    es_sistema   INTEGER NOT NULL DEFAULT 0 CHECK (es_sistema IN (0, 1)),  -- 1 = no eliminable
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- Matriz rol × permiso (editable desde Ajustes → Roles y Permisos)
CREATE TABLE rol_permisos (
    rol_id           INTEGER NOT NULL,
    permiso_codigo   TEXT NOT NULL,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (rol_id, permiso_codigo),
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permiso_codigo) REFERENCES permisos(codigo) ON DELETE CASCADE
);

-- Usuarios del sistema
CREATE TABLE usuarios (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    rut                   TEXT NOT NULL UNIQUE,        -- formato 12345678-9 sin puntos, con guión
    nombre                TEXT NOT NULL,
    email                 TEXT,                        -- opcional
    password_hash         TEXT NOT NULL,
    requiere_cambio_pwd   INTEGER NOT NULL DEFAULT 1 CHECK (requiere_cambio_pwd IN (0, 1)),
    activo                INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    hotel_default         TEXT CHECK (hotel_default IN ('1_sur', 'inn', 'ambos')),
    tema_preferido        TEXT NOT NULL DEFAULT 'auto' CHECK (tema_preferido IN ('auto', 'claro', 'oscuro')),
    created_at            TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at            TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    last_login_at         TEXT
);

CREATE INDEX idx_usuarios_rut ON usuarios(rut);
CREATE INDEX idx_usuarios_activo ON usuarios(activo);

-- Un usuario puede tener múltiples roles; permisos efectivos = unión
CREATE TABLE usuarios_roles (
    usuario_id   INTEGER NOT NULL,
    rol_id       INTEGER NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (usuario_id, rol_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- Sesiones activas (cookie HTTPOnly)
CREATE TABLE sesiones (
    token        TEXT PRIMARY KEY,                  -- token opaco generado con random_bytes
    usuario_id   INTEGER NOT NULL,
    ip           TEXT,
    user_agent   TEXT,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    expires_at   TEXT NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE INDEX idx_sesiones_usuario ON sesiones(usuario_id);
CREATE INDEX idx_sesiones_expires ON sesiones(expires_at);

-- Contraseñas temporales generadas al crear usuario o al reset
-- NO se guarda la pwd en claro; esta tabla solo deja traza del evento
CREATE TABLE contrasenas_temporales (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id       INTEGER NOT NULL,
    generada_por     INTEGER,                           -- NULL = sistema (auto), else = admin que la generó
    motivo           TEXT NOT NULL CHECK (motivo IN ('creacion', 'reset_admin', 'olvido')),
    usada            INTEGER NOT NULL DEFAULT 0 CHECK (usada IN (0, 1)),
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    usada_at         TEXT,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (generada_por) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_contrasenas_usuario ON contrasenas_temporales(usuario_id);

-- ============================================================================
-- BLOQUE 2 — OPERACIÓN (hoteles, habitaciones, turnos, asignaciones, checklist)
-- ============================================================================

-- Hoteles (2 por ahora: Atankalama 1 Sur, Atankalama Inn)
CREATE TABLE hoteles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo       TEXT NOT NULL UNIQUE,              -- '1_sur', 'inn'
    nombre       TEXT NOT NULL,
    cloudbeds_property_id  TEXT,                    -- ID del hotel en Cloudbeds
    activo       INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- Tipos de habitación (doble, matrimonial, suite, etc.)
CREATE TABLE tipos_habitacion (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre       TEXT NOT NULL UNIQUE,
    descripcion  TEXT,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- Habitaciones
-- Estado refleja el ciclo de vida: sucia → en_progreso → completada_pendiente_auditoria → aprobada/aprobada_con_observacion/rechazada
CREATE TABLE habitaciones (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    hotel_id                INTEGER NOT NULL,
    numero                  TEXT NOT NULL,                    -- '101', '203A', etc.
    tipo_habitacion_id      INTEGER NOT NULL,
    cloudbeds_room_id       TEXT,                             -- mapeo con Cloudbeds
    estado                  TEXT NOT NULL DEFAULT 'sucia' CHECK (estado IN (
        'sucia', 'en_progreso', 'completada_pendiente_auditoria',
        'aprobada', 'aprobada_con_observacion', 'rechazada'
    )),
    activa                  INTEGER NOT NULL DEFAULT 1 CHECK (activa IN (0, 1)),
    created_at              TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at              TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (hotel_id, numero),
    FOREIGN KEY (hotel_id) REFERENCES hoteles(id) ON DELETE RESTRICT,
    FOREIGN KEY (tipo_habitacion_id) REFERENCES tipos_habitacion(id) ON DELETE RESTRICT
);

CREATE INDEX idx_habitaciones_estado ON habitaciones(estado);
CREATE INDEX idx_habitaciones_hotel ON habitaciones(hotel_id);

-- Turnos (mañana 08:00-16:00, tarde 14:00-22:00 — configurables)
CREATE TABLE turnos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre       TEXT NOT NULL UNIQUE,                  -- 'mañana', 'tarde'
    hora_inicio  TEXT NOT NULL,                         -- 'HH:MM'
    hora_fin     TEXT NOT NULL,                         -- 'HH:MM'
    activo       INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
);

-- Asignación de turno a un trabajador para un día específico
CREATE TABLE usuarios_turnos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id   INTEGER NOT NULL,
    turno_id     INTEGER NOT NULL,
    fecha        TEXT NOT NULL,                         -- 'YYYY-MM-DD'
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (usuario_id, fecha),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE RESTRICT
);

CREATE INDEX idx_usuarios_turnos_fecha ON usuarios_turnos(fecha);

-- Asignaciones de habitación a trabajador
CREATE TABLE asignaciones (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    habitacion_id   INTEGER NOT NULL,
    usuario_id      INTEGER NOT NULL,                   -- trabajador asignado
    asignado_por    INTEGER,                            -- NULL = auto (round-robin), else = supervisora
    orden_cola      INTEGER NOT NULL DEFAULT 0,         -- posición en la cola del trabajador
    fecha           TEXT NOT NULL,                      -- 'YYYY-MM-DD' del turno
    activa          INTEGER NOT NULL DEFAULT 1 CHECK (activa IN (0, 1)),  -- 0 si se reasignó
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (habitacion_id) REFERENCES habitaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (asignado_por) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_asignaciones_usuario_fecha ON asignaciones(usuario_id, fecha);
CREATE INDEX idx_asignaciones_habitacion ON asignaciones(habitacion_id);
CREATE INDEX idx_asignaciones_activa ON asignaciones(activa);

-- Templates de checklist por tipo de habitación
CREATE TABLE checklists_template (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo_habitacion_id    INTEGER NOT NULL,
    nombre                TEXT NOT NULL,
    activo                INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at            TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at            TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (tipo_habitacion_id) REFERENCES tipos_habitacion(id) ON DELETE RESTRICT
);

-- Items del template (orden, descripción, obligatoriedad)
CREATE TABLE items_checklist (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id         INTEGER NOT NULL,
    orden               INTEGER NOT NULL,
    descripcion         TEXT NOT NULL,
    obligatorio         INTEGER NOT NULL DEFAULT 1 CHECK (obligatorio IN (0, 1)),
    activo              INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (template_id) REFERENCES checklists_template(id) ON DELETE CASCADE
);

CREATE INDEX idx_items_checklist_template ON items_checklist(template_id);

-- Ejecución concreta de un checklist (una por habitación × asignación)
-- timestamp_inicio / timestamp_fin son OCULTOS al trabajador (tracking interno)
CREATE TABLE ejecuciones_checklist (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    habitacion_id       INTEGER NOT NULL,
    asignacion_id       INTEGER NOT NULL,
    usuario_id          INTEGER NOT NULL,                -- trabajador que la ejecuta
    template_id         INTEGER NOT NULL,
    estado              TEXT NOT NULL DEFAULT 'en_progreso' CHECK (estado IN (
        'en_progreso', 'completada', 'auditada'
    )),
    timestamp_inicio    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    timestamp_fin       TEXT,                            -- se setea al marcar "habitación terminada"
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (habitacion_id) REFERENCES habitaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (template_id) REFERENCES checklists_template(id) ON DELETE RESTRICT
);

CREATE INDEX idx_ejecuciones_habitacion ON ejecuciones_checklist(habitacion_id);
CREATE INDEX idx_ejecuciones_usuario ON ejecuciones_checklist(usuario_id);
CREATE INDEX idx_ejecuciones_estado ON ejecuciones_checklist(estado);

-- Estado tap-a-tap de cada item dentro de una ejecución
-- Se inserta/actualiza al instante con cada marca/desmarca del trabajador
CREATE TABLE ejecuciones_items (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    ejecucion_id        INTEGER NOT NULL,
    item_id             INTEGER NOT NULL,
    marcado             INTEGER NOT NULL DEFAULT 0 CHECK (marcado IN (0, 1)),
    desmarcado_por_auditor  INTEGER NOT NULL DEFAULT 0 CHECK (desmarcado_por_auditor IN (0, 1)),
    updated_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE (ejecucion_id, item_id),
    FOREIGN KEY (ejecucion_id) REFERENCES ejecuciones_checklist(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items_checklist(id) ON DELETE RESTRICT
);

CREATE INDEX idx_ejecuciones_items_ejecucion ON ejecuciones_items(ejecucion_id);

-- ============================================================================
-- BLOQUE 3 — AUDITORÍA
-- ============================================================================

-- Una auditoría por ejecución. INMUTABLE post-veredicto (no se permite re-auditar).
-- El endpoint POST /api/auditoria/{habitacion_id} responde 409 si ya existe.
CREATE TABLE auditorias (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    ejecucion_id        INTEGER NOT NULL UNIQUE,         -- inmutabilidad vía UNIQUE
    habitacion_id       INTEGER NOT NULL,
    auditor_id          INTEGER NOT NULL,
    veredicto           TEXT NOT NULL CHECK (veredicto IN (
        'aprobado', 'aprobado_con_observacion', 'rechazado'
    )),
    comentario          TEXT,
    items_desmarcados_json  TEXT,                         -- JSON array de item_ids (para observación)
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (ejecucion_id) REFERENCES ejecuciones_checklist(id) ON DELETE RESTRICT,
    FOREIGN KEY (habitacion_id) REFERENCES habitaciones(id) ON DELETE RESTRICT,
    FOREIGN KEY (auditor_id) REFERENCES usuarios(id) ON DELETE RESTRICT
);

CREATE INDEX idx_auditorias_habitacion ON auditorias(habitacion_id);
CREATE INDEX idx_auditorias_auditor ON auditorias(auditor_id);
CREATE INDEX idx_auditorias_veredicto ON auditorias(veredicto);

-- ============================================================================
-- BLOQUE 4 — ALERTAS (predictivas operativas + técnicas)
-- ============================================================================

-- Alertas activas en el momento (una fila = una alerta viva)
-- Al resolverse (manual o automático) se borra la fila; queda registro en bitacora_alertas.
CREATE TABLE alertas_activas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL CHECK (tipo IN (
        'cloudbeds_sync_failed',
        'trabajador_en_riesgo',
        'habitacion_rechazada',
        'fin_turno_pendientes',
        'trabajador_disponible',
        'ticket_nuevo'
    )),
    prioridad           INTEGER NOT NULL CHECK (prioridad IN (0, 1, 2, 3)),
    titulo              TEXT NOT NULL,
    descripcion         TEXT NOT NULL,
    contexto_json       TEXT,                             -- datos específicos (habitacion_id, usuario_id, etc.)
    hotel_id            INTEGER,                          -- NULL = ambos / técnica
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (hotel_id) REFERENCES hoteles(id) ON DELETE CASCADE
);

CREATE INDEX idx_alertas_activas_tipo ON alertas_activas(tipo);
CREATE INDEX idx_alertas_activas_prioridad ON alertas_activas(prioridad);

-- Bitácora histórica: toda alerta que existió + las acciones tomadas sobre ella
CREATE TABLE bitacora_alertas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL,
    prioridad           INTEGER NOT NULL,
    titulo              TEXT NOT NULL,
    descripcion         TEXT NOT NULL,
    contexto_json       TEXT,
    hotel_id            INTEGER,
    levantada_at        TEXT NOT NULL,
    resuelta_at         TEXT,
    resolucion          TEXT CHECK (resolucion IN ('auto', 'accion_usuario', 'descartada')),
    resuelta_por        INTEGER,
    accion_tomada       TEXT,                             -- descripción libre
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (hotel_id) REFERENCES hoteles(id) ON DELETE SET NULL,
    FOREIGN KEY (resuelta_por) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_bitacora_alertas_tipo ON bitacora_alertas(tipo);
CREATE INDEX idx_bitacora_alertas_levantada ON bitacora_alertas(levantada_at);

-- Configuración de umbrales (editable desde Ajustes por roles con alertas.configurar_umbrales)
CREATE TABLE alertas_config (
    clave        TEXT PRIMARY KEY,                         -- ej: 'margen_seguridad_minutos'
    valor        TEXT NOT NULL,                            -- siempre TEXT, parsear según contexto
    descripcion  TEXT,
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_by   INTEGER,
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- ============================================================================
-- BLOQUE 5 — CLOUDBEDS
-- ============================================================================

-- Histórico de sincronizaciones con Cloudbeds (cron 2x/día + manual)
CREATE TABLE cloudbeds_sync_historial (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo            TEXT NOT NULL CHECK (tipo IN ('auto_cron', 'manual', 'escritura_estado')),
    hotel_id        INTEGER,                               -- NULL = sync global
    iniciada_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    finalizada_at   TEXT,
    resultado       TEXT NOT NULL DEFAULT 'en_progreso' CHECK (resultado IN (
        'en_progreso', 'exito', 'error', 'parcial'
    )),
    habitaciones_sincronizadas  INTEGER NOT NULL DEFAULT 0,
    errores_count   INTEGER NOT NULL DEFAULT 0,
    payload_request TEXT,                                  -- JSON sanitizado (sin tokens)
    payload_response    TEXT,                              -- JSON sanitizado
    error_mensaje   TEXT,
    disparada_por   INTEGER,                               -- NULL = cron
    FOREIGN KEY (hotel_id) REFERENCES hoteles(id) ON DELETE SET NULL,
    FOREIGN KEY (disparada_por) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_cloudbeds_sync_iniciada ON cloudbeds_sync_historial(iniciada_at);
CREATE INDEX idx_cloudbeds_sync_resultado ON cloudbeds_sync_historial(resultado);

-- Configuración de Cloudbeds (credenciales NO van acá — van en .env)
-- Acá solo van settings editables desde Ajustes
CREATE TABLE cloudbeds_config (
    clave        TEXT PRIMARY KEY,                         -- ej: 'sync_cron_schedule', 'reintentos_max'
    valor        TEXT NOT NULL,
    descripcion  TEXT,
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_by   INTEGER,
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- ============================================================================
-- BLOQUE 6 — TICKETS (mantenimiento)
-- ============================================================================

CREATE TABLE tickets (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    habitacion_id    INTEGER,                              -- opcional: ticket puede ser general
    hotel_id         INTEGER NOT NULL,
    titulo           TEXT NOT NULL,
    descripcion      TEXT NOT NULL,
    prioridad        TEXT NOT NULL DEFAULT 'normal' CHECK (prioridad IN ('baja', 'normal', 'alta', 'urgente')),
    estado           TEXT NOT NULL DEFAULT 'abierto' CHECK (estado IN (
        'abierto', 'en_progreso', 'resuelto', 'cerrado'
    )),
    levantado_por    INTEGER NOT NULL,
    asignado_a       INTEGER,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    resuelto_at      TEXT,
    FOREIGN KEY (habitacion_id) REFERENCES habitaciones(id) ON DELETE SET NULL,
    FOREIGN KEY (hotel_id) REFERENCES hoteles(id) ON DELETE RESTRICT,
    FOREIGN KEY (levantado_por) REFERENCES usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (asignado_a) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_tickets_estado ON tickets(estado);
CREATE INDEX idx_tickets_hotel ON tickets(hotel_id);
CREATE INDEX idx_tickets_levantado_por ON tickets(levantado_por);

-- ============================================================================
-- BLOQUE 7 — LOGS
-- ============================================================================

-- Eventos técnicos del sistema (INFO, WARNING, ERROR)
-- NO loguear tokens, API keys, passwords, headers Authorization
CREATE TABLE logs_eventos (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    nivel        TEXT NOT NULL CHECK (nivel IN ('INFO', 'WARNING', 'ERROR')),
    modulo       TEXT NOT NULL,                            -- 'cloudbeds', 'auth', 'copilot', etc.
    mensaje      TEXT NOT NULL,
    contexto_json    TEXT,
    usuario_id   INTEGER,                                  -- NULL si sistema
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_logs_eventos_nivel ON logs_eventos(nivel);
CREATE INDEX idx_logs_eventos_modulo ON logs_eventos(modulo);
CREATE INDEX idx_logs_eventos_created ON logs_eventos(created_at);

-- Auditoría de acciones de usuarios (quién hizo qué, cuándo)
-- Distinto de logs_eventos: acá solo acciones de negocio deliberadas
CREATE TABLE audit_log (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id       INTEGER,                              -- NULL solo si sistema / cron
    accion           TEXT NOT NULL,                        -- ej: 'auditoria.aprobar', 'usuario.crear'
    entidad          TEXT,                                 -- ej: 'habitacion', 'usuario'
    entidad_id       INTEGER,
    detalles_json    TEXT,
    origen           TEXT NOT NULL DEFAULT 'ui' CHECK (origen IN ('ui', 'copilot', 'api', 'cron', 'script')),
    ip               TEXT,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE INDEX idx_audit_log_usuario ON audit_log(usuario_id);
CREATE INDEX idx_audit_log_accion ON audit_log(accion);
CREATE INDEX idx_audit_log_entidad ON audit_log(entidad, entidad_id);
CREATE INDEX idx_audit_log_created ON audit_log(created_at);

-- ============================================================================
-- BLOQUE 8 — COPILOT IA
-- ============================================================================

-- Una conversación = una sesión de chat con el copilot
CREATE TABLE copilot_conversaciones (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id   INTEGER NOT NULL,
    titulo       TEXT,                                     -- generado automáticamente del primer mensaje
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE INDEX idx_copilot_conversaciones_usuario ON copilot_conversaciones(usuario_id);

-- Mensajes de una conversación (user + assistant + tool_use + tool_result)
CREATE TABLE copilot_mensajes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    conversacion_id     INTEGER NOT NULL,
    rol                 TEXT NOT NULL CHECK (rol IN ('user', 'assistant', 'tool')),
    contenido           TEXT NOT NULL,
    tool_name           TEXT,                              -- si rol='tool' o assistant con tool_use
    tool_payload_json   TEXT,
    tokens_input        INTEGER,
    tokens_output       INTEGER,
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (conversacion_id) REFERENCES copilot_conversaciones(id) ON DELETE CASCADE
);

CREATE INDEX idx_copilot_mensajes_conversacion ON copilot_mensajes(conversacion_id);

-- ============================================================================
-- FIN DEL SCHEMA
-- Total de tablas: 24
--   Bloque 1 (RBAC/Auth):  6  (permisos, roles, rol_permisos, usuarios, usuarios_roles, sesiones, contrasenas_temporales → 7)
--   Bloque 2 (Operación):  10 (hoteles, tipos_habitacion, habitaciones, turnos, usuarios_turnos,
--                              asignaciones, checklists_template, items_checklist,
--                              ejecuciones_checklist, ejecuciones_items)
--   Bloque 3 (Auditoría):  1  (auditorias)
--   Bloque 4 (Alertas):    3  (alertas_activas, bitacora_alertas, alertas_config)
--   Bloque 5 (Cloudbeds):  2  (cloudbeds_sync_historial, cloudbeds_config)
--   Bloque 6 (Tickets):    1  (tickets)
--   Bloque 7 (Logs):       2  (logs_eventos, audit_log)
--   Bloque 8 (Copilot):    2  (copilot_conversaciones, copilot_mensajes)
--   TOTAL: 26 tablas
-- ============================================================================
