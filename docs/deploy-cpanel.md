# Deploy en cPanel — atankalama.com/limpieza

> Runbook del deploy real (Fase 3 de `docs/migracion-mariadb-cpanel.md`), imitando
> el patrón probado de Maisterchef en la misma cuenta. **El hosting NO tiene SSH,
> consola ni composer**: todo va por FileZilla + File Manager + phpMyAdmin + cron.
> Última actualización: 2026-07-07 (deploy real ejecutado).

> **Datos reales de este hosting (verificados en el deploy del 2026-07-07):**
> - La carpeta home es **`/home4/cat6852`** (con `4`, NO `/home/cat6852`). Las rutas
>   `/home/cat6852/...` que aparecen abajo equivalen a `/home4/cat6852/...`.
> - El binario PHP CLI para los crons es **`/opt/alt/php84/usr/bin/php`** (hosting
>   **CloudLinux + LiteSpeed**, PHP 8.4.14; `PHP_BINARY` del web es `/usr/local/bin/lsphp`).
> - Los 4 wrappers de `deployment/cron/*.sh` **ya usan `$HOME`** para sus rutas (APP, logs,
>   backups), así que no dependen de `/home` vs `/home4`, y `PHP_BIN` ya trae la ruta real.

## Arquitectura

| Pieza | Valor |
|---|---|
| URL pública | `https://atankalama.com/limpieza` (subpath, como `/maisterchef`) |
| Docroot | `/home/cat6852/public_html/limpieza/` — stub `index.php` + `.htaccess` + estáticos |
| Código | `/home/cat6852/public_html/limpieza/app_core/` — TODO el código, denegado por web (`Require all denied`) |
| `.env` real | `app_core/.env` (chmod 600) — plantilla en `app_core/.env.production.example` |
| BD | `cat6852_australia` (COMPARTIDA con Maisterchef), tablas con prefijo `limpieza_` |
| Usuario MySQL | el de Maisterchef (ALL PRIVILEGES sobre la BD) |
| PHP | 8.4 (`ea-php84` vía MultiPHP; el server corre 8.4.21) |
| Subpath en el código | `BASE_PATH=/limpieza` en `.env` — router, links, API, PWA y cookie lo respetan |
| Crons | wrappers `.sh` en `/home/cat6852/cron/` (fuera del webroot) |
| Backups | `mysqldump` acotado a `limpieza_*` → `/home/cat6852/backups/limpieza/` |

## 0. Prerrequisitos

- Acceso cPanel (`cat6852`), FTP (`ftp.atankalama.com`, FileZilla) y phpMyAdmin.
- Credenciales MySQL de Maisterchef (van al `.env`), API keys Cloudbeds, credenciales SMTP del hosting.
- MultiPHP: el dominio en **ea-php84**. Verificar extensiones con el probe (paso 6):
  `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json` deben decir OK.

## 1. Build del ZIP (local, Windows)

```powershell
php scripts/build-cpanel-zip.php     # o: composer build-cpanel
```

Produce `build/limpieza-cpanel.zip` (~1.5 MB) con la estructura `limpieza/{index.php,
.htaccess, assets, sw.js, offline.html, uploads, app_core/...}` y **vendor de
producción fresco** (`--no-dev --prefer-dist`). El script audita el artefacto:
sin `.env`, sin `*.db`, sin `tests/`, sin `.git`, separadores `/`. Si la auditoría
falla, el build sale con error y NO hay ZIP.

> **El ZIP se genera con .NET `System.IO.Compression` vía PowerShell**
> (`scripts/zip-stage.ps1`, listado de auditoría con `zip-list.ps1`), forzando
> separadores `/`. NO con `tar.exe` ni `Compress-Archive` (lección real
> 18/07/2026): el `bsdtar` de Windows NO escribe formato zip real — con `-a`
> cae a tar/pax disfrazado de `.zip` y el `unzip` de cPanel lo rechaza con
> *"End-of-central-directory signature not found"*; `Compress-Archive` (PS 5.1)
> mete separadores `\` que rompen la extracción en Linux. El build valida la
> firma `PK\x03\x04` del artefacto antes de darlo por bueno.

## 2. Ensayo local (recomendado antes de cada deploy)

Sirve el ZIP extraído igual que el hosting (Apache + PHP 8.4 + subpath) contra
el MariaDB local:

```powershell
$dest = 'build\ensayo-docroot'
Remove-Item -Recurse -Force $dest -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $dest | Out-Null
tar.exe -xf build\limpieza-cpanel.zip -C $dest
# crear build\ensayo-docroot\limpieza\app_core\.env  (BASE_PATH=/limpieza,
# DB_HOST=db, DB_USERNAME=limpieza_app, DB_PASSWORD=limpieza_local_test,
# DB_PREFIX=limpieza_, CLOUDBEDS_DRY_RUN=true)
docker compose -f docker-compose.yml -f docker-compose.ensayo.yml up -d --build db ensayo
docker compose exec ensayo php /var/www/html/limpieza/app_core/scripts/init-db.php
docker compose exec ensayo php /var/www/html/limpieza/app_core/scripts/seed.php
# app en http://localhost:8091/limpieza/
```

Smokes mínimos: `/limpieza/login` 200, `/limpieza/manifest` 200 con
`start_url:/limpieza/home`, `/limpieza/api/health` 200,
`/limpieza/app_core/.env` **403**, login admin funciona.

## 3. Carga inicial de la BD (una sola vez)

La BD de prod nace **completa desde un dump generado en local** (sin ejecutar
nada en el server). El schema MariaDB ya incorpora todas las migraciones (no hay
que correr `migrate-add-*.php` en una instalación fresca).

```powershell
# En el ensayo local (paso 2) ya corriste init-db + seed. Suma el inventario real:
docker compose -f docker-compose.yml -f docker-compose.ensayo.yml exec ensayo php /var/www/html/limpieza/app_core/scripts/import-inventario-cloudbeds.php --dry-run
docker compose -f docker-compose.yml -f docker-compose.ensayo.yml exec ensayo php /var/www/html/limpieza/app_core/scripts/import-inventario-cloudbeds.php

# Dump SOLO de las tablas limpieza_* (la BD es compartida). En dos pasos y con
# --result-file DENTRO del contenedor: un redirect `>` de PowerShell 5.1
# escribiría UTF-16 con BOM y corrompería el .sql.
$tablas = docker compose -f docker-compose.yml -f docker-compose.ensayo.yml exec -T db mysql -ulimpieza_app -plimpieza_local_test -N -B -e "SHOW TABLES LIKE 'limpieza\_%'" cat6852_australia
docker compose -f docker-compose.yml -f docker-compose.ensayo.yml exec -T db sh -c "mysqldump -ulimpieza_app -plimpieza_local_test --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 --result-file=/tmp/limpieza-inicial.sql cat6852_australia $($tablas -join ' ')"
docker compose -f docker-compose.yml -f docker-compose.ensayo.yml cp db:/tmp/limpieza-inicial.sql build/limpieza-inicial.sql
```

Sanity del dump antes de subirlo (los cuatro deben dar lo esperado):

```powershell
$dump = Get-Content build\limpieza-inicial.sql
($dump | Select-String 'CREATE TABLE').Count            # 32
($dump | Select-String '^(CREATE DATABASE|USE )').Count  # 0
($dump | Select-String 'CREATE TABLE `(?!limpieza_)').Count  # 0
($dump | Select-String 'INSERT INTO .limpieza_habitaciones').Count  # 1
```

Importar en prod: phpMyAdmin → seleccionar **cat6852_australia** → Importar →
`limpieza-inicial.sql`. El dump NO trae `CREATE DATABASE`/`USE` y solo toca
tablas `limpieza_*`. **Verificar después que las `maisterchef_*` siguen intactas**
(conteo de tablas antes/después).

> Backup previo obligatorio: antes de importar, exportar las tablas
> `maisterchef_*` (o la BD completa) desde phpMyAdmin, como red de seguridad.

## 4. Subir el código

1. FileZilla → subir `build/limpieza-cpanel.zip` a `/home/cat6852/public_html/`.
2. File Manager → click derecho al zip → **Extract** (crea `public_html/limpieza/`).
3. Borrar el zip del server.
4. Permisos: `app_core/storage/logs/` escribible (755 suele bastar — mismo
   usuario web y cron en este hosting; no existe el problema de ownership del
   viejo deploy Docker).

## 5. Configurar el `.env`

1. File Manager → `public_html/limpieza/app_core/` → copiar
   `.env.production.example` → `.env` y completar:
   - `SESSION_SECRET` (generar local: `openssl rand -hex 32`)
   - `DB_USERNAME` / `DB_PASSWORD` (los de Maisterchef)
   - `CLOUDBEDS_API_KEY_*` / `CLOUDBEDS_PROPERTY_ID_*`
   - `SMTP_*` (cuenta de correo del hosting) y `VAPID_*` (generar local:
     `php scripts/generate-vapid-keys.php`)
   - Primer arranque prudente: `CLOUDBEDS_DRY_RUN=true` (las escrituras a
     Cloudbeds se simulan; pasar a `false` tras validar el paso 8).
2. `chmod 600 .env` (File Manager → Permissions → 600).

> **Gotcha crítico:** si el `.env` falta o quedó en otra ruta, la app NO falla:
> cae en silencio a SQLite y crea `app_core/database/atankalama.db`. El paso 7
> verifica que eso no haya pasado.

## 6. Descubrir el binario PHP CLI (para los crons)

El `php` del cron de cPanel es CGI (se traga los argumentos) y la ruta
`/opt/cpanel/ea-php84/...` no existe en este hosting. Descubrir la real:

1. Renombrar `deployment/cpanel/whichphp.php` con token aleatorio
   (`whichphp-K7X2M9.php`), subirlo a `public_html/limpieza/`.
2. Abrir `https://atankalama.com/limpieza/whichphp-K7X2M9.php` → anotar
   `PHP_BINDIR` y verificar las extensiones OK.
3. **Borrar el archivo** inmediatamente.
4. El binario CLI es `<PHP_BINDIR>/php` (probarlo en un cron de prueba si hay dudas).
   **En este hosting (verificado 2026-07-07):** `PHP_BINDIR=/opt/alt/php84/usr/bin` →
   binario CLI **`/opt/alt/php84/usr/bin/php`** (ya puesto como `PHP_BIN` en los `.sh`).

## 7. Smokes post-deploy (obligatorios)

> Hacerlos INMEDIATAMENTE después del paso 4/5: desde que el código queda
> publicado, el admin existe con la contraseña conocida del seed (`Admin2025!`).
> El login del smoke fuerza el cambio y cierra esa ventana — no dejarla abierta
> horas.

| Check | Esperado |
|---|---|
| `https://atankalama.com/limpieza/api/health` | 200 `{"ok":true,...}` |
| `https://atankalama.com/limpieza/login` | 200, página de login con estilos |
| `https://atankalama.com/limpieza/manifest` | 200, `start_url":"/limpieza/home` |
| `https://atankalama.com/limpieza/app_core/.env` | **403** (si da 200, PARAR: revisar `.htaccess` de app_core) |
| `https://atankalama.com/limpieza/app_core/src/Core/Config.php` | **403** |
| Login `11111111-1` / `Admin2025!` | fuerza cambio de contraseña → home admin |
| Cookie del navegador | `limpieza_session` con path `/limpieza` |
| File Manager: `app_core/database/` | SIN `atankalama.db` (si existe → el `.env` no se está leyendo) |
| DevTools → Application | Service worker activo con scope `/limpieza/`; prompt "Instalar app" disponible |

## 8. Crons (4 wrappers)

1. Editar los 4 `.sh` de `deployment/cron/`: reemplazar `PHP_BIN` por el binario
   del paso 6 (la ruta `APP` ya apunta a `/home/cat6852/public_html/limpieza/app_core`).
2. Subirlos por FTP a `/home/cat6852/cron/` (crear la carpeta si no existe;
   queda FUERA del webroot). **Saltos de línea LF** (FileZilla en modo binario
   o verificar con File Manager → Edit).
3. `chmod 755` a los 4.
4. cPanel → Cron Jobs → 4 entradas (el campo Command SOLO la ruta al `.sh` —
   la UI rechaza con 401 cualquier metacarácter) y **Cron Email vacío**:

```
*/10 * * * * /home4/cat6852/cron/limpieza-sync-cloudbeds.sh
*/15 * * * * /home4/cat6852/cron/limpieza-recalcular-alertas.sh
0 3 * * * /home4/cat6852/cron/limpieza-cleanup-retention.sh
30 3 * * * /home4/cat6852/cron/limpieza-backup-db.sh
```

> El sync tickea cada 10 min pero se **auto-regula** por
> `cloudbeds_config.sync_intervalo_minutos` (default 30, editable vía
> `PUT /api/cloudbeds/config`). Cambiar la cadencia NO requiere tocar el crontab.

5. Backup: crear `/home/cat6852/.my.cnf` (chmod 600) con:
   ```
   [client]
   user=USUARIO_MYSQL
   password=LA_PASSWORD
   ```
6. Verificar tras la primera hora: `/home/cat6852/logs/limpieza-sync.log` con
   ticks, una fila nueva en `limpieza_cloudbeds_sync_historial`, y (tras las
   03:30) un `.sql.gz` en `/home/cat6852/backups/limpieza/` **de tamaño normal
   (>50 KB)** con `OK` en `backup.log`. Si `mysqldump` no existe o exige SSL,
   el wrapper loguea ERROR y NO deja dumps basura — ajustar `DUMP_BIN`/`DUMP_ARGS`
   (`mariadb-dump`, `--skip-ssl`) y esperar el próximo ciclo.

## 9. Post-deploy funcional

1. Cambiar la contraseña del admin (el login la fuerza) y guardarla en 1Password.
2. Crear los usuarios reales (supervisoras, trabajadoras, recepción) — cada una
   recibe contraseña temporal (por email si SMTP quedó configurado).
3. Importar los turnos de la semana (`/limpieza/ajustes/importar-turnos`, CSV de Breik).
4. Validar una escritura a Cloudbeds con `CLOUDBEDS_DRY_RUN=true` (aprobar una
   auditoría de prueba → log "DRY-RUN: escritura simulada") y recién ahí poner
   `CLOUDBEDS_DRY_RUN=false` en el `.env`.
5. Instalar la PWA en los teléfonos del personal (banner "Instalar app").

## 10. Actualizaciones futuras (deploy delta)

0. **Cerrar la versión en `CHANGELOG.md` ANTES de buildear.** Cada deploy es una
   versión: los chicos suben el segundo número (v1 → v1.1), un cambio grande sube
   el primero (v1.x → v2); un cambio que no sube código (editar el `.env`, por
   ejemplo) **no** es una versión. Poner la fecha real del deploy en DD/MM/YYYY
   en lugar de `sin publicar` — el badge del home del Admin y
   `/ajustes/versiones` muestran **la última versión con fecha**, así que si te
   olvidás, prod dice que sigue en la versión anterior. El ZIP se arma después
   porque copia el `CHANGELOG.md` adentro.
1. Re-correr `php scripts/build-cpanel-zip.php` y subir/extraer el ZIP completo
   (2 MB — más simple y seguro que armar deltas a mano), o subir archivos
   sueltos por FTP si el cambio es puntual. El `.env` del server NO se toca
   (el ZIP no trae `.env` y extraer no lo pisa; verificar igual tras extraer).
2. Si el release trae migración de esquema: generar el SQL con prefijo
   `limpieza_` en local y correrlo por phpMyAdmin (no hay consola). **Orden:**
   si la columna es **aditiva** (con `DEFAULT`), correr el `ALTER` **ANTES** de
   subir el código — el código viejo ignora la columna nueva y el código nuevo
   la necesita, así el deploy queda sin downtime. Si la migración fuera
   destructiva o cambiara tipos, coordinar una ventana (no es el caso hasta hoy).
3. Bump de `CACHE_VERSION` en `sw.js` si cambió un asset y no se propaga
   (el SW ya hace stale-while-revalidate, normalmente no hace falta).
4. Smokes del paso 7.

## Troubleshooting

- **Todo da 404 bajo /limpieza/** → falta `BASE_PATH=/limpieza` en el `.env` o
  el `.htaccess` del docroot no se subió (mod_rewrite apagado da 500/listado).
- **Login ok pero vuelve a /login** → cookie: revisar que la URL sea HTTPS
  (`APP_ENV != local` fuerza cookie `secure`) y `BASE_PATH` correcto.
- **La API devuelve HTML del dominio raíz** → el navegador está pegándole a
  `atankalama.com/api/...` (sin subpath): caché vieja del SW → DevTools →
  Application → Unregister SW + hard reload (el SW nuevo es v4).
- **Cron manda emails cada 10 min** → falta redirección en el wrapper o el Cron
  Email no quedó vacío.
- **"attempt to write a readonly database" o aparece atankalama.db** → el `.env`
  no se leyó (ruta o permisos): la app cayó a SQLite. Corregir y borrar el `.db`.
- **`app_core/.env` descargable (200)** → el hosting no está aplicando
  `.htaccess` de app_core: verificar que el archivo llegó (los dotfiles a veces
  no se ven en File Manager → Settings → Show Hidden Files).

## 11. Historial de deploys

Registro de lo que se desplegó y cómo, para reconstruir el estado de prod sin
adivinar. Los deploys delta se hacen con el ZIP completo (§10) salvo nota.

| Fecha | Release | Notas |
|---|---|---|
| 2026-07-07 | **Deploy inicial** | App publicada en `atankalama.com/limpieza`. Código (`main` `08410de`) + `.env` (600) + dump limpio (156 hab reales, sin test rooms) + 4 cron. Fix VAPID en `generate-vapid-keys.php`. RUT admin → real por phpMyAdmin. |
| 2026-07-07 | **Editor de checklist + créditos por peso** (`822bc2b`) | Deploy delta, ZIP completo. **Migración:** `ALTER TABLE limpieza_items_checklist ADD COLUMN creditos INT NOT NULL DEFAULT 1;` por phpMyAdmin, corrida **antes** de extraer el ZIP (aditiva, backfill a 1 → reportes históricos idénticos). Sin cambios de dependencias (vendor sin tocar). Permiso `checklists.editar` **ya estaba** en prod (venía en el dump inicial + rol Admin `__ALL__`, que init-db/seed expanden a todos los códigos) → no se sembró nada. Smokes verdes: `/api/health` ok, `app_core/.env` 403, Ajustes → Checklists carga/edita/guarda, Reportes calcula bien. |
| 2026-07-18 | **Solicitudes de la empresa julio + versiones + zona horaria** → **v2** | Deploy delta, ZIP completo (`main` `bca9a8d`). SQL previo de §11.1 corrido en phpMyAdmin (tabla `limpieza_ui_config` + `apariencia.editar` a Supervisora y Admin). `.env` editado: `MAIL_TRANSPORT=mail` + `SMTP_FROM=sistema@atankalama.com` + `SMTP_FROM_NAME` (correo verificado: la recuperación de clave llega). Smokes verdes contra rutas NUEVAS (`/api/auth/recuperar` 200, `/ajustes/versiones` 302, `sw.js` v6, `custom.css` 200). **Aviso a supervisora:** el fix de zona horaria re-atribuye el trabajo de 20:00–22:00 al día correcto → los reportes históricos cambian. |
| 2026-07-18 | **Fix de asignación de hotel en usuarios** → **v2.1** | Deploy delta, ZIP completo. Solo código (3 vistas), sin SQL ni `.env`. Además: `UPDATE limpieza_usuarios SET hotel_default='ambos' WHERE hotel_default IS NULL OR hotel_default=''` en phpMyAdmin para los usuarios ya creados con "Ninguno". |

> **⚠️ Gotcha crítico de la extracción (lección real 18/07/2026):** el **Extract del
> File Manager de cPanel MEZCLA carpetas: crea los archivos nuevos pero NO pisa los
> que ya existen**. Si extraés el ZIP sobre un `public_html/limpieza/` existente, los
> archivos MODIFICADOS (router, `sw.js`, vistas viejas) quedan sin actualizar y las
> features nuevas dan 404 aunque el health check dé verde (la app vieja corre bien).
> **Método correcto:** renombrar `limpieza` → `limpieza_old` (queda de rollback),
> extraer el ZIP sobre `/public_html` (carpeta `limpieza/` inexistente → todo se
> escribe fresco), copiar `.env` de `limpieza_old/app_core/` al nuevo, smokes contra
> rutas NUEVAS (no solo `/api/health`), y recién ahí borrar `limpieza_old`.

### 11.1 SQL del release "solicitudes de la empresa julio" (phpMyAdmin)

Equivalente con prefijo `limpieza_` de `scripts/migrate-add-ui-config.php` (que en
prod no se puede correr por CLI). Idempotente:

```sql
CREATE TABLE IF NOT EXISTS limpieza_ui_config (
    clave        VARCHAR(100) PRIMARY KEY,
    valor        TEXT NOT NULL,
    updated_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
    updated_by   INT,
    FOREIGN KEY (updated_by) REFERENCES limpieza_usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO limpieza_permisos (codigo, descripcion, categoria, scope)
SELECT 'apariencia.editar', 'Editar los colores de las tarjetas de la aplicación', 'Apariencia', 'global'
 WHERE NOT EXISTS (SELECT 1 FROM limpieza_permisos WHERE codigo = 'apariencia.editar');

-- OJO: el '__ALL__' de Admin se expande al sembrar — un permiso nuevo hay que
-- concedérselo explícito también a Admin, no solo a Supervisora.
INSERT INTO limpieza_rol_permisos (rol_id, permiso_codigo)
SELECT r.id, 'apariencia.editar'
  FROM limpieza_roles r
 WHERE r.nombre IN ('Supervisora', 'Admin')
   AND NOT EXISTS (SELECT 1 FROM limpieza_rol_permisos rp
                    WHERE rp.rol_id = r.id AND rp.permiso_codigo = 'apariencia.editar');
```

No se insertan colores: sin filas en `limpieza_ui_config` la app usa los defaults
(paleta idéntica a la actual) — el deploy no cambia nada visual por sí solo.

### 11.2 SQL del release "versionado de checklists" (phpMyAdmin)

Equivalente con prefijo `limpieza_` de `scripts/migrate-add-version-checklists.php`.
Correr **antes** de extraer el ZIP. Es puramente aditiva: no toca ítems ni
ejecuciones, así que los reportes dan exactamente lo mismo antes y después.

⚠️ **No es re-corrible a ciegas.** MariaDB no acepta `ADD COLUMN IF NOT EXISTS` en
todas las versiones, y phpMyAdmin **corta la ejecución en el primer error**: si una
columna ya existiera, el `ALTER` da error 1060 y todo lo que viene después (el
backfill y el índice) **no se corre**. Si tenés que reintentar, borrá del bloque las
sentencias que ya pasaron.

```sql
ALTER TABLE limpieza_checklists_template ADD COLUMN version    INT NOT NULL DEFAULT 1;
ALTER TABLE limpieza_checklists_template ADD COLUMN raiz_id    INT NULL;
ALTER TABLE limpieza_checklists_template ADD COLUMN creado_por INT NULL;

ALTER TABLE limpieza_checklists_template
  ADD CONSTRAINT fk_checklists_template_creado_por
  FOREIGN KEY (creado_por) REFERENCES limpieza_usuarios(id) ON DELETE SET NULL;

-- Cada checklist existente es la v1 de su propia raíz.
UPDATE limpieza_checklists_template SET raiz_id = id, version = 1 WHERE raiz_id IS NULL;

-- UNIQUE (no un índice suelto): impide que dos guardados simultáneos dejen dos
-- versiones vigentes del mismo checklist. Va DESPUÉS del backfill: antes fallaría
-- por los raiz_id nulos repetidos.
CREATE UNIQUE INDEX idx_checklists_template_raiz_version
  ON limpieza_checklists_template(raiz_id, version);
```

La FK de `creado_por` no la crea el script de migración (SQLite no agrega FK por
`ALTER`), pero sí la trae el schema fresco de MariaDB: se agrega acá para que prod y
una instalación nueva no queden con esquemas distintos.

Verificación después de correrlo (debe dar una fila por checklist, todas con
`version = 1` y `raiz_id = id`):

```sql
SELECT id, raiz_id, version, activo, nombre FROM limpieza_checklists_template ORDER BY id;
```

Sin permisos nuevos: el historial reusa `checklists.ver` y el editor sigue con
`checklists.editar`.

**Smoke específico:** en Ajustes → Checklists, guardar un checklist debe mostrar el
toast «guardado como v2» y la tarjeta pasar de `v1` a `v2`; el botón **Historial**
debe listar las dos versiones (la v1 como no vigente). **No hacerlo con un checklist
real en horario de trabajo:** una limpieza en curso termina con su versión, pero las
que empiecen después usan la nueva.
