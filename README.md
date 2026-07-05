# Atankalama — Aplicación de limpieza hotelera

Aplicación web mobile-first para gestionar operaciones de limpieza en las dos propiedades de **Atankalama Corp** (Calama, Chile): **Atankalama** (1 Sur 858) y **Atankalama INN** (Chorrillos 558). Reemplaza Flexkeeping, integra con Cloudbeds API y con Claude (copilot conversacional).

- **Stack:** PHP 8.2 + SQLite (PDO) · Tailwind CSS + Alpine.js + Lucide Icons (vía CDN, sin build step)
- **Tests:** PHPUnit 10 · 169 tests / 446 assertions
- **Licencia:** Propietaria — Atankalama Corp

> Este es un MVP activo. Para la visión general del producto ver [plan.md](plan.md) y [claude-code-setup.md](claude-code-setup.md).

---

## Tabla de contenidos

- [Requisitos](#requisitos)
- [Setup local](#setup-local)
- [Credenciales demo](#credenciales-demo)
- [Comandos útiles](#comandos-útiles)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Documentación](#documentación)
- [Tests](#tests)
- [Deploy a producción](#deploy-a-producción)

---

## Requisitos

- **PHP 8.2+** con extensiones `pdo`, `pdo_sqlite`, `json`, `mbstring`, `openssl`
- **Composer 2.x**
- **SQLite 3.x** (incluido en la extensión PHP)
- Navegador moderno (mobile-first: probado en Chrome, Safari iOS, Firefox)

Sin Node.js, sin build tools, sin base de datos externa.

---

## Setup local

### 1. Clonar e instalar dependencias

```bash
git clone https://github.com/NicoCalama/atankalama-limpieza.git
cd atankalama-limpieza
composer install
```

### 2. Configurar variables de entorno

```bash
cp .env.example .env
```

Edita `.env` y completa:

- `SESSION_SECRET` — string aleatorio largo (64+ chars)
- `CLOUDBEDS_API_KEY_INN` y `CLOUDBEDS_API_KEY_PRINCIPAL` — credenciales reales de Cloudbeds
- `CLOUDBEDS_PROPERTY_ID_INN` y `CLOUDBEDS_PROPERTY_ID_PRINCIPAL`
- `CLAUDE_API_KEY` — API key de Anthropic (para el copilot IA)

Para desarrollo local sin Cloudbeds ni Claude, puedes dejar los placeholders — las features que los requieren fallarán con mensaje claro, pero el resto de la app funciona.

### 3. Crear la base de datos

```bash
php scripts/init-db.php           # aplica el schema de docs/database-schema.sql
php scripts/seed.php              # carga catálogos, permisos, roles, checklists templates, admin inicial
```

`seed.php` imprime la **contraseña temporal** del admin (`11111111-1`, Nicolás Campos). Guárdala — debe cambiarse en el primer login.

### 4. Importar el inventario real desde Cloudbeds

`seed.php` deja la app con catálogos y el admin, pero **sin habitaciones**. Para traer las
piezas reales de cada propiedad desde Cloudbeds (requiere las credenciales en `.env`):

```bash
php scripts/import-inventario-cloudbeds.php --dry-run   # muestra el plan de cambios, NO escribe
php scripts/import-inventario-cloudbeds.php             # aplica el import
```

El import es idempotente (podés re-correrlo cuando cambie el inventario) y mapea cada pieza a
su tipo de limpieza por `maxGuests`. Ver [docs/cloudbeds-import-inventario.md](docs/cloudbeds-import-inventario.md).

### 5. Levantar el servidor de desarrollo

```bash
php -S localhost:8000 -t public/
```

Abre http://localhost:8000 — te redirige al login.

---

## Credencial inicial

Tras `seed.php` solo existe el **admin**. Los demás usuarios se crean desde la app (Ajustes → Usuarios).

| Rol | RUT | Nombre | Hotel |
|---|---|---|---|
| Admin | `11111111-1` | Nicolás Campos | Ambos |

La contraseña temporal la imprime `seed.php` al correrlo; debe cambiarse en el primer login.

---

## Comandos útiles

```bash
# Servidor de desarrollo
composer serve                              # alias de: php -S localhost:8000 -t public/

# Base de datos
php scripts/init-db.php                     # aplica schema (usa DB existente)
php scripts/init-db.php --fresh             # borra la DB y la recrea vacía
composer init-db                            # alias sin --fresh

# Seeders
php scripts/seed.php                        # catálogos + permisos + roles + admin inicial

# Inventario real desde Cloudbeds (idempotente)
php scripts/import-inventario-cloudbeds.php --dry-run   # plan sin escribir
php scripts/import-inventario-cloudbeds.php             # aplica

# Cloudbeds (cron en producción — manual para pruebas)
php scripts/sync-cloudbeds.php

# Alertas predictivas (cron c/15 min en producción)
php scripts/recalcular-alertas.php

# Tests
composer test                               # alias de: phpunit tests/
./vendor/bin/phpunit                        # suite completa
./vendor/bin/phpunit tests/Integration/     # solo integración
```

---

## Estructura del proyecto

```
atankalama-limpieza/
├── public/                 # Entry point (index.php) y assets estáticos
├── src/
│   ├── Controllers/        # Controladores HTTP
│   ├── Core/               # Config, Database, Kernel (router), Request, Response
│   ├── Helpers/            # Rut, fechas, etc.
│   ├── Middleware/         # AuthCheck, PermissionCheck
│   ├── Models/             # Entidades del dominio
│   ├── Repositories/       # Acceso a datos (PDO)
│   └── Services/           # Lógica de negocio (Password, Cloudbeds, Copilot, Alertas, etc.)
├── views/                  # Templates PHP nativos (layout + homes por rol + componentes)
├── scripts/                # CLI: init-db, seed, import-inventario-cloudbeds, sync-cloudbeds, recalcular-alertas
├── database/
│   ├── seeds/              # Catálogos PHP (permisos, roles, hoteles, turnos, checklists)
│   └── atankalama.db       # SQLite (gitignored)
├── docs/                   # Especificación del dominio y schema
├── tests/                  # PHPUnit (Unit + Integration)
└── skills/                 # Skills de referencia para Claude Code
```

---

## Documentación

Toda la especificación vive en [`docs/`](docs/):

**Dominio core:**
- [roles-permisos.md](docs/roles-permisos.md) — catálogo RBAC dinámico
- [auth.md](docs/auth.md) — login con RUT + contraseñas temporales
- [habitaciones.md](docs/habitaciones.md) — estados y asignación
- [checklist.md](docs/checklist.md) — persistencia tap-a-tap y tracking oculto
- [auditoria.md](docs/auditoria.md) — flujo con 3 veredictos (aprobado, aprobado con observación, rechazado)

**Integraciones y features:**
- [cloudbeds.md](docs/cloudbeds.md) — sincronización con PMS
- [alertas-predictivas.md](docs/alertas-predictivas.md) — algoritmo P0-P3
- [copilot-ia.md](docs/copilot-ia.md) — integración Claude + tool use
- [tickets.md](docs/tickets.md) · [turnos.md](docs/turnos.md) · [usuarios.md](docs/usuarios.md) · [ajustes.md](docs/ajustes.md) · [logs.md](docs/logs.md)

**Homes por rol:**
- [home-trabajador.md](docs/home-trabajador.md)
- [home-supervisora.md](docs/home-supervisora.md)
- [home-recepcion.md](docs/home-recepcion.md)
- [home-admin.md](docs/home-admin.md)

**Referencia técnica:**
- [database-schema.sql](docs/database-schema.sql) — DDL canónico
- [api-endpoints.md](docs/api-endpoints.md) — todos los endpoints REST
- [ARCHITECTURE_MAP.md](ARCHITECTURE_MAP.md) — mapa de navegación del código
- [CLAUDE.md](CLAUDE.md) — convenciones y reglas de desarrollo

---

## Tests

```bash
./vendor/bin/phpunit
```

Cubre: validación de RUT, hashing/verificación de contraseñas, generación de contraseñas temporales, RBAC dinámico (`tienePermiso()`), flujo de auditoría con los 3 veredictos, cliente Cloudbeds con mocks, seeder de datos demo (ver [tests/Integration/SeedDemoDataTest.php](tests/Integration/SeedDemoDataTest.php)), y los endpoints de las 4 Homes.

Base de datos de tests: SQLite en memoria, recreada por cada test (`TestDatabase::recrear()`).

---

## Deploy a producción

Deploy a VPS (Caddy + PHP-FPM + cron) documentado paso-a-paso en [docs/deploy-vps.md](docs/deploy-vps.md).

Artefactos de deploy en el repo:

- [Caddyfile.example](Caddyfile.example) — configuración canónica de Caddy (HTTPS, headers de seguridad, bloqueo de rutas sensibles)
- [.env.production.example](.env.production.example) — plantilla de variables con `APP_ENV=production`
- [scripts/deploy.sh](scripts/deploy.sh) — deploy idempotente (`git pull` + `composer install --no-dev` + `init-db` + reload PHP-FPM)
- [scripts/backup-db.sh](scripts/backup-db.sh) — backup diario de SQLite con rotación 7 días
- Endpoint `GET /api/health` — health check público para uptime monitors

Resumen técnico:

- Ubuntu 22.04 LTS · Caddy (HTTPS automático vía Let's Encrypt) · PHP 8.2-FPM · SQLite
- Usuario de servicio `atankalama`, firewall UFW (solo SSH + HTTPS)
- Cron: `sync-cloudbeds.php` 2×/día · `recalcular-alertas.php` cada 15 min · backup diario 03:30
- Logrotate + monitoreo externo opcional (UptimeRobot, Betterstack)

---

## Contacto

Proyecto interno de **Atankalama Corp**. Para consultas técnicas: Nicolás Campos (`nicolas@atankalama.cl`).
