# Migración a MariaDB + despliegue en cPanel (compartido con Maisterchef)

> **Estado: Fase 1 casi completa** (rama `feat/migracion-mariadb`). Tokenización (paso 6) hecha y verificada; restan los fixes en origen (paso 7) y los 3 scripts de PDO crudo (paso 8). Última actualización: 2026-06-29.

## Estado actual y cómo retomar

**Hecho y commiteado (verificado, el camino SQLite/local queda 100% intacto):**
- Capa de datos driver-aware (`DB_CONNECTION=sqlite|mariadb`) + prefijo por token `#__` + `Database::now()`.
- Plantillas `.env` con la config MariaDB (`DB_PREFIX=limpieza_`, `cat6852_australia`).
- `docs/database-schema.mariadb.sql` (32 tablas, dialecto MySQL, tokens `#__`) — **verificado adversarialmente** (paridad + validez MariaDB 10.11 + tokens); fix `endpoint(191)`.
- **Motor de dialecto** `Database::applyDialect()`: traduce SQLite→MariaDB en runtime SOLO si el driver es mysql/mariadb (`strftime('now')`, `date('now')`, `INSERT OR IGNORE/REPLACE`, `GROUP_CONCAT`). Las queries se escriben una sola vez (dialecto SQLite) y corren en ambos motores; en SQLite es passthrough.
- `scripts/lint-prefix-tokens.php`: verificador (sin MariaDB) de referencias de tabla sin `#__`.
- **Tokenización (paso 6)**: 414 referencias en 25 archivos seguros (services, seeds, `TestDatabase`, `Logger`) con `#__`, vía Workflow + revisión independiente. Linter **435→21** (los 21 restantes son los 3 scripts de PDO crudo). Suite **185/185**.
- **fix**: `JOIN` roto en `AuditoriaService` (`p.codigo = rp.permiso_codigo`) → suite de 184/185 a 185/185.
- **fix(MariaDB)** (hallazgos de la revisión): tabla dinámica tokenizada en `cleanup-retention.php` (`FROM #__{$tabla}`); `Database::driver()` + guardia en `AuthService::asegurarTablaIntentosLogin()` para no ejecutar el DDL solo-SQLite de `intentos_login` fuera de SQLite (evita login caído en MariaDB).

**Pendiente — retomar aquí:**
1. ✅ ~~Tokenizar las 435 referencias~~ — hecho (linter en 21; restan solo los 3 scripts de PDO crudo de abajo).
2. **Fixes en origen** (no auto-traducibles por el motor de dialecto): `julianday()` (HomeService, ReportesService), aritmética de fechas `datetime('now','-N días')` / concat `||` (UsuarioService, CopilotService), `ON CONFLICT` (PushService, TurnosImportService) → calcular en PHP y pasar como parámetro, o reescribir.
3. **Los 3 scripts de PDO crudo** (el linter los marca; el token no se expande ahí): `scripts/init-db.php` (4) → reescribir para MariaDB (multi-statement + `sqlite_master`→`information_schema`); `scripts/reset-admin-password.php` (1) → migrar a `Database` o usar DSN MariaDB propio; `scripts/prepare-demo-video.php` (16) → solo-demo local, migrar a `Database` o marcar como solo-SQLite.
4. **Baja (limpieza)**: `scripts/migrate-add-notificaciones.php` crea `notificaciones` por PDO crudo sin prefijo (dialecto solo-SQLite) → deprecar o whitelistear; la ruta canónica es `database-schema.mariadb.sql`.

**Verificación:** la suite PHPUnit (SQLite) corre en **PHP 8.2 local** y queda **185/185** (543 assertions; el fallo pre-existente de `AuditoriaServiceTest` quedó corregido). OJO: la suite **NO** detecta tokens faltantes (el prefijo es inerte en SQLite) → para eso está el **linter**, complementado con una revisión independiente que cubre los huecos que el linter no ve (nombres dinámicos, DDL en runtime / PDO crudo). La correctitud del SQL MariaDB se valida en **staging** (no hay MariaDB local; opción acordada con el dueño).

## Contexto y decisión

`atankalama-limpieza` se desplegará en el **mismo servidor cPanel** (cuenta `cat6852`,
dominio `atankalama.com`) donde ya vive y funciona `Maisterchef 2.0`. Se **descarta** el
despliegue original por EasyPanel/Docker (hubo cambios de proceso).

Decisiones tomadas con el dueño:
- **Una sola base compartida**: las tablas de atankalama vivirán en `cat6852_australia`
  (la misma BD de Maisterchef), diferenciadas por **prefijo `limpieza_`** (Maisterchef usa
  `maisterchef_`). Las apps son independientes (no se cruzan datos); se comparte BD por
  simplicidad operativa.
- **Reusar el usuario MySQL de Maisterchef**: en MariaDB los permisos son por base de datos;
  ese usuario tiene ALL PRIVILEGES sobre `cat6852_australia`, así que también puede gestionar
  las tablas `limpieza_*`. No se necesita crear usuario nuevo (opcional más adelante por
  higiene de credenciales — no da aislamiento de datos porque cPanel concede por BD completa).
- **Estrategia**: imitar el patrón de despliegue ya probado de Maisterchef, no inventar uno.

## Arquitectura objetivo (imitando Maisterchef)

| Pieza | Maisterchef (referencia) | atankalama (objetivo) |
|---|---|---|
| Entrada | `public_html/maisterchef/index.php` (stub) | `public_html/limpieza/index.php` (stub) → `public/index.php` |
| Código | `app_core/` fuera del docroot | `app_core/` con todo atankalama; solo `public/` servido |
| `.htaccess` | rewrite + deny `.env`/composer | igual + deny `src/ database/ scripts/ vendor/ docs/ views/` |
| BD | MariaDB `cat6852_australia`, `DB_PREFIX=maisterchef_` (Eloquent lo aplica solo) | misma BD, `DB_PREFIX=limpieza_` (aplicado por token, ver Fase 1) |
| Deploy | ZIP (con vendor) + FileZilla + File Manager + phpMyAdmin + auditoría | idéntico |
| Migración esquema | endpoint `/admin/migrate` | `scripts/init-db.php` adaptado a MariaDB |
| Crons | `ea-php84` en panel cPanel | igual (`sync-cloudbeds`, `recalcular-alertas`, `cleanup-retention`, backup) |
| Backup | `db:backup` acotado a `maisterchef_*` | `mysqldump` acotado a `limpieza_*` |

## La diferencia clave (por qué hay trabajo de código)

Maisterchef es Laravel: el prefijo lo aplica Eloquent transparente. atankalama usa **SQL
crudo sin ORM**, así que el prefijo y el dialecto MariaDB se construyen a mano. Enfoque:

- **Prefijo por token**: queries y esquema usan el token `#__`; `Database` lo reemplaza por
  `DB_PREFIX` antes de preparar. Con `DB_PREFIX=''` (local/SQLite) el token desaparece; con
  `limpieza_` (prod) produce `limpieza_usuarios`.
- **Timestamps en PHP**: se centraliza el "ahora" en PHP (`gmdate`) y se pasa como parámetro,
  eliminando la dependencia de `strftime` de SQLite (el grueso de las incompatibilidades).
- **Driver configurable**: `DB_CONNECTION` = `sqlite` (local/tests) | `mariadb` (prod). El
  código corre en ambos motores.

## Plan por fases

**Fase 1 — Capa de datos portable** *(ver "Estado actual" arriba para el detalle)*
1. ✅ `src/Core/Database.php`: conexión por `DB_CONNECTION` (sqlite|mariadb) + token `#__`→`DB_PREFIX` + `Database::now()`.
2. ✅ `.env.example` / `.env.production.example`: variables MariaDB + `DB_PREFIX`.
3. ✅ `docs/database-schema.mariadb.sql` (32 tablas, dialecto MySQL, tokens `#__`) — verificado.
4. ✅ **Motor de dialecto** `Database::applyDialect()` (traduce SQLite→MariaDB en runtime; reemplaza la idea original de reescribir cada query).
5. ✅ `scripts/lint-prefix-tokens.php` (verificador de tokens, sin MariaDB).
6. ✅ Tokenizadas 414 referencias en 25 archivos seguros (linter 435→21; los 21 restantes = 3 scripts de PDO crudo). Incluye fixes de revisión (`cleanup-retention` dinámico, `AuthService`/`Database::driver()`). Suite 185/185.
7. ⬜ Fixes en origen no auto-traducibles: `julianday()`, aritmética `datetime('now','-N días')`/`||`, `ON CONFLICT`.
8. ⬜ Reescribir/migrar los 3 scripts de PDO crudo: `scripts/init-db.php` para MariaDB (multi-statement + `information_schema`), `reset-admin-password.php` y `prepare-demo-video.php` a `Database`.

**Fase 2 — Empaquetado cPanel**
- Stub `index.php`, `.htaccess`, layout `app_core/`, `.env` de prod, crons, backup `mysqldump`.

**Fase 3 — La carga (deploy)**
- Build ZIP (con `vendor/`) → subir por FileZilla → extraer → crear tablas `limpieza_*` en
  `cat6852_australia` → `.env` (chmod 600) → seed (admin) → verificar.

## Convenciones .env (alineadas con Maisterchef)

```
# Producción (cPanel, BD compartida)
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cat6852_australia
DB_USERNAME=<usuario MySQL de Maisterchef>
DB_PASSWORD=<...>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_PREFIX=limpieza_

# Local / tests
# DB_CONNECTION=sqlite
# DB_PATH=database/atankalama.db
# DB_PREFIX=
```

## Verificación

- **Local**: `php -l` en archivos tocados; la suite PHPUnit sobre SQLite sigue verde (no se
  rompe el desarrollo actual — el camino MariaDB es aditivo/opt-in).
- **Con MariaDB** (local o staging): `init-db.php` crea tablas `limpieza_*`; smoke de login +
  checklist.
- **Prod**: importar el esquema en `cat6852_australia`, confirmar que NO toca ninguna tabla
  `maisterchef_*`.

> Nota de entorno: la máquina local tiene PHP 8.2; el proyecto exige 8.4. La verificación de
> runtime completa (contra MariaDB) requiere PHP 8.4 + un MariaDB local o de staging. Mientras
> tanto, los cambios se validan por sintaxis (`php -l`) y se mantienen compatibles con SQLite.
