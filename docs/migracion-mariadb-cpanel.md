# Migración a MariaDB + despliegue en cPanel (compartido con Maisterchef)

> **Estado: Fase 1 COMPLETA y VALIDADA EN DOCKER** (rama `feat/migracion-mariadb`). Tokenización (paso 6), fixes en origen (paso 7) y scripts de PDO crudo (paso 8) hechos y verificados. Linter de tokens en **0**; suite **198/198**. **Validada end-to-end contra MariaDB 10.11 real** en Docker local (PHP 8.4, ver `docker-compose.yml` + `docs/plan-test-visual-botones.md`): `init-db.php` crea las 32 tablas `limpieza_*`, seeds OK y smoke autenticado (login, reportes, home, `LIMIT ?`) en HTTP 200 sin errores ni warnings SQL — esto cierra el caveat de "se confirma en staging" de más abajo. Sigue la **Fase 2** (empaquetado cPanel). Última actualización: 2026-06-30.

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

**Hecho en esta sesión (cierra Fase 1):**

- **Paso 7 — Fixes en origen** (no auto-traducibles por el motor de dialecto). Se centralizó la lógica de dialecto en `Database` con helpers puros y testeables:
  - `julianday(fin)-julianday(inicio)` → `Database::diffMinutosSql($ini,$fin)` (SQLite: `julianday`; MariaDB: `TIMESTAMPDIFF(SECOND, …)/60.0` normalizando el ISO). Sitios: `HomeService:275`, `ReportesService:317`.
  - Aritmética de fechas relativa `strftime('now','-90 days')` y `'-' || ? || ' hours'` → umbral calculado en PHP (`gmdate`) y pasado como parámetro. Sitios: `UsuarioService:378/392`, `CopilotService:151`.
  - `ON CONFLICT(...) DO UPDATE` → `Database::onConflictUpdate($conflict,$update)` (SQLite: `ON CONFLICT … excluded`; MariaDB: `ON DUPLICATE KEY UPDATE … VALUES()`). Sitios: `PushService:55`, `TurnosImportService:163`. Las UNIQUE keys ya existen en ambos esquemas.
- **Paso 8 — Scripts de PDO crudo:**
  - `scripts/init-db.php`: driver-aware. Selecciona schema (`database-schema.mariadb.sql` en MariaDB), check de existencia por `information_schema` (vs `sqlite_master`), aplica statement-por-statement con prefijo en MariaDB / archivo completo en SQLite, listado filtrado por prefijo, y sync RBAC vía `Database` con tokens `#__`.
  - `scripts/reset-admin-password.php`: migrado a `Config`+`Database` (token `#__usuarios`, driver configurado).
  - `scripts/prepare-demo-video.php` y `scripts/migrate-add-notificaciones.php`: **guard solo-SQLite** al inicio + **whitelist** en `lint-prefix-tokens.php` (son herramientas locales que no corren en MariaDB).

**Hallazgos NUEVOS de esta sesión** (no estaban en el handoff previo; encontrados por barrido exhaustivo + audit adversarial con MariaDB 10.11 real en Docker):
- **`LIMIT ?`** con prepares nativos (`ATTR_EMULATE_PREPARES=false`): riesgo de fallo en MySQL al bindear el entero como string. Corregido inline con `(int)` (seguro en ambos motores) en `NotificacionesService:48/80`, `AlertasService:158`, `CloudbedsSyncService:192`. *Nota: la verificación empírica fue ambigua (un verificador lo vio funcionar en MariaDB 10.11); el fix es defensivo y de comportamiento idéntico.*
- **`DATE(<col_iso>)`** (13 sitios en `ReportesService`/`HomeService`): el audit **refutó empíricamente** que rompa (MariaDB parsea el ISO con `T`/`Z` y solo emite Warning 1292). Aun así se agregó la traducción `DATE(col) → SUBSTR(col,1,10)` al motor de dialecto como **mejora de robustez** (sin warnings, sin depender de la coerción laxa; `SUBSTR` ≡ `DATE` validado).

**Verificación:** suite PHPUnit (SQLite) en **PHP 8.2 local** = **198/198** (560 assertions; +13 tests nuevos en `tests/Unit/DatabaseDialectTest.php` que **fijan el SQL MariaDB** de `translateDialect`/`diffMinutosSql`/`onConflictUpdate` sin necesitar un MariaDB). Linter de tokens = **0**. `init-db.php` validado end-to-end en SQLite (32 tablas) y el splitter del schema MariaDB validado offline (73 statements: 32 tablas + 41 índices, 0 malformados). La suite **NO** detecta tokens/dialecto roto (inerte en SQLite) → por eso el **linter** + los **tests de dialecto** + el **audit adversarial**. La correctitud final del SQL MariaDB se confirma en **staging** (no hay MariaDB local).

**Revisión independiente (2026-06-29):** 4 lentes con contexto limpio (dialecto, seguridad, completitud, tests) + verificación adversarial → **0 bloqueantes, 0 críticos/altos**. Deuda menor anotada (no bloqueante):
- `push_subscriptions`: el UNIQUE diverge entre motores — SQLite usa `endpoint` completo, MariaDB `endpoint(191)` (límite de índice utf8mb4). Riesgo real ínfimo (dos endpoints que difieran solo después de 191 chars); es decisión de esquema pre-existente.
- Los helpers `diffMinutosSql`/`onConflictUpdate` interpolan nombres de columna sin escape: hoy solo reciben literales del código (documentado en sus docblocks); validar columnas sería un endurecimiento futuro.
- El splitter de `init-db.php` asume `;` solo como terminador y comentarios de línea completa: válido para el schema MariaDB actual (sin triggers/BEGIN-END ni `;` en literales).
- `DatabaseDialectTest` fija las cadenas SQL pero no ejecuta el SQLite generado contra un PDO real, y no cubre la variante `strftime %S` (ningún call-site la usa).

> Nota de entorno: correr `phpunit` en paralelo en Windows produce errores espurios (`table permisos already exists`) por lock de WAL sobre `test.db` entre clases de test. NO es regresión (confirmado con `stash` del diff: mismos errores sin los cambios); en solitario la suite queda **198/198**.

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
7. ✅ Fixes en origen no auto-traducibles: `julianday()` (helper `diffMinutosSql`), aritmética `strftime('now','-N')`/`||` (umbral en PHP), `ON CONFLICT` (helper `onConflictUpdate`). Extra: `LIMIT ?` inline `(int)` y traducción `DATE(col)→SUBSTR` en el motor.
8. ✅ Scripts de PDO crudo: `scripts/init-db.php` driver-aware (MariaDB statement-por-statement + `information_schema`), `reset-admin-password.php` vía `Database`, `prepare-demo-video.php` y `migrate-add-notificaciones.php` con guard solo-SQLite + whitelist en el linter.

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
