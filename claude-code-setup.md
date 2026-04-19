# Claude Code Setup — Atankalama Aplicación Limpieza

**Proyecto:** `atankalama-limpieza`
**Entorno:** Windows + VS Code + Claude Code v2.1.92
**Modo de trabajo:** Autonomía Híbrida (c3) — ver `plan.md` sección 16.1
**Versión:** 2.1 (incluye decisiones del diseño detallado de la Home de la Supervisora)
**Fecha:** 08 de abril de 2026

> Este documento es la **guía operacional** del entorno de desarrollo donde Claude Code va a codificar el MVP de Atankalama Limpieza. Complementa a `plan.md`: mientras `plan.md` describe **qué** vamos a construir, este archivo describe **cómo** está configurado el entorno técnico y cómo trabajar con Claude Code sobre él.

---

## Tabla de contenidos

1. [Estado actual del setup](#1-estado-actual-del-setup)
2. [Pre-requisitos en Windows](#2-pre-requisitos-en-windows)
3. [Estructura de carpetas del proyecto](#3-estructura-de-carpetas-del-proyecto)
4. [Archivos base de seguridad](#4-archivos-base-de-seguridad)
5. [gitleaks](#5-gitleaks)
6. [El archivo CLAUDE.md raíz](#6-el-archivo-claudemd-raíz)
7. [MCPs instalados](#7-mcps-instalados)
8. [Skills personalizadas](#8-skills-personalizadas)
9. [Flujo de trabajo con Claude Code](#9-flujo-de-trabajo-con-claude-code)
10. [Orden recomendado de codificación de módulos](#10-orden-recomendado-de-codificación-de-módulos)
11. [Prompts de ejemplo para arrancar cada módulo](#11-prompts-de-ejemplo-para-arrancar-cada-módulo)

---

## 1. Estado actual del setup

**Setup 100% completo ✅** (Fase 0 cerrada).

**Progreso de codificación (a 2026-04-14):**
- ✅ **Etapa A — Fundación** (commit `b0542a4`): composer, schema SQLite aplicado, seeders (50 permisos, 4 roles, 2 hoteles, 2 turnos, admin inicial), servicios base (`Config`, `Database`, `Logger`, `LogSanitizer`, `RutValidator`, `PasswordService`).
- ✅ **Etapa B — Auth y RBAC dinámico** (commit `8ebbe68`): `Usuario` con `tienePermiso()`, `AuthService` (login RUT + sesiones sliding 8h + cambio forzado primer login), `RbacService` (CRUD roles/permisos), middleware `AuthCheck` + `PermissionCheck`, endpoints `/api/auth/*` y `/api/roles/*`, 60 tests.
- ✅ **Etapa C — Habitaciones y Cloudbeds** (commit `7906b71`): modelos Hotel/TipoHabitacion/Habitacion (6 estados), `EstadoHabitacionService` con matriz de transiciones, `CloudbedsClient` con reintentos exponenciales (3×) y 401→CREDENCIAL_INVALIDA, `CloudbedsSyncService` (sync 2×/día + escritura Clean + alertas P0 al fallar), endpoints `/api/hoteles`, `/api/habitaciones`, `/api/cloudbeds/*`, script CLI `scripts/sync-cloudbeds.php`. 89 tests totales (272 assertions).

**Siguiente**: Etapa D — Checklists, asignaciones y auditoría (ver §10).

---

## 2. Pre-requisitos en Windows

Ya verificados e instalados:

- **Git for Windows** 2.53.0 ✅
- **Node.js LTS** v24.14.0 ✅
- **npm** 11.9.0 (desbloqueado, política `RemoteSigned` aplicada) ✅
- **PHP** 8.2.30 (ZTS Visual C++ 2019 x64) ✅
- **VS Code** 1.114.0 ✅
- **Extensión Claude Code** v2.1.92 ✅

**Falta instalar:**
- **gitleaks** — se instalará vía `winget install gitleaks` durante el setup (ver sección 5)

---

## 3. Estructura de carpetas del proyecto

Esta es la estructura objetivo. Claude Code la creará durante el setup inicial.

```
atankalama-limpieza/
├── CLAUDE.md                    # Instrucciones para Claude Code (ver sección 6)
├── README.md                    # Documentación pública del proyecto
├── LICENSE                      # MIT (ya existe)
├── plan.md                      # Plan general v3.0
├── claude-code-setup.md         # Este archivo (v2.0)
├── .gitignore                   # Lo que NO va al repo
├── .env.example                 # Plantilla de variables de entorno
├── .mcp.json                    # Configuración de MCPs (ya existe)
├── .claude/                     # Config local de Claude Code (gitignored)
│   └── settings.local.json      # Token GitHub (ya existe, gitignored)
│
├── docs/                        # Especificaciones detalladas por módulo
│   ├── home-trabajador.md       # ✅ v1.0 COMPLETO
│   ├── home-supervisora.md      # ✅ v2.1 COMPLETO
│   ├── home-recepcion.md        # 🚧 siguiente
│   ├── home-admin.md            # ✅ COMPLETO
│   ├── handoff-2026-04-08.md    # ✅ documento de traspaso
│   ├── auth.md
│   ├── checklist.md
│   ├── habitaciones.md
│   ├── asignacion.md
│   ├── auditoria.md
│   ├── tickets.md
│   ├── usuarios.md
│   ├── roles-permisos.md        # ⭐ Matriz RBAC dinámica
│   ├── turnos.md
│   ├── alertas-predictivas.md
│   ├── ajustes.md
│   ├── copilot-ia.md
│   ├── cloudbeds.md
│   ├── database-schema.sql      # Schema SQLite ejecutable
│   └── api-endpoints.md         # Listado completo de endpoints REST
│
├── skills/                      # Skills personalizadas para Claude Code
│   ├── cloudbeds-api/
│   │   └── SKILL.md
│   ├── php-conventions/
│   │   └── SKILL.md
│   └── ui-components/
│       └── SKILL.md
│
├── public/                      # Punto de entrada web (DocumentRoot)
│   ├── index.php                # Front controller
│   ├── assets/
│   │   ├── css/                 # CSS personalizado (Tailwind viene por CDN)
│   │   ├── js/                  # JS custom (Alpine viene por CDN)
│   │   └── img/
│   └── uploads/                 # Fotos de tickets de mantenimiento (gitignored)
│       └── .gitkeep
│
├── src/                         # Código PHP del backend
│   ├── Core/                    # Router, Request, Response, App, Config
│   ├── Controllers/             # Un controller por módulo
│   ├── Models/                  # Modelos / acceso a BD
│   ├── Services/                # Lógica de negocio
│   │   ├── Auth/                # Hash, sesiones, RUT, generación de passwords
│   │   ├── Cloudbeds/           # Cliente Cloudbeds + cola de reintentos
│   │   ├── Copilot/             # Servicio del copilot IA (Claude API + tools)
│   │   ├── Rbac/                # Sistema de permisos dinámicos
│   │   ├── AlertasPredictivas/  # Cálculo y gestión de alertas
│   │   └── Checklist/           # Persistencia tap a tap, tracking de tiempo
│   ├── Middleware/              # Auth, PermissionCheck, RateLimit
│   ├── Helpers/                 # Funciones utilitarias
│   └── Views/                   # Templates PHP nativos (frontend)
│       ├── layouts/             # Layout base con header, sidebar, bottom nav, FAB
│       ├── partials/            # Componentes reusables (badges, botones, modales)
│       ├── auth/                # Login, cambio de password
│       ├── home/                # 4 versiones por rol
│       ├── habitaciones/
│       ├── checklist/
│       ├── asignacion/
│       ├── auditoria/
│       ├── tickets/
│       ├── usuarios/
│       ├── ajustes/
│       └── copilot/
│
├── database/
│   ├── migrations/              # Scripts SQL de migración
│   │   └── 001_schema_inicial.sql
│   ├── seeds/                   # Datos iniciales
│   │   ├── permisos.php         # ⭐ Catálogo maestro de permisos (RBAC)
│   │   ├── roles_default.php    # 4 roles por defecto con sus permisos
│   │   ├── turnos_default.php   # Diurno y Nocturno
│   │   ├── hoteles.php          # Atankalama Inn y Atankalama
│   │   ├── tipos_habitacion.php
│   │   └── admin_inicial.php    # Usuario admin bootstrap
│   └── atankalama.db            # Archivo SQLite (gitignored)
│
├── storage/
│   ├── logs/                    # Logs de aplicación (gitignored)
│   │   └── .gitkeep
│   └── sessions/                # Sesiones PHP (gitignored)
│       └── .gitkeep
│
├── tests/
│   ├── Unit/
│   └── Integration/
│
├── scripts/
│   ├── init-db.php              # Crea/recrea la BD desde migraciones + seeds
│   ├── sync-cloudbeds.php       # Cron 2×/día
│   ├── seed-demo-data.php       # Datos de demo para mostrar el MVP
│   └── reset-admin-password.php # Rescate de emergencia si se pierde el admin
│
└── vendor/                      # Composer (gitignored)
```

---

## 4. Archivos base de seguridad

### 4.1 `.gitignore` (versión completa)

El `.gitignore` actual creado por Claude Code es básico. Se debe reemplazar/completar con esta versión:

```gitignore
# === Variables de entorno ===
.env
.env.local
.env.*.local

# === Config local de Claude Code ===
.claude/settings.local.json

# === Base de datos SQLite ===
database/*.db
database/*.db-journal
database/*.db-wal
database/*.db-shm

# === Composer ===
vendor/
composer.lock

# === Node (solo para herramientas de desarrollo, NO para el frontend) ===
node_modules/
.npm
.yarn

# === Logs y storage ===
storage/logs/*
!storage/logs/.gitkeep
storage/sessions/*
!storage/sessions/.gitkeep

# === Uploads de usuarios ===
public/uploads/*
!public/uploads/.gitkeep

# === Build / cache ===
dist/
build/
*.log
*.cache
.phpunit.result.cache

# === Sistema operativo ===
.DS_Store
Thumbs.db
desktop.ini

# === IDEs ===
.vscode/settings.json
.idea/
*.swp
*.swo

# === Secretos detectados accidentalmente (defensa en profundidad) ===
**/secrets.json
**/credentials.json
```

**Nota importante:** `database/` como carpeta sí se trackea, pero los archivos `.db` adentro se excluyen. Las migraciones y seeds sí van al repo.

### 4.2 `.env.example`

```bash
# === Aplicación ===
APP_NAME="Atankalama Aplicacion Limpieza"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=America/Santiago

# === Sesiones ===
SESSION_SECRET=cambiar_por_string_aleatorio_largo_de_64_caracteres
SESSION_LIFETIME_MINUTES=480

# === Base de datos ===
DB_PATH=database/atankalama.db

# === Cloudbeds API — Atankalama Inn (Chorrillos 558) ===
CLOUDBEDS_API_KEY_INN=tu_api_key_atankalama_inn_aqui
CLOUDBEDS_PROPERTY_ID_INN=property_id_atankalama_inn

# === Cloudbeds API — Atankalama (1 Sur 858) ===
CLOUDBEDS_API_KEY_PRINCIPAL=tu_api_key_atankalama_principal_aqui
CLOUDBEDS_PROPERTY_ID_PRINCIPAL=property_id_atankalama_principal

# === Cloudbeds común ===
CLOUDBEDS_BASE_URL=https://api.cloudbeds.com/api/v1
CLOUDBEDS_TIMEOUT_SECONDS=10
CLOUDBEDS_MAX_RETRIES=3

# === Claude API (copilot IA) ===
CLAUDE_API_KEY=sk-ant-api03-tu_clave_aqui
CLAUDE_MODEL=claude-sonnet-4-6
CLAUDE_MAX_TOKENS=2048

# === Sincronización Cloudbeds (cron) ===
SYNC_HOUR_MORNING=09
SYNC_HOUR_EVENING=21

# === Alertas predictivas ===
ALERTA_PREDICTIVA_MARGEN_MINUTOS=15
ALERTA_PREDICTIVA_LIMPIEZAS_MINIMAS_HISTORIA=5
```

### 4.3 Archivos `.gitkeep`

Para que Git trackée las carpetas vacías:
- `storage/logs/.gitkeep`
- `storage/sessions/.gitkeep`
- `public/uploads/.gitkeep`

---

## 5. gitleaks

### 5.1 Instalación

```powershell
winget install gitleaks
```

Verificar con `gitleaks version`.

### 5.2 Hook pre-commit

Crear `.git/hooks/pre-commit` (sin extensión) con este contenido:

```bash
#!/bin/sh
echo "Ejecutando gitleaks..."
gitleaks protect --staged --redact --verbose
if [ $? -ne 0 ]; then
    echo ""
    echo "ERROR: gitleaks detecto posibles secretos en el commit."
    echo "Revisa los archivos marcados arriba antes de commitear."
    exit 1
fi
```

### 5.3 Prueba del hook

Durante el setup, Claude Code debe verificar que el hook funciona creando temporalmente un archivo con un secreto falso (ej: `test-secret.txt` con `CLOUDBEDS_API_KEY=sk-fake-1234567890abcdef`), intentando commitearlo, confirmando que gitleaks lo bloquea, y después borrando el archivo de prueba.

---

## 6. El archivo CLAUDE.md raíz

Este es el archivo más importante del setup. Claude Code lo lee automáticamente al iniciar y define las reglas del proyecto.

Debe crearse en la raíz del repo con el siguiente contenido:

````markdown
# CLAUDE.md — Instrucciones para Claude Code

Este documento contiene las reglas y convenciones para trabajar en el proyecto **Atankalama Aplicación Limpieza**. Léelo completo antes de codificar cualquier cosa.

## Sobre el proyecto

Aplicación web mobile-first en PHP 8.2 + SQLite para gestionar limpieza hotelera en las dos propiedades de Atankalama Corp (Calama, Chile). Reemplaza Flexkeeping. Integra con Cloudbeds API y con Claude API (copilot conversacional). Ver `plan.md` para el contexto completo.

## Documentación obligatoria a consultar

Antes de tocar cualquier módulo, lee:

1. `plan.md` — esqueleto general del proyecto (v3.0)
2. `docs/<modulo>.md` — especificación detallada del módulo en el que vas a trabajar
3. `docs/database-schema.sql` — estructura de la base de datos
4. `docs/api-endpoints.md` — todos los endpoints REST
5. Las skills en `skills/` según corresponda (cloudbeds-api, php-conventions, ui-components)

## Modo de trabajo

Este proyecto opera en **autonomía híbrida**:

- **Autonomía total** para módulos backend mecánicos: schema, modelos, CRUD, RBAC dinámico, integración Cloudbeds, middleware, alertas predictivas, tests. Codifica módulos completos, commitea, sigue.
- **Supervisión por módulo** para UI: Login, Home (4 versiones por rol), checklist, copilot IA, ajustes (matriz RBAC), auditoría, alertas predictivas. Propone archivos, espera aprobación antes de aceptar.

Cuando estés en modo autonomía total y debas tomar una decisión que no esté especificada en `docs/`, sigue los **Defaults razonables** (más abajo) y deja un comentario `// DECISIÓN AUTÓNOMA: <descripción>` en el código para revisión posterior.

## Stack obligatorio

- **PHP 8.2** — usa features modernos: typed properties, readonly, enums, match expressions, named arguments
- **SQLite** vía PDO — nada de ORMs externos en el MVP
- **Tailwind CSS via CDN** — sin build step, sin npm para el frontend
- **Alpine.js via CDN** — para interactividad puntual (checkboxes, modales, FAB, modo día/noche)
- **PHP nativo como motor de plantillas** — sin Blade ni Twig en el MVP
- **Lucide icons via CDN** — iconografía minimalista
- **Google Fonts (Inter) via CDN** — tipografía
- **Composer** para dependencias PHP (mínimas: solo lo esencial)

## Frontend — decisiones clave

- **NO uses** frameworks frontend pesados (React, Vue, jQuery)
- **NO uses** build tools (Webpack, Vite, PostCSS)
- **NO compiles Tailwind** — viene por CDN, todas las clases están disponibles
- **SÍ puedes** usar Alpine.js directivas (`x-data`, `x-show`, `x-on`, etc.) libremente
- **Mobile-first siempre:** escribe los estilos base para 375px y agrega `sm:`, `md:`, `lg:` para escalar
- **Botones mínimo 44px de alto:** `min-h-[44px]` o equivalente
- **Bottom tab bar en móvil**, sidebar en desktop (`md:` y arriba)
- **FAB del copilot IA** siempre visible — `fixed bottom-20 right-4` o similar
- **Modo día/noche** con clase `dark:` de Tailwind, persistido en localStorage

## Arquitectura clave — RBAC Dinámico

Este proyecto usa **Role-Based Access Control dinámico**, no hardcodeado. Reglas:

1. **NUNCA** chequees roles directamente en el código:
   ```php
   // ❌ NUNCA
   if ($usuario->rol === 'admin') { ... }
   ```

2. **SIEMPRE** chequea permisos específicos:
   ```php
   // ✅ SIEMPRE
   if ($usuario->tienePermiso('habitaciones.asignar')) { ... }
   ```

3. El catálogo de permisos vive en `database/seeds/permisos.php`. Cada vez que agregues una feature nueva, agrega los permisos que necesita a ese archivo.

4. La asignación de permisos a roles se hace vía la tabla `rol_permisos` y se puede editar desde la UI (Ajustes → Roles y Permisos) sin tocar código.

5. El middleware `PermissionCheck` debe aplicarse en cada endpoint que requiera permisos. Nunca confíes solo en chequeos del frontend.

Ver `docs/roles-permisos.md` para el detalle completo.

## Arquitectura clave — Auditoría con 3 estados

La auditoría NO es binaria (aprobado/rechazado). Tiene **3 estados**:

1. **`aprobado`** — todo bien, habitación queda "Clean" en Cloudbeds
2. **`aprobado_con_observacion`** — auditor encontró algo menor, lo resolvió, pero deja constancia. Habitación queda "Clean" en Cloudbeds. Se guardan qué items específicos del checklist fueron desmarcados por el auditor. Afecta KPIs a nivel de ítem, no de habitación. El trabajador NO ve esto como rechazo en su historial.
3. **`rechazado`** — habitación necesita re-limpieza. Vuelve a "Dirty" en Cloudbeds. Supervisora es notificada y decide a quién reasignar.

Ver `docs/auditoria.md` para el flujo completo.

**Inmutabilidad post-auditoría (NO NEGOCIABLE):**

Una vez una habitación recibe veredicto de auditoría (cualquiera de los 3 estados), **NO puede ser re-auditada**. En la UI:
- Aparece en las listas de auditoría como solo lectura
- Visualmente diferenciada: opacidad reducida, badge "Auditada"
- Sin botones de acción (no muestra los 3 botones)
- Tap → muestra detalle histórico (auditor, fecha, comentario, ítems desmarcados si aplica)

Backend: el endpoint `POST /api/auditoria/{habitacion_id}` debe rechazar con error 409 (Conflict) si la habitación ya tiene un registro en `auditorias` para esa ejecución.

## Arquitectura clave — Persistencia del checklist

El progreso del trabajador en un checklist se guarda **a cada tap**, no al final:

- Cada vez que el trabajador marca o desmarca un item del checklist, dispara un PUT/POST inmediato al backend
- Si no hay internet, el cambio queda en una cola local (localStorage/IndexedDB) y se sincroniza cuando vuelve la conexión
- Si la app se cierra, al reabrir la habitación aparece como "Continuar" con todos los checks previamente marcados
- El botón "Habitación terminada" se desbloquea solo cuando todos los items están marcados

Ver `docs/checklist.md` para el detalle.

## Arquitectura clave — Tracking de tiempo oculto

Cada habitación registra `timestamp_inicio` y `timestamp_fin` en `ejecuciones_checklist`. El trabajador **NUNCA** debe ver estos valores en su pantalla. Son exclusivamente para:
- Cálculo del tiempo promedio personal del trabajador (input de alertas predictivas)
- Reportes, KPIs y analítica (versión básica en MVP, completa en Fase 2)

## Arquitectura clave — Alertas predictivas

El sistema calcula en tiempo real si cada trabajador va a alcanzar a terminar su turno. Ver `docs/alertas-predictivas.md` para el algoritmo y umbrales. Reglas:

- La alerta es **solo visible para supervisoras con permiso `alertas.recibir_predictivas`**
- El trabajador **NUNCA** ve la alerta ni sabe de su existencia
- El umbral de margen de seguridad (default 15 min) es configurable desde Ajustes por roles con permiso `alertas.configurar_umbrales`

**Tipos de alertas y prioridades (definidos en `docs/home-supervisora.md`):**

El sistema maneja 6 tipos de alertas con prioridades 0-3:

- P0: `cloudbeds_sync_failed`
- P1: `trabajador_en_riesgo`, `habitacion_rechazada`, `fin_turno_pendientes`
- P2: `trabajador_disponible`, `ticket_nuevo`
- P3: (reservado para casos futuros)

Cada tipo de alerta tiene:
- Un título claro y accionable
- Una descripción con datos concretos
- Máximo 2 botones de acción
- NO tiene botón "descartar" (las alertas persisten hasta resolverse o hasta que la condición desaparezca)

Las acciones sobre alertas se registran en la tabla `bitacora_alertas`.

## Convenciones de código PHP

- **Namespace raíz:** `Atankalama\Limpieza`
- **PSR-4 autoloading** vía Composer
- **PSR-12** para estilo de código
- **Strict types** en cada archivo: `declare(strict_types=1);`
- **Tipado estricto** en parámetros y retornos siempre
- **`final class`** por defecto, salvo herencia justificada
- **`readonly`** en propiedades inyectadas por constructor
- **Nombres en español** para conceptos del dominio (`Habitacion`, `Asignacion`, `Trabajador`, `Auditoria`) y en inglés para conceptos técnicos (`Controller`, `Service`, `Repository`, `Middleware`)
- **Comentarios y mensajes de error en español** — la app es 100% español chileno
- **Prepared statements con PDO** siempre — nunca concatenación SQL
- **Errores con excepciones**, no con `return false`

### Respuestas JSON estandarizadas

Éxito:
```json
{ "ok": true, "data": { ... } }
```

Error:
```json
{ "ok": false, "error": { "codigo": "CODIGO_ERROR", "mensaje": "Descripción amigable" } }
```

Ver la skill `php-conventions` para más detalle.

## Reglas de seguridad (NO NEGOCIABLES)

1. **Nunca hardcodear credenciales.** Todas las API keys, passwords, secrets vienen de `.env` vía `getenv()` o `$_ENV`.
2. **Nunca commitear `.env`.** Solo `.env.example` con placeholders.
3. **Nunca pegar valores reales** de API keys en ejemplos de código, comentarios, ni documentación.
4. **Contraseñas siempre hasheadas** con `password_hash($pwd, PASSWORD_BCRYPT)`.
5. **Validación de permisos en backend** con el middleware `PermissionCheck` antes de cada acción sensible. Nunca confíes solo en el frontend.
6. **El copilot IA valida permisos del rol** antes de ejecutar cualquier tool.
7. **Toda escritura a Cloudbeds queda en logs** con timestamp, payload y respuesta.
8. **Sanitiza todo input del usuario** (XSS vía `htmlspecialchars()`, SQL injection vía PDO prepared statements).
9. **Antes de cada commit**, gitleaks está configurado como hook pre-commit. Si te bloquea, NO uses `--no-verify` — revisa qué activó la alerta.
10. **Nunca loggear tokens, API keys, passwords ni headers Authorization**, ni siquiera en errores.

## Defaults razonables (cuando algo no está especificado)

- **Mensajes de error al usuario:** tono amable, en español, sin jerga técnica. Ejemplo: "No pudimos guardar tu cambio, intenta de nuevo en un momento."
- **Estados de carga:** spinner azul centrado con texto "Cargando..." debajo
- **Estado vacío:** ilustración o icono grande + texto explicativo + acción primaria si aplica
- **Confirmaciones:** para acciones destructivas (borrar, rechazar), modal con dos botones — primario rojo y secundario gris "Cancelar"
- **Timeouts de red:** 10 segundos para Cloudbeds, 30 segundos para Claude API
- **Reintentos:** 3 reintentos con backoff exponencial (1s, 2s, 4s) para llamadas externas
- **Logs:** `INFO` para acciones normales, `WARNING` para validaciones fallidas, `ERROR` para fallos de integración
- **Fechas:** formato chileno DD/MM/YYYY en UI, ISO 8601 en BD y JSON
- **Hora:** zona horaria `America/Santiago`
- **Idioma:** español chileno. Textos cortos, claros, sin formalismos excesivos pero respetuosos.

Cuando uses un default razonable, deja un comentario:
```php
// DECISIÓN AUTÓNOMA: usé spinner azul porque docs/checklist.md no especifica estado de carga
```

## Comandos útiles del proyecto

```powershell
# Servidor local de desarrollo
php -S localhost:8000 -t public/

# Crear/recrear la base de datos desde migraciones + seeds
php scripts/init-db.php

# Cargar datos de demo (para mostrar el MVP)
php scripts/seed-demo-data.php

# Sincronizar manualmente con Cloudbeds (normalmente es cron)
php scripts/sync-cloudbeds.php

# Rescate de emergencia: resetear password del admin
php scripts/reset-admin-password.php

# Correr tests
./vendor/bin/phpunit tests/
```

## Convención de commits

Mensajes en español, descriptivos, en presente. Formato:

```
<tipo>: <descripción corta>

<descripción larga opcional>
```

Tipos: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `style`

Ejemplos:
- `feat: implementar sistema RBAC dinámico con catálogo de permisos`
- `feat: autenticación con RUT y middleware de permisos`
- `feat: endpoint POST /api/habitaciones/{id}/completar con escritura a Cloudbeds`
- `fix: corregir validación del dígito verificador del RUT`
- `docs: documentar flujo de auditoría con 3 estados`

Un commit por módulo o sub-módulo lógico terminado. NO commitees código a medias.

## Testing

Para los módulos en autonomía total, escribe tests unitarios mínimos:
- Validación de RUT (incluye casos con K)
- Hash y verificación de contraseñas
- Generación de contraseñas temporales (sin caracteres ambiguos)
- Lógica de asignación round-robin
- Algoritmo de alertas predictivas (con casos edge: trabajador nuevo, sin histórico, margen exacto)
- Sistema de permisos dinámicos (`tienePermiso()`)
- Cliente Cloudbeds (con respuestas mockeadas)

Los módulos UI (con supervisión) no requieren tests automatizados en el MVP.

## Cuando termines un módulo

1. Asegúrate de que el código corre sin errores (`php -S localhost:8000 -t public/`)
2. Si es módulo backend: corre los tests
3. Haz commit con mensaje descriptivo
4. Si encontraste decisiones autónomas significativas, menciónalas en el cuerpo del commit
5. Continúa con el siguiente módulo del orden definido en `claude-code-setup.md` sección 10

## Cuando algo no funciona

- Si una API externa falla (Cloudbeds, Claude), NO inventes el comportamiento — registra el error en logs y propaga un error claro al usuario
- Si una decisión es ambigua, prefiere preguntar a Nicolás antes que asumir
- Si encuentras un bug en código previo (incluso si lo escribiste tú), arréglalo y menciónalo en el commit
````

---

## 7. MCPs instalados

Los 5 MCPs ya están configurados en `.mcp.json` del proyecto. Para referencia:

```json
{
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": ["--yes", "@modelcontextprotocol/server-filesystem", "."]
    },
    "github": {
      "command": "npx",
      "args": ["-y", "@github/github-mcp-server"]
    },
    "sqlite": {
      "command": "npx",
      "args": ["@modelcontextprotocol/server-sqlite", "database/atankalama.db"]
    },
    "context7": {
      "command": "npx",
      "args": ["-y", "@upstash/context7-mcp"]
    },
    "playwright": {
      "command": "npx",
      "args": ["@playwright/mcp@latest"]
    }
  }
}
```

El token de GitHub está guardado en `.claude/settings.local.json` (fuera del repo, gitignored).

Para verificar los MCPs activos en cualquier momento, en el chat de Claude Code: `/mcp`

---

## 8. Skills personalizadas

Tres skills a crear en `skills/` con el siguiente contenido.

### 8.1 `skills/cloudbeds-api/SKILL.md`

```markdown
# Cloudbeds API — Skill para Atankalama Limpieza

## Cuándo usar esta skill

Cuando trabajes en cualquier código que se conecte con la API de Cloudbeds.

## Autenticación

La API usa API keys por propiedad, en header `Authorization: Bearer <key>`. Hay DOS keys distintas:
- `CLOUDBEDS_API_KEY_INN` para Hotel Atankalama Inn
- `CLOUDBEDS_API_KEY_PRINCIPAL` para Hotel Atankalama (1 Sur 858)

Cada key tiene su `propertyId` asociado en `.env`. NUNCA mezclar keys entre propiedades.

## Endpoints clave para el MVP

### Listar habitaciones con estado de limpieza
- `GET /housekeeping/rooms?propertyID={id}&status=dirty`

### Actualizar estado de habitación
- `PUT /housekeeping/rooms/{roomId}` con body `{ "status": "clean" }` o `{ "status": "dirty" }`
- Respuesta exitosa: 200 con el objeto actualizado
- Errores: 401 (key inválida), 404 (habitación no existe), 422 (estado inválido), 429 (rate limit), 5xx

## Manejo de errores obligatorio

- Timeout 10s por request
- 3 reintentos con backoff exponencial (1s, 2s, 4s)
- Si tras los reintentos sigue fallando: encolar en `cloudbeds_sync_queue` y retornar error al caller
- Loggear TODA llamada en `logs_sistema` (sin headers Authorization)

## Estructura del cliente PHP

Vive en `src/Services/Cloudbeds/CloudbedsClient.php`. Debe:
- Recibir el `propertyKey` ('inn' o 'principal') por constructor
- Métodos: `listarHabitacionesSucias()`, `marcarComoLimpia(roomId)`, `marcarComoSucia(roomId)`
- Dependencias por constructor (DI), no globales

## Reglas

- NUNCA hardcodear API keys
- NUNCA loggear headers Authorization
- SIEMPRE validar respuestas antes de usarlas
- SIEMPRE registrar la operación en logs
```

### 8.2 `skills/php-conventions/SKILL.md`

```markdown
# Convenciones PHP 8.2 — Atankalama Limpieza

## Cuándo usar esta skill

Cuando escribas código PHP en este proyecto.

## Estructura de un Controller

```php
<?php
declare(strict_types=1);

namespace Atankalama\Limpieza\Controllers;

use Atankalama\Limpieza\Core\{Request, Response};
use Atankalama\Limpieza\Services\HabitacionService;

final class HabitacionesController
{
    public function __construct(
        private readonly HabitacionService $habitaciones,
    ) {}

    public function listar(Request $req): Response
    {
        $hotelId = $req->query('hotel_id');
        $estado = $req->query('estado');

        $resultado = $this->habitaciones->listar($hotelId, $estado);

        return Response::json(['ok' => true, 'data' => $resultado]);
    }
}
```

## Estructura de un Service

```php
<?php
declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

final class HabitacionService
{
    public function __construct(
        private readonly \PDO $db,
    ) {}

    public function listar(?int $hotelId, ?string $estado): array
    {
        $sql = 'SELECT * FROM habitaciones WHERE 1=1';
        $params = [];
        if ($hotelId !== null) {
            $sql .= ' AND hotel_id = :hotel_id';
            $params['hotel_id'] = $hotelId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
```

## Respuestas JSON estandarizadas

### Éxito
```json
{ "ok": true, "data": { ... } }
```

### Error de validación
```json
{
  "ok": false,
  "error": {
    "codigo": "VALIDATION_ERROR",
    "mensaje": "El RUT no es válido",
    "campos": { "rut": "Dígito verificador incorrecto" }
  }
}
```

### Error de permisos
```json
{
  "ok": false,
  "error": {
    "codigo": "FORBIDDEN",
    "mensaje": "No tienes permiso para realizar esta acción"
  }
}
```

### Error interno
```json
{
  "ok": false,
  "error": {
    "codigo": "INTERNAL_ERROR",
    "mensaje": "Ocurrió un error inesperado, intenta nuevamente"
  }
}
```

## Middleware de permisos dinámicos

Ejemplo de uso:
```php
// En la definición de rutas
$router->post('/api/habitaciones/{id}/asignar', [
    'middleware' => ['auth', 'permission:habitaciones.asignar'],
    'handler' => [AsignacionController::class, 'asignar'],
]);
```

El middleware `PermissionCheck` lee el permiso del parámetro y chequea `$usuario->tienePermiso('habitaciones.asignar')`.

## Reglas

- Siempre `declare(strict_types=1);`
- Siempre `final class` salvo herencia justificada
- Siempre `readonly` en propiedades inyectadas
- Siempre prepared statements con PDO
- Siempre tipar parámetros y retornos
- Errores con excepciones, no `return false`
- Excepciones del dominio en `src/Exceptions/`
- **NUNCA** chequear rol directamente, SIEMPRE chequear permisos con `$usuario->tienePermiso('codigo')`
```

### 8.3 `skills/ui-components/SKILL.md`

```markdown
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
```

---

## 9. Flujo de trabajo con Claude Code

Una vez el setup esté completo, así es como trabajar con Claude Code para este proyecto:

### Sesión típica

1. Abres VS Code en `C:\Proyectos\atankalama-limpieza`
2. Abres el panel de Claude Code
3. Claude Code lee automáticamente `CLAUDE.md` raíz
4. Le pides el módulo X con un prompt claro (ver sección 11)
5. Claude Code lee `docs/<modulo>.md`, las skills relevantes, el schema, los endpoints
6. **Si es módulo de autonomía total:**
   - Claude Code codifica todos los archivos
   - Corre tests si aplica
   - Hace `git add` + commit (gitleaks valida automáticamente)
   - Te avisa que terminó
   - Tú revisas el commit con `git log -p`
7. **Si es módulo de supervisión:**
   - Claude Code propone los archivos uno por uno
   - Tú apruebas, rechazas o pides ajustes
8. Pruebas el módulo localmente
9. Si todo bien, pasas al siguiente módulo

### Cuando algo sale mal

- **Si gitleaks bloquea un commit:** revisa qué archivo activó la alerta, quita el secreto, vuelve a intentar. NUNCA uses `--no-verify`
- **Si Claude Code propone algo que no te gusta:** dile específicamente qué cambiar
- **Si encuentras un bug en módulos anteriores:** pídele a Claude Code que lo corrija con un commit nuevo (`fix: ...`)
- **Si quieres descartar todo el trabajo de Claude Code:** `git reset --hard HEAD~N` donde N es la cantidad de commits a deshacer

---

## 10. Orden recomendado de codificación de módulos

Este es el orden óptimo. Cada módulo construye sobre los anteriores.

### Etapa A — Fundación (autonomía total) ✅ `b0542a4`

1. ✅ **Setup del proyecto PHP** — `composer.json`, autoload PSR-4, estructura Core
2. ✅ **Base de datos** — schema SQLite completo con tablas de RBAC + todas las demás
3. ✅ **Seeders** — catálogo de permisos, 4 roles por defecto, 2 turnos, 2 hoteles, admin inicial
4. ✅ **Servicios base** — `Config`, `Logger`, `RutValidator`, `PasswordService`

### Etapa B — RBAC y Auth (autonomía total) ✅ `8ebbe68`

5. ✅ **Modelo `Usuario`** con método `tienePermiso($codigo)`
6. ✅ **Servicio RBAC** — gestión de roles y permisos
7. ✅ **Middleware `PermissionCheck`**
8. ✅ **Endpoints de auth** — login, logout, cambio de contraseña
9. ✅ **Endpoints de gestión de roles/permisos** (CRUD)
10. ✅ **Tests de auth y RBAC**

### Etapa C — Habitaciones y Cloudbeds (autonomía total) ✅ `7906b71`

11. ✅ **Cliente Cloudbeds** con cola de reintentos
12. ✅ **Modelos** de Hotel, TipoHabitacion, Habitacion
13. ✅ **HabitacionService**
14. ✅ **Endpoints REST** de habitaciones
15. ✅ **Script de sincronización** (cron 2×/día)

### Etapa D — Checklists, asignaciones, auditoría (autonomía total) ✅ `570aca0`

16. ✅ **Modelos y servicios** de checklist, asignación
17. ✅ **Lógica de persistencia tap a tap** del checklist
18. ✅ **Tracking de tiempo oculto**
19. ✅ **Auto-asignación round-robin**
20. ✅ **Endpoints REST** de checklists, asignaciones
21. ✅ **Auditoría con 3 estados**
22. ✅ **Lógica de "rechazar → vuelve a sucia + reasignable"**
23. ✅ **Lógica de "aprobado con observación" con items desmarcados**

### Etapa E — Alertas predictivas (autonomía total) ✅ `9824eb2`

24. ✅ **Servicio `AlertasPredictivas`** con el algoritmo
25. ✅ **Tabla de configuración de umbrales**
26. ✅ **Recálculo automático** al completar habitaciones y en background
27. ✅ **Endpoints** para ver bandeja de alertas y marcar como atendidas
28. ✅ **Servicio `AlertasService`** con los 6 tipos de alertas definidos
29. ✅ **Tabla `bitacora_alertas`** con sus índices
30. ✅ **Cálculo de prioridades** según tipo y antigüedad
31. ✅ **Endpoints** para listar alertas top 5 + ver todas + ejecutar acciones
32. ✅ **Refresco automático** al completar habitaciones y cada 15 min
33. ✅ **Validación de inmutabilidad de auditoría** en backend (error 409)

### Etapa F — Tickets, usuarios, turnos (autonomía total) ✅ `51b578c`

34. ✅ **Endpoints de tickets simplificados**
35. ✅ **Endpoints de gestión de usuarios**
36. ✅ **Endpoints de gestión de turnos**
37. ✅ **Endpoint de reset de contraseña por admin** (implementado en Etapa B)

### Etapa G — Copilot IA (autonomía total para motor, supervisión para prompts) ✅ `3464c7c`

38. ✅ **Servicio `CopilotService`** con Claude API y tool use
39. ✅ **Definición de tools por rol** (validando permisos dinámicos)
40. ✅ **Endpoint** `POST /api/copilot/mensaje`
41. ✅ **Persistencia de conversaciones**

### Etapa H — Frontend (SUPERVISIÓN)

42. **Layout base** — sidebar desktop + bottom nav móvil + FAB del copilot
43. **Pantalla de Login** + cambio forzado de contraseña
44. **Home del Trabajador** ⭐ (ya diseñada en detalle, ver `docs/home-trabajador.md`)
45. **Home de la Supervisora** ⭐ (ya diseñada en detalle, ver `docs/home-supervisora.md`)
46. **Home de Recepción** ⭐ (ya diseñada en detalle, ver `docs/home-recepcion.md`)
47. **Home del Admin** ⭐ (ya diseñada en detalle, ver `docs/home-admin.md` y `docs/home-admin-qa-checklist.md`)
48. **Listado y detalle de habitaciones + checklist persistente**
49. **Vista de asignación**
50. **Bandeja de auditoría** con los 3 botones y checklist expandible
51. **Levantar ticket de mantenimiento**
52. **Gestión de usuarios**
53. **Matriz RBAC de roles y permisos** (una de las pantallas más importantes)
54. **Gestión de turnos**
55. **Configuración de alertas predictivas**
56. **Ajustes por rol**
57. **Panel del copilot IA** (FAB + panel deslizable + voz)
58. **Modo día/noche** persistido

### Etapa I — Pulido final

59. **Datos de demo realistas** ✅ (commits `049498d` + `d4590f5`)
60. **README.md** con instrucciones de setup y uso ✅ (commit pendiente en esta sesión)
61. **Despliegue al VPS** ⏳

---

## 11. Prompts de ejemplo para arrancar cada módulo

### Para módulos de autonomía total

```
Implementa el módulo de [NOMBRE] siguiendo las especificaciones de docs/[archivo].md
y respetando todas las convenciones del CLAUDE.md raíz.

Usa la skill php-conventions para la estructura de código.
Si necesitas consultar la API de Cloudbeds, usa la skill cloudbeds-api.

Cuando termines:
1. Asegúrate de que el código compila sin errores
2. Corre los tests si aplica
3. Haz un commit con mensaje descriptivo en español
4. Avísame qué decisiones autónomas tomaste (busca los comentarios DECISIÓN AUTÓNOMA)

Trabaja con autonomía total — no me preguntes por cada archivo, solo avísame al final.
```

### Para módulos de supervisión (UI)

```
Vamos a implementar la pantalla de [NOMBRE]. Sigue docs/[archivo].md y la skill ui-components.

Este es un módulo bajo SUPERVISIÓN:
1. Primero muéstrame el plan: qué archivos vas a crear/modificar
2. Luego propón cada archivo uno por uno, esperando mi aprobación
3. NO commitees nada hasta que yo te lo diga explícitamente

Recuerda: mobile-first, botones mínimo 44px, modo día/noche, Tailwind CDN, Alpine CDN.
```

### Para arreglar un bug

```
Hay un bug en [DESCRIPCIÓN]. Pasos para reproducir:
1. ...
2. ...

Comportamiento esperado: ...
Comportamiento actual: ...

Investiga, propón la causa, arregla, y commitea con mensaje "fix: ..."
```

### Para el setup inicial (ejemplo del segundo prompt grande que ejecutará esta fase)

```
Lee plan.md v3.0 y claude-code-setup.md v2.0 completos.

Luego ejecuta TODO el setup inicial del proyecto que falta, específicamente:

1. Crea la estructura completa de carpetas (sección 3 del setup)
2. Reemplaza el .gitignore actual con la versión completa (sección 4.1)
3. Crea .env.example con todas las variables (sección 4.2)
4. Crea los archivos .gitkeep necesarios
5. Crea el CLAUDE.md raíz con el contenido exacto de la sección 6
6. Crea las 3 skills en skills/cloudbeds-api, skills/php-conventions, skills/ui-components con el contenido exacto de la sección 8
7. Instala gitleaks con winget (sección 5.1)
8. Configura el hook pre-commit de gitleaks (sección 5.2)
9. Prueba gitleaks con un archivo de secreto falso, verifica que bloquea el commit, y borra el archivo de prueba (sección 5.3)
10. Haz un commit con todo esto: "chore: setup inicial del proyecto con RBAC, skills y gitleaks"
11. Haz push a main

NO crees el archivo .env real — eso lo haré yo manualmente con las credenciales verdaderas.
NO crees todavía ningún archivo de docs/ — esos se crearán en la siguiente fase.
NO crees database/atankalama.db — la BD se crea cuando arrancamos la codificación real.

Cuando termines, dame un resumen de qué creaste, qué commits hiciste, y qué falta
para que yo complete antes de empezar a codificar módulos.
```

---

*Fin del documento de setup v2.0. Próximo paso: retomar con Claude (asistente) el detalle de las pantallas pendientes de Home (Supervisora, Recepción, Admin).*
