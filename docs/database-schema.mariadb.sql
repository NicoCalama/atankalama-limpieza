-- ============================================================================
-- Atankalama Limpieza — Schema MariaDB (despliegue cPanel, BD compartida)
-- Traducción de docs/database-schema.sql (SQLite) al dialecto MariaDB 10.11.
--
-- Convenciones de la migración:
--   - Token de prefijo `#__` en CADA nombre de tabla (CREATE/REFERENCES/INDEX).
--     Database::applyPrefix() lo reemplaza por DB_PREFIX (= 'limpieza_' en prod),
--     así estas tablas conviven con las `maisterchef_*` en cat6852_australia.
--   - id: INT AUTO_INCREMENT PRIMARY KEY ; columnas FK de id: INT.
--   - Booleans: TINYINT 0/1 (se conservan los CHECK; MariaDB 10.2+ los aplica).
--   - Fechas/horas: VARCHAR(30) en ISO-8601 (igual que SQLite, NO DATETIME) para
--     no cambiar el formato que usa la app. El valor lo provee la app vía
--     Database::now(); el DEFAULT de abajo es respaldo si un INSERT lo omite.
--   - Motor InnoDB + utf8mb4 (FK reales, igual que Maisterchef).
--
-- IMPORTANTE (validar contra un MariaDB real antes del deploy):
--   El DEFAULT de timestamps usa una expresión [DEFAULT (CONCAT(REPLACE(
--   UTC_TIMESTAMP(3),' ','T'),'Z')))] soportada en MariaDB 10.2.1+. Si el hosting
--   la rechazara, reemplazar por `NOT NULL` sin default (la app ya setea now()).
--
-- El schema NO crea CREATE DATABASE: la base cat6852_australia ya existe y es
-- compartida. init-db.php aplica este archivo statement por statement.
-- ============================================================================

-- ============================================================================
-- BLOQUE 1 — RBAC / AUTH
-- ============================================================================

CREATE TABLE #__permisos (
    codigo       VARCHAR(100) PRIMARY KEY,
    descripcion  TEXT NOT NULL,
    categoria    VARCHAR(100) NOT NULL,
    scope        VARCHAR(20) NOT NULL CHECK (scope IN ('global', 'propio')),
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__roles (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(100) NOT NULL UNIQUE,
    descripcion  TEXT,
    es_sistema   TINYINT NOT NULL DEFAULT 0 CHECK (es_sistema IN (0, 1)),
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__rol_permisos (
    rol_id           INT NOT NULL,
    permiso_codigo   VARCHAR(100) NOT NULL,
    created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    PRIMARY KEY (rol_id, permiso_codigo),
    FOREIGN KEY (rol_id) REFERENCES #__roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permiso_codigo) REFERENCES #__permisos(codigo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__usuarios (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    rut                   VARCHAR(20) NOT NULL UNIQUE,
    nombre                VARCHAR(150) NOT NULL,
    email                 VARCHAR(190),
    password_hash         VARCHAR(255) NOT NULL,
    requiere_cambio_pwd   TINYINT NOT NULL DEFAULT 1 CHECK (requiere_cambio_pwd IN (0, 1)),
    activo                TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    hotel_default         VARCHAR(10) CHECK (hotel_default IN ('1_sur', 'inn', 'ambos')),
    tema_preferido        VARCHAR(10) NOT NULL DEFAULT 'auto' CHECK (tema_preferido IN ('auto', 'claro', 'oscuro')),
    created_at            VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_at            VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    last_login_at         VARCHAR(30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_usuarios_rut ON #__usuarios(rut);
CREATE INDEX idx_usuarios_activo ON #__usuarios(activo);

CREATE TABLE #__usuarios_roles (
    usuario_id   INT NOT NULL,
    rol_id       INT NOT NULL,
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    PRIMARY KEY (usuario_id, rol_id),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (rol_id) REFERENCES #__roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__sesiones (
    token        VARCHAR(128) PRIMARY KEY,
    usuario_id   INT NOT NULL,
    ip           VARCHAR(45),
    user_agent   TEXT,
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    expires_at   VARCHAR(30) NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_sesiones_usuario ON #__sesiones(usuario_id);
CREATE INDEX idx_sesiones_expires ON #__sesiones(expires_at);

CREATE TABLE #__contrasenas_temporales (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id       INT NOT NULL,
    generada_por     INT,
    motivo           VARCHAR(20) NOT NULL CHECK (motivo IN ('creacion', 'reset_admin', 'olvido')),
    usada            TINYINT NOT NULL DEFAULT 0 CHECK (usada IN (0, 1)),
    created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    usada_at         VARCHAR(30),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (generada_por) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_contrasenas_usuario ON #__contrasenas_temporales(usuario_id);

CREATE TABLE #__intentos_login (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    clave        VARCHAR(80) NOT NULL,
    creado_at    VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_intentos_login_clave_creado ON #__intentos_login(clave, creado_at);

-- ============================================================================
-- BLOQUE 2 — OPERACIÓN
-- ============================================================================

CREATE TABLE #__hoteles (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    codigo       VARCHAR(50) NOT NULL UNIQUE,
    nombre       VARCHAR(150) NOT NULL,
    cloudbeds_property_id  VARCHAR(100),
    activo       TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    sabanas_cada_n_dias  INT NOT NULL DEFAULT 4,  -- cada cuántas noches avisar cambio de sábanas en stayover. Ver docs/ocupacion-y-sabanas.md
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__tipos_habitacion (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(100) NOT NULL UNIQUE,
    descripcion  TEXT,
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__habitaciones (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id                INT NOT NULL,
    numero                  VARCHAR(20) NOT NULL,
    tipo_habitacion_id      INT NOT NULL,
    cloudbeds_room_id       VARCHAR(100),
    estado                  VARCHAR(40) NOT NULL DEFAULT 'sucia' CHECK (estado IN (
        'sucia', 'en_progreso', 'completada_pendiente_auditoria',
        'aprobada', 'aprobada_con_observacion', 'rechazada'
    )),
    activa                  TINYINT NOT NULL DEFAULT 1 CHECK (activa IN (0, 1)),
    es_espacio_comun        TINYINT NOT NULL DEFAULT 0 CHECK (es_espacio_comun IN (0, 1)),  -- 1 = área común (piscina, pasillo…); sin Cloudbeds ni auditoría. Ver docs/areas-comunes.md
    -- Ocupación sincronizada desde Cloudbeds (getHousekeepingStatus). Es contexto: NO cambia 'estado'. Ver docs/ocupacion-y-sabanas.md
    cb_frontdesk_status     VARCHAR(20) CHECK (cb_frontdesk_status IN ('check-in', 'check-out', 'stayover', 'turnover', 'unused')),
    cb_ocupada              TINYINT CHECK (cb_ocupada IN (0, 1)),
    cb_arrival_date         VARCHAR(10),                          -- entrada del huésped actual (YYYY-MM-DD)
    cb_departure_date       VARCHAR(10),                          -- salida prevista (YYYY-MM-DD)
    cb_ocupacion_sync_at    VARCHAR(30),                          -- cuándo se refrescó la ocupación
    created_at              VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_at              VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    UNIQUE (hotel_id, numero),
    FOREIGN KEY (hotel_id) REFERENCES #__hoteles(id) ON DELETE RESTRICT,
    FOREIGN KEY (tipo_habitacion_id) REFERENCES #__tipos_habitacion(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_habitaciones_estado ON #__habitaciones(estado);
CREATE INDEX idx_habitaciones_hotel ON #__habitaciones(hotel_id);

CREATE TABLE #__turnos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nombre       VARCHAR(50) NOT NULL UNIQUE,
    hora_inicio  VARCHAR(5) NOT NULL,
    hora_fin     VARCHAR(5) NOT NULL,
    activo       TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__usuarios_turnos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT NOT NULL,
    turno_id     INT NOT NULL,
    fecha        VARCHAR(10) NOT NULL,
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    UNIQUE (usuario_id, fecha),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (turno_id) REFERENCES #__turnos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_usuarios_turnos_fecha ON #__usuarios_turnos(fecha);

CREATE TABLE #__asignaciones (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    habitacion_id   INT NOT NULL,
    usuario_id      INT NOT NULL,
    asignado_por    INT,
    orden_cola      INT NOT NULL DEFAULT 0,
    fecha           VARCHAR(10) NOT NULL,
    franja          VARCHAR(10) CHECK (franja IN ('mañana', 'tarde', 'noche')),  -- ventana de la limpieza (día/noche); NULL = sin etiqueta. Ver docs/limpiezas-multiples-dia.md
    activa          TINYINT NOT NULL DEFAULT 1 CHECK (activa IN (0, 1)),
    created_at      VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (habitacion_id) REFERENCES #__habitaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (asignado_por) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_asignaciones_usuario_fecha ON #__asignaciones(usuario_id, fecha);
CREATE INDEX idx_asignaciones_habitacion ON #__asignaciones(habitacion_id);
CREATE INDEX idx_asignaciones_activa ON #__asignaciones(activa);

CREATE TABLE #__checklists_template (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    tipo_habitacion_id    INT NOT NULL,
    habitacion_id         INT,                                 -- si != NULL, template propio de un espacio (área común); las piezas de huésped lo dejan NULL y se resuelven por tipo. Ver docs/areas-comunes.md
    nombre                VARCHAR(150) NOT NULL,
    activo                TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at            VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_at            VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (tipo_habitacion_id) REFERENCES #__tipos_habitacion(id) ON DELETE RESTRICT,
    FOREIGN KEY (habitacion_id) REFERENCES #__habitaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_checklists_template_habitacion ON #__checklists_template(habitacion_id);

CREATE TABLE #__items_checklist (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    template_id         INT NOT NULL,
    orden               INT NOT NULL,
    descripcion         TEXT NOT NULL,
    obligatorio         TINYINT NOT NULL DEFAULT 1 CHECK (obligatorio IN (0, 1)),
    creditos            INT NOT NULL DEFAULT 1 CHECK (creditos >= 0),  -- peso de créditos del ítem; solo cuenta para créditos si obligatorio=1. Ver docs/creditos-rework.md
    es_cambio_sabanas   TINYINT NOT NULL DEFAULT 0 CHECK (es_cambio_sabanas IN (0, 1)),  -- 1 = ítem de sábanas; solo etiqueta en la UI (informativo). Ver docs/ocupacion-y-sabanas.md
    activo              TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (template_id) REFERENCES #__checklists_template(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_items_checklist_template ON #__items_checklist(template_id);

CREATE TABLE #__ejecuciones_checklist (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    habitacion_id       INT NOT NULL,
    asignacion_id       INT NOT NULL,
    usuario_id          INT NOT NULL,
    template_id         INT NOT NULL,
    estado              VARCHAR(20) NOT NULL DEFAULT 'en_progreso' CHECK (estado IN (
        'en_progreso', 'completada', 'auditada'
    )),
    timestamp_inicio    VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    timestamp_fin       VARCHAR(30),
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (habitacion_id) REFERENCES #__habitaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (asignacion_id) REFERENCES #__asignaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (template_id) REFERENCES #__checklists_template(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ejecuciones_habitacion ON #__ejecuciones_checklist(habitacion_id);
CREATE INDEX idx_ejecuciones_usuario ON #__ejecuciones_checklist(usuario_id);
CREATE INDEX idx_ejecuciones_estado ON #__ejecuciones_checklist(estado);

CREATE TABLE #__ejecuciones_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    ejecucion_id        INT NOT NULL,
    item_id             INT NOT NULL,
    marcado             TINYINT NOT NULL DEFAULT 0 CHECK (marcado IN (0, 1)),
    desmarcado_por_auditor  TINYINT NOT NULL DEFAULT 0 CHECK (desmarcado_por_auditor IN (0, 1)),
    marcado_por         INT NULL,   -- quién marcó el ítem: reparte créditos en re-limpieza (ver docs/creditos-rework.md)
    updated_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    UNIQUE (ejecucion_id, item_id),
    FOREIGN KEY (ejecucion_id) REFERENCES #__ejecuciones_checklist(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES #__items_checklist(id) ON DELETE RESTRICT,
    FOREIGN KEY (marcado_por) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_ejecuciones_items_ejecucion ON #__ejecuciones_items(ejecucion_id);

-- ============================================================================
-- BLOQUE 3 — AUDITORÍA
-- ============================================================================

CREATE TABLE #__auditorias (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    ejecucion_id        INT NOT NULL UNIQUE,
    habitacion_id       INT NOT NULL,
    auditor_id          INT NOT NULL,
    veredicto           VARCHAR(30) NOT NULL CHECK (veredicto IN (
        'aprobado', 'aprobado_con_observacion', 'rechazado'
    )),
    comentario          TEXT,
    items_desmarcados_json  TEXT,
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (ejecucion_id) REFERENCES #__ejecuciones_checklist(id) ON DELETE RESTRICT,
    FOREIGN KEY (habitacion_id) REFERENCES #__habitaciones(id) ON DELETE RESTRICT,
    FOREIGN KEY (auditor_id) REFERENCES #__usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_auditorias_habitacion ON #__auditorias(habitacion_id);
CREATE INDEX idx_auditorias_auditor ON #__auditorias(auditor_id);
CREATE INDEX idx_auditorias_veredicto ON #__auditorias(veredicto);

-- ============================================================================
-- BLOQUE 4 — ALERTAS
-- ============================================================================

CREATE TABLE #__alertas_activas (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    tipo                VARCHAR(40) NOT NULL CHECK (tipo IN (
        'cloudbeds_sync_failed',
        'trabajador_en_riesgo',
        'habitacion_rechazada',
        'fin_turno_pendientes',
        'trabajador_disponible',
        'ticket_nuevo',
        'habitacion_saltada'
    )),
    prioridad           INT NOT NULL CHECK (prioridad IN (0, 1, 2, 3)),
    titulo              VARCHAR(200) NOT NULL,
    descripcion         TEXT NOT NULL,
    contexto_json       TEXT,
    hotel_id            INT,
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (hotel_id) REFERENCES #__hoteles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_alertas_activas_tipo ON #__alertas_activas(tipo);
CREATE INDEX idx_alertas_activas_prioridad ON #__alertas_activas(prioridad);

CREATE TABLE #__bitacora_alertas (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    tipo                VARCHAR(40) NOT NULL,
    prioridad           INT NOT NULL,
    titulo              VARCHAR(200) NOT NULL,
    descripcion         TEXT NOT NULL,
    contexto_json       TEXT,
    hotel_id            INT,
    levantada_at        VARCHAR(30) NOT NULL,
    resuelta_at         VARCHAR(30),
    resolucion          VARCHAR(20) CHECK (resolucion IN ('auto', 'accion_usuario', 'descartada')),
    resuelta_por        INT,
    accion_tomada       TEXT,
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (hotel_id) REFERENCES #__hoteles(id) ON DELETE SET NULL,
    FOREIGN KEY (resuelta_por) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_bitacora_alertas_tipo ON #__bitacora_alertas(tipo);
CREATE INDEX idx_bitacora_alertas_levantada ON #__bitacora_alertas(levantada_at);

CREATE TABLE #__alertas_config (
    clave        VARCHAR(100) PRIMARY KEY,
    valor        TEXT NOT NULL,
    descripcion  TEXT,
    updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_by   INT,
    FOREIGN KEY (updated_by) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Apariencia configurable de la UI (Ajustes → Colores): colores de tarjetas por
-- estado y por hotel. Key-value, mismo patrón que alertas_config. Sin fila = se
-- usa el default de UiConfigService::DEFAULTS.
CREATE TABLE #__ui_config (
    clave        VARCHAR(100) PRIMARY KEY,
    valor        TEXT NOT NULL,
    updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_by   INT,
    FOREIGN KEY (updated_by) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- BLOQUE 5 — CLOUDBEDS
-- ============================================================================

CREATE TABLE #__cloudbeds_sync_historial (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tipo            VARCHAR(30) NOT NULL CHECK (tipo IN ('auto_cron', 'manual', 'escritura_estado')),
    hotel_id        INT,
    iniciada_at     VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    finalizada_at   VARCHAR(30),
    resultado       VARCHAR(20) NOT NULL DEFAULT 'en_progreso' CHECK (resultado IN (
        'en_progreso', 'exito', 'error', 'parcial'
    )),
    habitaciones_sincronizadas  INT NOT NULL DEFAULT 0,
    errores_count   INT NOT NULL DEFAULT 0,
    payload_request TEXT,
    payload_response    TEXT,
    error_mensaje   TEXT,
    disparada_por   INT,
    FOREIGN KEY (hotel_id) REFERENCES #__hoteles(id) ON DELETE SET NULL,
    FOREIGN KEY (disparada_por) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_cloudbeds_sync_iniciada ON #__cloudbeds_sync_historial(iniciada_at);
CREATE INDEX idx_cloudbeds_sync_resultado ON #__cloudbeds_sync_historial(resultado);

CREATE TABLE #__cloudbeds_config (
    clave        VARCHAR(100) PRIMARY KEY,
    valor        TEXT NOT NULL,
    descripcion  TEXT,
    updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_by   INT,
    FOREIGN KEY (updated_by) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- BLOQUE 6 — TICKETS
-- ============================================================================

CREATE TABLE #__tickets (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    habitacion_id    INT,
    hotel_id         INT NOT NULL,
    titulo           VARCHAR(200) NOT NULL,
    descripcion      TEXT NOT NULL,
    prioridad        VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK (prioridad IN ('baja', 'normal', 'alta', 'urgente')),
    estado           VARCHAR(20) NOT NULL DEFAULT 'abierto' CHECK (estado IN (
        'abierto', 'en_progreso', 'resuelto', 'cerrado'
    )),
    levantado_por    INT NOT NULL,
    asignado_a       INT,
    created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    resuelto_at      VARCHAR(30),
    FOREIGN KEY (habitacion_id) REFERENCES #__habitaciones(id) ON DELETE SET NULL,
    FOREIGN KEY (hotel_id) REFERENCES #__hoteles(id) ON DELETE RESTRICT,
    FOREIGN KEY (levantado_por) REFERENCES #__usuarios(id) ON DELETE RESTRICT,
    FOREIGN KEY (asignado_a) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_tickets_estado ON #__tickets(estado);
CREATE INDEX idx_tickets_hotel ON #__tickets(hotel_id);
CREATE INDEX idx_tickets_levantado_por ON #__tickets(levantado_por);

-- ============================================================================
-- BLOQUE 7 — LOGS
-- ============================================================================

CREATE TABLE #__logs_eventos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nivel        VARCHAR(10) NOT NULL CHECK (nivel IN ('INFO', 'WARNING', 'ERROR')),
    modulo       VARCHAR(50) NOT NULL,
    mensaje      TEXT NOT NULL,
    contexto_json    TEXT,
    usuario_id   INT,
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_logs_eventos_nivel ON #__logs_eventos(nivel);
CREATE INDEX idx_logs_eventos_modulo ON #__logs_eventos(modulo);
CREATE INDEX idx_logs_eventos_created ON #__logs_eventos(created_at);

CREATE TABLE #__audit_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id       INT,
    accion           VARCHAR(100) NOT NULL,
    entidad          VARCHAR(50),
    entidad_id       INT,
    detalles_json    TEXT,
    origen           VARCHAR(20) NOT NULL DEFAULT 'ui' CHECK (origen IN ('ui', 'copilot', 'api', 'cron', 'script')),
    ip               VARCHAR(45),
    created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_audit_log_usuario ON #__audit_log(usuario_id);
CREATE INDEX idx_audit_log_accion ON #__audit_log(accion);
CREATE INDEX idx_audit_log_entidad ON #__audit_log(entidad, entidad_id);
CREATE INDEX idx_audit_log_created ON #__audit_log(created_at);

-- ============================================================================
-- BLOQUE 8 — COPILOT IA
-- ============================================================================

CREATE TABLE #__copilot_conversaciones (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT NOT NULL,
    titulo       VARCHAR(200),
    created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_copilot_conversaciones_usuario ON #__copilot_conversaciones(usuario_id);

CREATE TABLE #__copilot_mensajes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    conversacion_id     INT NOT NULL,
    rol                 VARCHAR(20) NOT NULL CHECK (rol IN ('user', 'assistant', 'tool')),
    contenido           TEXT NOT NULL,
    tool_name           VARCHAR(100),
    tool_payload_json   TEXT,
    tokens_input        INT,
    tokens_output       INT,
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (conversacion_id) REFERENCES #__copilot_conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_copilot_mensajes_conversacion ON #__copilot_mensajes(conversacion_id);

-- ============================================================================
-- BLOQUE 9 — NOTIFICACIONES
-- ============================================================================

CREATE TABLE #__notificaciones_disponibilidad (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    trabajador_id       INT NOT NULL,
    fecha               VARCHAR(10) NOT NULL,
    created_at          VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    UNIQUE (trabajador_id, fecha),
    FOREIGN KEY (trabajador_id) REFERENCES #__usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE #__push_subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    endpoint    TEXT NOT NULL,
    p256dh      VARCHAR(255) NOT NULL,
    auth        VARCHAR(255) NOT NULL,
    created_at  VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    -- prefijo 191: seguro en utf8mb4 aun con ROW_FORMAT antiguo (191*4=764 < 767 bytes)
    UNIQUE (usuario_id, endpoint(191)),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_push_subscriptions_usuario ON #__push_subscriptions(usuario_id);

CREATE TABLE #__notificaciones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    tipo        VARCHAR(30) NOT NULL DEFAULT 'general',
    titulo      VARCHAR(200) NOT NULL,
    cuerpo      TEXT NOT NULL,
    url         VARCHAR(255) NOT NULL DEFAULT '/home',
    leida       TINYINT NOT NULL DEFAULT 0 CHECK (leida IN (0, 1)),
    created_at  VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    FOREIGN KEY (usuario_id) REFERENCES #__usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_notificaciones_usuario ON #__notificaciones(usuario_id, leida);
CREATE INDEX idx_notificaciones_created ON #__notificaciones(created_at);

-- ============================================================================
-- FIN DEL SCHEMA — 32 tablas (paridad con database-schema.sql)
-- ============================================================================
