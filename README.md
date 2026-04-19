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

### 4. (Opcional) Cargar datos de demo

Para ver la app con usuarios, habitaciones, turnos, ejecuciones y tickets ya poblados:

```bash
php scripts/seed-demo-data.php              # agrega demo sin tocar datos existentes
php scripts/seed-demo-data.php --reset      # limpia demo previo y recarga (preserva admin original)
```

### 5. Levantar el servidor de desarrollo

```bash
php -S localhost:8000 -t public/
```

Abre http://localhost:8000 — te redirige al login.

---

## Credenciales demo

Tras correr `php scripts/seed-demo-data.php`, todos los usuarios demo comparten la contraseña **`Demo1234!`**.

| Rol | RUT | Nombre | Hotel |
|---|---|---|---|
| Admin (original de seed.php) | `11111111-1` | Nicolás Campos | Ambos |
| Supervisora | `15234567-4` | Paola | 1 Sur |
| Supervisora | `14987654-5` | Claudia | Inn |
| Recepción | `16789012-1` | Daniela Contreras | 1 Sur |
| Recepción | `17345678-6` | Andrea Silva | Inn |
| Trabajadora | `18502341-9` | Valentina | — |
| Trabajadora | `19234512-K` | Camila | — |
| Trabajadora | `17834901-5` | Sofía | — |
| Trabajadora | `16543210-K` | María | — |
| Trabajadora | `19876543-0` | Isidora | — |

(El seeder crea 10 trabajadoras en total; ver [scripts/seed-demo-data.php](scripts/seed-demo-data.php) para el listado completo.)

El **admin original** se preserva al correr `--reset` y conserva la contraseña que tenga al momento del reset.

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
php scripts/seed-demo-data.php --reset      # recarga datos de demo

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
├── scripts/                # CLI: init-db, seed, seed-demo-data, sync-cloudbeds, recalcular-alertas
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

Deploy a VPS (Caddy/Nginx + PHP-FPM + cron para Cloudbeds y alertas predictivas) — **documentación pendiente** (item 61 de la Etapa I).

Lineamientos generales:

- Servir `public/` como document root
- PHP-FPM 8.2 con OPcache habilitado
- HTTPS obligatorio (Caddy automatiza certificados con Let's Encrypt)
- Cron: `sync-cloudbeds.php` 2×/día (ver `SYNC_HOUR_MORNING` y `SYNC_HOUR_EVENING` en `.env`); `recalcular-alertas.php` cada 15 minutos
- Backups diarios del archivo SQLite

---

## Contacto

Proyecto interno de **Atankalama Corp**. Para consultas técnicas: Nicolás Campos (`nicolas@atankalama.cl`).
