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
> publicado, el admin existe con la contraseña temporal que imprimió `seed.php`
> (al azar desde v6.5; antes era una fija). El login del smoke fuerza el cambio y
> cierra esa ventana — no dejarla abierta horas.

| Check | Esperado |
|---|---|
| `https://atankalama.com/limpieza/api/health` | 200 `{"ok":true,...}` — **incluye `checks.esquema`: si da 503 con «Faltan migraciones», el SQL del release no se corrió (ver §7.1)** |
| `https://atankalama.com/limpieza/login` | 200, página de login con estilos |
| `https://atankalama.com/limpieza/manifest` | 200, `start_url":"/limpieza/home` |
| `https://atankalama.com/limpieza/app_core/.env` | **403** (si da 200, PARAR: revisar `.htaccess` de app_core) |
| `https://atankalama.com/limpieza/app_core/src/Core/Config.php` | **403** |
| `https://atankalama.com/limpieza/app_core/CHANGELOG.md` | **403** (un archivo estático: si se ve el texto, app_core está expuesto — ver §11.6) |
| Login `11111111-1` / contraseña temporal del seed | fuerza cambio de contraseña → home admin |
| Cookie del navegador | `limpieza_session` con path `/limpieza` |
| File Manager: `app_core/database/` | SIN `atankalama.db` (si existe → el `.env` no se está leyendo) |
| DevTools → Application | Service worker activo con scope `/limpieza/`; prompt "Instalar app" disponible |

### 7.1 Verificación de esquema (¿corrió el SQL del release?)

**Por qué existe este paso.** El 22/09/2026 se descubrió que el SQL del §11.5 (v6.4) nunca se
había corrido en producción. El código subió igual dentro del delta de la v6.5 y
`POST /api/auditoria/{id}/iniciar` tiró **500 en cada apertura de inspección durante días**,
sin que nadie lo notara: el frontend llama ese endpoint con un `.catch()` vacío. El KPI «tiempo
por inspección» quedó con un hueco irrecuperable. El runbook pedía correr el SQL antes del
código, pero **nada verificaba que se hubiera hecho**.

Ahora sí. `EsquemaService` compara la base viva contra las dos fuentes de verdad que ya
existen —`docs/database-schema*.sql` y `database/seeds/permisos.php`—, así que **cubre
automáticamente cualquier release futuro**, sin que haya que anotar nada en ninguna lista.

Se ve en tres lados:

1. **`/api/health`** → pasa a **503** si falta algo. Es el smoke de la tabla de arriba, así que
   un SQL olvidado se cae **el día del deploy**. Por ser público solo dice *cuántos* elementos
   faltan, nunca cuáles.
2. **Inicio → Salud del sistema** (permiso `sistema.ver_salud`) → tarjeta «Esquema de base de
   datos» con el detalle: qué tablas, columnas y permisos faltan.
3. **Por consola**, cuando querés el detalle sin entrar a la app:

```bash
/opt/alt/php84/usr/bin/php /home4/cat6852/public_html/limpieza/app_core/scripts/verificar-esquema.php
```

Sale con código 1 si falta algo, así que también sirve como cron. **Solo lee, nunca modifica.**

Si reporta faltantes: corré el SQL del release (§11 de este documento) o el
`scripts/migrate-*.php` que corresponda, y volvé a verificar. **El SQL va ANTES del código.**

> No verifica *qué roles* tienen cada permiso: esa asignación se edita desde Ajustes → Roles y
> Permisos y no tiene fuente de verdad en el código. Solo comprueba que el permiso exista en el
> catálogo — que es justo lo que faltaba en el incidente.

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

## 10. Actualizaciones futuras (deploy delta por FTP — método por defecto)

0. **Cerrar la versión en `CHANGELOG.md` ANTES de buildear.** Cada deploy es una
   versión: los chicos suben el segundo número (v1 → v1.1), un cambio grande sube
   el primero (v1.x → v2); un cambio que no sube código (editar el `.env`, por
   ejemplo) **no** es una versión. Poner la fecha real del deploy en DD/MM/YYYY
   en lugar de `sin publicar` — el badge del home del Admin y
   `/ajustes/versiones` muestran **la última versión con fecha**, así que si te
   olvidás, prod dice que sigue en la versión anterior. Editar el `CHANGELOG.md`
   antes vale para los dos métodos: en el delta se sube ese archivo, y en el ZIP
   se copia adentro.
1. **Subir el delta por FTP (método por defecto, decidido el 21/07/2026).** En
   vez de re-extraer el ZIP completo, subir por FileZilla **solo los archivos que
   cambiaron**, sobreescribiéndolos en su lugar. FileZilla pisa archivo por
   archivo, así que **esquiva el gotcha del Extract que mezcla carpetas** (ver §11)
   y no hace falta el baile de `limpieza_old`. Cómo armar la lista del delta sin
   olvidarse ninguno:
   - `git diff --name-only <commit-del-último-deploy> HEAD` — el commit de
     referencia es el que quedó anotado en la última fila de §11.
   - **Descartar** de esa lista `docs/**` y `tests/**`: no se despliegan (el build
     tampoco los copia, salvo los 2 `database-schema*.sql`).
   - **Mapeo repo → servidor:** la raíz del repo (`src/`, `views/`, `scripts/`,
     `public/`, `CHANGELOG.md`, `composer.*`) va bajo
     `public_html/limpieza/app_core/…` con la misma ruta relativa. Los archivos del
     docroot (`deployment/cpanel/docroot/index.php` y `.htaccess`) van a
     `public_html/limpieza/…` — rara vez cambian.
   - **⚠️ EXCEPCIÓN — los estáticos de `public/` van al DOCROOT, no a `app_core/`.**
     `public/assets/**`, `public/sw.js`, `public/offline.html` y `public/uploads/`
     se sirven desde `public_html/limpieza/…` (así los copia
     `build-cpanel-zip.php`), y el `.htaccess` del docroot tira **403 a todo
     `app_core/`**: un `assets/js/app.js` subido a `app_core/public/assets/` **no
     lo sirve nadie**. Subí las **dos** copias — la del docroot, que es la que ve
     el navegador, y la de `app_core/public/…`, de la que `layout.php` saca el
     `?v=filemtime(…)` que rompe la caché. Con una sola: o el código no llega, o
     el `?v=` miente. (Pasó en el deploy de la v6.12, 24/09/2026: los badges
     nuevos no aparecían porque `app.js` había ido solo a `app_core/`.)
   - **`vendor/` solo si cambió `composer.lock`** (agregar/actualizar dependencias).
     Si cambió, correr `composer install --no-dev` en local y subir `vendor/`
     entero — o, más simple, hacer ESE deploy con el ZIP completo. Un cambio de
     solo-código nunca toca `vendor/`.
   - El `.env` del server **NO se toca nunca** (nunca está en el delta).
   - Cuando el cambio toca *muchos* archivos, mueve archivos de lugar, o cambia
     `vendor/`, hacé el **ZIP completo** en su lugar (`php
     scripts/build-cpanel-zip.php` + método de §11 con `limpieza_old`): más simple
     y seguro que un delta grande a mano.
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
adivinar. Desde el 21/07/2026 los deploys de solo-código se hacen por **delta
FTP** (§10); el ZIP completo queda para cambios grandes o de `vendor/`.

| Fecha | Release | Notas |
|---|---|---|
| 2026-07-07 | **Deploy inicial** | App publicada en `atankalama.com/limpieza`. Código (`main` `08410de`) + `.env` (600) + dump limpio (156 hab reales, sin test rooms) + 4 cron. Fix VAPID en `generate-vapid-keys.php`. RUT admin → real por phpMyAdmin. |
| 2026-07-07 | **Editor de checklist + créditos por peso** (`822bc2b`) | Deploy delta, ZIP completo. **Migración:** `ALTER TABLE limpieza_items_checklist ADD COLUMN creditos INT NOT NULL DEFAULT 1;` por phpMyAdmin, corrida **antes** de extraer el ZIP (aditiva, backfill a 1 → reportes históricos idénticos). Sin cambios de dependencias (vendor sin tocar). Permiso `checklists.editar` **ya estaba** en prod (venía en el dump inicial + rol Admin `__ALL__`, que init-db/seed expanden a todos los códigos) → no se sembró nada. Smokes verdes: `/api/health` ok, `app_core/.env` 403, Ajustes → Checklists carga/edita/guarda, Reportes calcula bien. |
| 2026-07-18 | **Solicitudes de la empresa julio + versiones + zona horaria** → **v2** | Deploy delta, ZIP completo (`main` `bca9a8d`). SQL previo de §11.1 corrido en phpMyAdmin (tabla `limpieza_ui_config` + `apariencia.editar` a Supervisora y Admin). `.env` editado: `MAIL_TRANSPORT=mail` + `SMTP_FROM=sistema@atankalama.com` + `SMTP_FROM_NAME` (correo verificado: la recuperación de clave llega). Smokes verdes contra rutas NUEVAS (`/api/auth/recuperar` 200, `/ajustes/versiones` 302, `sw.js` v6, `custom.css` 200). **Aviso a supervisora:** el fix de zona horaria re-atribuye el trabajo de 20:00–22:00 al día correcto → los reportes históricos cambian. |
| 2026-07-18 | **Fix de asignación de hotel en usuarios** → **v2.1** | Deploy delta, ZIP completo. Solo código (3 vistas), sin SQL ni `.env`. Además: `UPDATE limpieza_usuarios SET hotel_default='ambos' WHERE hotel_default IS NULL OR hotel_default=''` en phpMyAdmin para los usuarios ya creados con "Ninguno". |
| 2026-07-20 | **Versionado de checklists (copy-on-write)** → **v2.2** (`dce4da9`) | Deploy delta, ZIP completo. **El SQL de §11.2 se corrió DESPUÉS del código** (orden invertido, ver gotcha abajo): el editor y el historial quedaron rotos hasta que se aplicó. Sin permisos nuevos. Smoke de código nuevo por **contraste de rutas**: `/api/checklists/templates/1/historial` → 401 (existe) vs. ruta inventada → 404. La fecha de la v2.2 se puso editando `app_core/CHANGELOG.md` a mano (el ZIP se había armado con "sin publicar"). |
| 2026-07-21 | **Arreglos del editor de checklists** → **v2.3** (`bf32d6a`) | **Primer deploy por delta FTP** (nuevo método por defecto, §10). Cierra la brecha main↔prod que dejó v2.2: sube el fix del 409 (carrera de dos guardados + bucle del editor) y el guard de misma raíz en la herencia de la re-limpieza (commit `6950766`). 4 archivos por FileZilla a `app_core/`: `src/Services/ChecklistService.php`, `views/ajustes-checklists.php`, `CHANGELOG.md`, `scripts/migrate-add-version-checklists.php`. Sin SQL, sin permisos, sin `.env`, sin `vendor/`. Smokes verdes (funcionales): badge del home = v2.3, editar+guardar un checklist crea versión, `app_core/.env` → 403. |
| 2026-07-22 | **Alerta de inventario Cloudbeds** → **v2.4** (`72ac75e`) | Deploy delta, **ZIP** (12 archivos, ver §11.3). **SQL previo** en phpMyAdmin: solo el permiso `habitaciones.importar_inventario` (INSERT IGNORE) a Supervisora + Admin — **el ALTER del CHECK NO hizo falta**: `information_schema.CHECK_CONSTRAINTS` de `limpieza_alertas_activas` vino **vacío** en prod (el dump inicial no dejó CHECKs enforced sobre `tipo`), así que el `INSERT` de la alerta nueva no se bloquea. Sin `.env`, sin `vendor/`. Smokes verdes: badge home = v2.4 (verificado en **incógnito**, ver gotcha abajo), y `POST /api/inventario/rechazar` **sin body → 400** `ALERTA_REQUERIDA` (no 403 → el permiso llegó; no 404 → la ruta nueva vive), corrido desde la consola con sesión Admin. **Gotcha nuevo (extracción en el nivel equivocado):** el ZIP se extrajo primero en la **raíz** `public_html/` en vez de `public_html/limpieza/app_core/` → el código nuevo no se activó (health seguía verde, badge seguía v2.3). El badge v2.3 lo delató: `CHANGELOG.md` se lee **en vivo por request** (no OPcache, `Changelog::ruta()` = `basePath()/CHANGELOG.md`), así que un badge viejo = el archivo del server está viejo. Fix: mover el ZIP a `app_core/` y re-extraer ahí; después limpiar los `CHANGELOG.md`/`src`/`scripts`/`database`/`views` colgados en la raíz. **Lección: extraé SIEMPRE parado dentro de `app_core/`, y verificá el badge en incógnito (evita el SW cache).** |
| 2026-07-22 | **Checklist por tipo real + override por hotel** → **v2.5** (`221a4b3`, feature `4e61f20`) | Deploy delta por **FTP** (método por defecto): 9 archivos de código + 2 schemas a `app_core/`, extraídos LOCAL de `build/limpieza-v2.5-delta.zip` y arrastrados por FileZilla (pisa archivo por archivo). **SQL previo (§11.4) en phpMyAdmin, ANTES de subir código** (el código nuevo lee `checklists_template.hotel_id`): `ALTER TABLE limpieza_checklists_template ADD COLUMN hotel_id INT NULL` + índice `(tipo_habitacion_id, hotel_id)` + `INSERT IGNORE` del flag `tipos_checklist_por_hotel='0'`. Sin permisos nuevos, sin `.env`, sin `vendor/`. **Re-import obligatorio** corrido DESPUÉS del código, por **cron de una sola vez** (no hay SSH): wrappers `limpieza-reimport-{dryrun,apply}.sh` en `$HOME/cron/`, cron `* * * * *` apuntando primero al dry-run, revisado el log, luego al apply, y **borrado el cron + los `.sh`** al terminar. Resultado: **146 piezas re-tipadas** (89 `1_sur` + 57 `inn`) de los baldes viejos a su `roomTypeName` real, **0 creadas / 0 desactivadas / 0 colisiones**; idempotente confirmado (2ª corrida = `sin cambio: 146`). El import solo LEE de Cloudbeds y escribe la BD local (no depende de `CLOUDBEDS_DRY_RUN`). Smokes verdes: contraste de rutas `GET /api/checklists/config` → **401** (código nuevo activo) vs. inventada → 404; badge home = **v2.5** en incógnito; Ajustes → Checklists muestra ~13 tipos reales (no los 3 baldes); toggle "separar por hotel" crea override no-destructivo; iniciar una pieza no da 500 `TEMPLATE_NO_ENCONTRADO`. |
| 2026-07-25 | **Fix del service worker (clone de Response)** → **v2.6** (`1b18cea`) | Deploy delta por **FTP**, 2 archivos. **GOTCHA CLAVE:** `sw.js` se sirve desde el **DOCROOT** (`public_html/limpieza/sw.js`), NO desde `app_core`; el mapeo normal del delta (`public/` → `app_core/public/`) apuntaría a la copia deny-all (letra muerta) y el fix no se activaría. Archivos subidos: `public/sw.js` → `limpieza/sw.js` (docroot); `CHANGELOG.md` → `app_core/CHANGELOG.md`. Sin SQL, sin `.env`, sin `vendor/`. **El bug:** en `cacheFirst` el `response.clone()` corría dentro del `then` async de `caches.open` mientras el original se devolvía en paralelo → en cache miss el `respondWith` consumía el body antes de clonar → `Failed to execute 'clone' on 'Response': body already used` (sw.js:98). Fix: clonar sincrónico con el body intacto y cachear la copia. Bump `CACHE_VERSION` v6→v7. **Smoke verde:** `GET https://atankalama.com/limpieza/sw.js` → 200, `CACHE_VERSION = 'v7'`, y el fix `var copia = response.clone()` presente en el archivo servido (confirma que se pisó el archivo del docroot, no la copia muerta de app_core). |
| 2026-08-10 | **Vista guiada (ayuda por tarea en pantalla)** → **v3** (`dd81753`) | Deploy delta por **FTP**, 29 archivos (`build/limpieza-vg-v3-delta.zip`): docroot `sw.js` + `assets/vista-guiada/*.{js,css}`; `app_core/` `src/Support/{Tours,TourResolver}.php` + las 23 vistas con anclas `data-tour` + `CHANGELOG.md`. Motor portado del kit "vista-guiada" (botón «?» flotante por pantalla → recorridos por tarea con spotlight; catálogo en `Tours.php`, resolución por path en `TourResolver.php`, inyección en `layout.php` tras el flag `VISTA_GUIADA_HABILITADA`). Bump `CACHE_VERSION` v7→**v8**. Sin SQL, sin `vendor/`. **`.env`:** se agregó `VISTA_GUIADA_HABILITADA=true` al `app_core/.env` de prod (paso aparte, NO es una versión) para encender el «?»; Nicolás confirmó que funciona. Smokes verdes: `/api/health` ok, `sw.js` sirve v8, los 2 assets de `assets/vista-guiada/` → 200, badge home = v3. Red de seguridad: `ToursAnchorTest` renderiza cada vista real y verifica anclas ↔ pasos (sin huérfanos). |
| 2026-08-10 | **Tablero drag&drop de asignaciones** → **v4** (`dc9a751`, feature `35544ab`) | Deploy delta por **FTP**, 6 archivos (`build/limpieza-dnd-v4-delta.zip`, armado con `scripts/zip-stage.ps1`): docroot `sw.js` + `assets/js/drag-asignaciones.js` (**nuevo**) + `assets/css/custom.css`; `app_core/` `views/asignaciones.php` + `src/Support/Tours.php` + `CHANGELOG.md`. `/asignaciones` rediseñada como tablero de 2 columnas con **arrastrar-y-soltar** (motor pointer events, mouse + dedo, sin librerías) + **modo clásico** conservado tras un interruptor recordado por dispositivo (respaldo, sobre todo en teléfono). Reutiliza los endpoints existentes (`POST /asignaciones`, `/reasignar`, `/desasignar`, `PUT /orden`) → **sin SQL, sin permisos nuevos, sin `.env`, sin `vendor/`** (el reordenar usa `asignaciones.reordenar_cola_trabajador`, que ya existía). Bump `CACHE_VERSION` v8→**v9**. Vista Guiada: 4 recorridos de `asignaciones` reescritos (chips → arrastre), mismas 4 anclas (viven en el tablero; en modo clásico el «?» degrada con el fallback honesto, a propósito). Smokes verdes (curl): `/api/health` ok/db ok, `sw.js` sirve v9, `assets/js/drag-asignaciones.js` y `assets/css/custom.css` → 200 con tamaños **idénticos al local** (13515 / 5397 bytes = subida completa), `app_core/.env` → 403, badge home = v4. |
| 2026-08-20…22 | **v5 / v5.1 / v5.2** (jefe, FTP sin git) | Desplegados por el jefe **directo por FTP, sin registrar acá** (antes de pasar a git el 29/08). Contenido resumido en el CHANGELOG: **v5** (asignar responsable a un ticket, tomar un ticket sin dueño, «Reportar un problema» también dentro de la habitación y en el menú inferior, fotos en tickets con compresión), **v5.1** (colores por estado en Asignaciones, confiar en el estado real de Cloudbeds, fix de cambio de estado), **v5.2** (contador + buscador en Habitaciones). Además el jefe sumó módulos nuevos (Edificios/Mapeo, importar usuarios XLSX). Todo se importó al repo el 29/08 (`b506af1` + fix `b4d1db6`). **Este hueco queda a propósito: no se reconstruyen los detalles de FTP que no se anotaron en su momento.** |
| 2026-08-31 | **Vista guiada de Edificios y Tickets** → **v5.3** (`760215a`) | **Primer deploy por git de la nueva etapa de colaboración.** Delta por **FTP**, 5 archivos (`build/limpieza-vg-v53-delta.zip`, armado con `zip-stage.ps1`), **todos a `app_core/`**: `src/Support/{Tours,TourResolver}.php` + `views/{edificios,habitacion-detalle}.php` + `CHANGELOG.md`. Completa la ayuda guiada «?» para las features v5 del jefe: pantalla `edificios` (3 recorridos: crear/mapear/editar-borrar), tour de tickets enriquecido (**«Asignar responsable»** + fotos + foto de cierre), recorrido **«Reportar un problema»** en `habitacion.detalle` (ancla `hab.reportar`), y pantalla NUEVA `tickets.trabajador` resuelta por rol en `TourResolver` (`/tickets` sale del MAP; gate `tickets.ver_propios`). Incluye también el fix `b4d1db6` del 29/08 (anclas de turnos/usuarios) que se había pusheado sin desplegar. **Sin SQL, sin `sw.js`/assets (ningún asset cambió → sin bump de `CACHE_VERSION`), sin `.env` (`VISTA_GUIADA_HABILITADA` ya estaba en `true` desde v3), sin `vendor/`.** Suite 399/399. Smokes verdes: badge home = **v5.3** (incógnito), `/api/health` 200, y funcional: «?» en Ajustes→Edificios (3 recorridos), en Tickets (Reportar/Gestionar-con-asignar/Filtrar) y en el detalle de habitación («Reportar un problema»). **Gate de divergencia verificado antes de subir: prod estaba en v5.2 (el jefe no tocó nada por FTP desde el 22/08).** |
| 2026-09-13 | **Ayuda guiada de las features del v6 + fixes de seguridad** → **v6.1** (`05f75bf`) | Delta por **FTP**, 10 archivos (`build/limpieza-v61-delta.zip`, armado con `zip-stage.ps1`), **todos a `app_core/`**: `src/Support/Tours.php` + `views/habitacion-detalle.php` (bandera `data-vg-context` que gatea el recorrido «Dar por limpia») + 3 **fixes de seguridad** (`src/Controllers/TicketsController.php` bypass del guard al asignar responsable al crear un ticket; `src/Helpers/ExcelExport.php` y `src/Services/EspacioService.php::exportarCsv` neutralización de fórmulas CSV/Excel) + 4 **neutros** (`src/Services/ChecklistService.php` delay antifraude configurable vía `CHECKLIST_DELAY_MINIMO_SEGUNDOS` —default 180s = mismo comportamiento en prod— y 3 nits de PHPStan en `Auditoria/Habitacion/TurnoService`) + `CHANGELOG.md`. Completa la ayuda «?» para las features v6 del jefe: `asig.fecha` (planificar otro día), `hab.marcar-limpia` (dar por limpia sin checklist, gate `habitaciones.marcar_limpia_manual`), Espacios `esp.{buscar,tabla,exportar}`, Tickets `tk.tabla` (+ tabla en «mis tickets»), Reportes `rep.auditorias_pendientes`. **Las anclas `data-tour` ya estaban en prod** (markup del jefe, verificado ancla por ancla) → basta subir `Tours.php` server-rendered. **Sin SQL, sin permisos nuevos, sin `sw.js`/assets (los diffs de `vista-guiada.{js,css}` docroot eran puro CRLF vs LF → contenido idéntico → sin bump de `CACHE_VERSION`), sin `.env`, sin `vendor/`.** Suite 400/400, PHPStan verde. Smokes: badge home = **v6.1** (confirmado por Nicolás), `/api/health` 200 (db+env ok), login renderiza sin 500. **Contexto:** cierra el limbo del v6 del jefe (desplegado por FTP con el `CHANGELOG` en «sin publicar» → el parser ignoraba la fila y el badge mostraba v5.3); este deploy datea v6 y agrega v6.1. **Deuda anotada (NO tocada acá):** el repo quedó atrás en `public/assets/js/drag-asignaciones.js` — prod tiene el fix «click vs arrastre» del v6 (329 líneas) y el repo el de v4 (305); backportear aparte para no pisar el fix del jefe. |
| 2026-09-15 | **Cierre de día 23:55 + rename a Inspección + teclado** → **v6.2** (jefe, FTP directo) | Subido por el jefe **sin git** (cierre de día automático, estado `aprobada_automatica`, `cloudbeds_room_name`, rename Auditoría→Inspección, accesibilidad de teclado, fixes). Sus migraciones las corrió él (prod ya las tenía). Reintegrado al repo el mismo día (`7736999`, `c8def5d`, `85e44cc`). |
| 2026-09-16 | **Anti-bloqueo de admins** → **v6.3** (`594fdf2`) | Delta por **FTP**, 11 archivos (`build/limpieza-v63-delta.zip`, incluía también la reintegración v6.2). Sin SQL. Badge v6.3 confirmado. |
| 2026-09-16…21 | **Subida del jefe desde su copia local** (sin git, sin registrar) | ⚠️ **Revirtió** la v6.3 (`Database`, `RbacService`, `UsuarioService` y `modal-usuario-detalle` quedaron como copias viejas exactas), `Tours.php` (base v5.3), el `CHANGELOG` (el badge volvió a v5.3) y la bandera `data-vg-context` de `habitacion-detalle`. **Agregó:** tickets con varios responsables (tabla `tickets_asignados` + `scripts/migrate-add-tickets-asignados.php`), JS/CSS en `views/recursos/` servidos por la ruta `/views/recursos/{ruta*}`, áreas comunes por inspección, «Forzar actualización» y una cola offline a medio desplegar. Detectado en la revisión del 21/09 (descarga completa de prod); reintegrado en **v6.5**. |
| 2026-09-17 | v6.4 (ficha de KPIs) — **NO desplegada** | El delta armado (`build/cpanel-v64`) quedó obsoleto: su `Kernel.php` borraba `/views/recursos` (rompía Tickets) y su `AuditoriaService` dejaba las áreas atrapadas en «Por inspeccionar». Su contenido sale en **v6.5** (§11.6). |
| 2026-09-22 | **Mitigación: `app_core/` expuesto por web** (manual en cPanel) | `app_core/CHANGELOG.md` respondía 200: LiteSpeed no aplicaba el `Require all denied` de `app_core/.htaccess`, así que los scripts de `app_core/scripts/` se podían ejecutar por URL. Reglas `RewriteRule … [F]` en ambos `.htaccess` + borrado de archivos sobrantes (ver §11.6). **Confirmado:** `app_core/CHANGELOG.md` → 403 tras el deploy de v6.5. |
| 2026-09-22 | **Reintegración del jefe + ficha de KPIs + seguridad** → **v6.5** (incluye la v6.4; `ccf125b`, CHANGELOG `03a6472`) | Delta por **FTP**, 89 archivos (`build/limpieza-v65-delta.zip`, calculado comparando HEAD contra la descarga de prod del 21/09 — no con `git diff`, porque prod tenía cambios fuera de git). **SQL previo** en phpMyAdmin: `tickets_asignados` (idempotente, con backfill) + v6.4 (§11.5). Repone la v6.3 (anti-bloqueo) y los recorridos de ayuda perdidos; scripts con guarda «solo consola»; `.htaccess` con `RewriteRule [F]`. Verificado: badge v6.5 y todo funcionando (Nicolás); `app_core/CHANGELOG.md` → 403, `/api/health` 200, `/api/reportes/ficha` 401, `/views/recursos/tickets/tickets.js` 200. |
| 2026-09-22 | **RUT en los buscadores** → **v6.6** (`5117eea`, CHANGELOG `f9919aa`) | El modal global «Cambiar contraseña» dejaba 3 campos de contraseña ocultos y fuera de un `<form>` en todas las páginas: Chrome tomaba cada pantalla por un login y escribía el RUT guardado en el buscador (y la lista quedaba filtrada por ese RUT). Fix: `<template x-if>` + `<form>`. 1 archivo (`views/componentes/modal-cambiar-password.php`) + `CHANGELOG.md`; su archivo viajó también en el delta de la v6.7, que se calculó contra la v6.5 porque la subida de la v6.6 no estaba confirmada. Sin SQL ni assets. |
| 2026-09-22 | **Pieza en curso primero + piezas en progreso solo para Admin** → **v6.7** (`325753d`, CHANGELOG `76f03db`) | Delta por **FTP**, 13 archivos (`build/limpieza-v67-delta.zip`, contra el deploy de la v6.5; incluye el de la v6.6). Antes de armarlo se comprobó que las copias de prod de los 11 archivos que ya existían eran idénticas a la base del repo (sin cambios del jefe). **SQL** del permiso `asignaciones.mover_en_progreso` (§11.7) corrido en phpMyAdmin (confirmado por Nicolás). Sin assets → sin bump de `CACHE_VERSION`; sin `.env` ni `vendor/`. Verificado: badge v6.7 (Nicolás); `/api/health` 200, `/login` 200, `app_core/CHANGELOG.md` y `app_core/views/componentes/candado-en-progreso.php` → 403, `POST /api/asignaciones/reasignar` sin sesión → 401. |
| 2026-09-22 | **Pantalla «Todas las alertas»** → **v6.8** (`6a8a756`, CHANGELOG `540e6f6`) | El enlace «Ver todas las alertas (N)» de los dos Inicios apuntaba a `/alertas`, una ruta que **nunca se registró**: el router caía en su 404 genérico y el navegador mostraba el JSON `NO_ENCONTRADO` en vez de una pantalla. La pantalla estaba especificada en `docs/home-admin.md` y `docs/home-supervisora.md` desde el principio. Delta por **FTP**, 4 archivos (`build/limpieza-v68-delta.zip`): `src/Core/Kernel.php`, `src/Controllers/PaginasController.php`, `views/alertas.php` (nuevo) y `CHANGELOG.md`. Los dos Inicios **no se tocaron** (el enlace ya apuntaba bien). Sin SQL, sin assets → sin bump de `CACHE_VERSION`; sin `.env` ni `vendor/`. Verificado: badge v6.8 (Nicolás); `/api/health` 200, `/login` 200, `/alertas` sin sesión → **302 a `/login`** (antes 404), y los archivos nuevos cerrados por web: `app_core/views/alertas.php` y `app_core/CHANGELOG.md` → 403. |
| 2026-09-22 | **Verificador de esquema** → **v6.9** (`789ff41`, CHANGELOG `67cd436`) | Cierra la causa de fondo del incidente del mismo día: el SQL de la v6.4 nunca se había corrido en prod y **nada lo verificaba**. Ahora `EsquemaService` compara la base viva contra `docs/database-schema*.sql` y `database/seeds/permisos.php` (las dos fuentes de verdad que ya existían → cubre cualquier release futuro sin listas que mantener). Se ve en `/api/health` (**503** si falta algo, sin nombrar nada por ser público), en Inicio → Salud del sistema (detalle, tras `sistema.ver_salud`) y en `scripts/verificar-esquema.php`. Ver **§7.1**. Incluye el arreglo del schema de MariaDB, al que le faltaban `edificios` y `habitaciones.edificio_id/edificio/piso` (deuda vieja: estaban solo en el de SQLite, y sin ellas el verificador tendría un punto ciego en prod). Delta por **FTP**, 8 archivos (`build/limpieza-v69-delta.zip`), **sin SQL** (el release no necesita migración), sin assets → sin bump de `CACHE_VERSION`. **OJO: `database-schema.mariadb.sql` va a `app_core/docs/`, que es de donde lo lee el verificador.** **Chequeo previo antes de subir** (`build/precheck-esquema-prod.sql`, generado desde el parser): las 289 columnas y los 69 permisos esperados ya estaban en prod → deploy sin sorpresas. Verificado: badge v6.9 (Nicolás); `/api/health` **200 con `checks.esquema.ok: true`**, `/login` 200, y `app_core/scripts/verificar-esquema.php`, `app_core/src/Services/EsquemaService.php` y `app_core/docs/database-schema.mariadb.sql` → 403. |
| 2026-09-23 | **La pieza aprobada no vuelve a la cola con el check-in** → **v6.10** (`395f96b`, CHANGELOG `a0740b8`) | Cloudbeds marca `dirty` apenas hace check-in un huésped (marca del servicio del día siguiente, no limpieza pendiente); el sync lo tomaba al pie de la letra y deshacía la aprobación del día. Caso testigo: la **706** aprobada 11:15 del 22/09, escritura a Cloudbeds `success:true`, y a las 11:41 de vuelta a sucia → la limpiaron dos veces; ese día le pasó a ~8 piezas. Regla nueva en `CloudbedsSyncService::conservarAprobacionDelDia()` (conserva solo si la aprobación es del día Y la pieza está ocupada; el check-out sigue revirtiendo, y los nocheros van por su barrido de las 16:00). Además deshacer una aprobación deja WARNING + **alerta P1 `aprobacion_deshecha`** para la supervisora — antes era mudo. Delta por **FTP**, 9 archivos (`build/limpieza-v610-delta.zip`), **sin SQL**: el tipo de alerta nuevo entra al `CHECK` de los schemas, pero en prod esa tabla no tiene CHECKs activos (verificado en el deploy de la v2.4). Sin assets → sin bump de `CACHE_VERSION`. Verificado tras el deploy: badge **v6.10** (Nicolás); `/api/health` **200 con `checks.esquema.ok: true`** (el tipo nuevo no rompió el verificador), `/login` 200 y `app_core/` → 403 en los 4 archivos del delta. **Gotcha operativo:** durante la verificación el firewall del hosting (CSF) baneó la IP desde la que se sondeaba —DNS resolvía pero el TCP no conectaba, con un sitio de control respondiendo normal—; es temporal y no afecta a los usuarios. **Espaciar los curl de verificación.** |

| 2026-09-23 | **El cierre de día conserva su marca + piezas ocupadas que sí vuelven a la cola** → **v6.11** (`8a27cf4`, CHANGELOG `8ffe38a`) | Segundo deploy del día, a las pocas horas de la v6.10. Incidente del 23/09 (`docs/incidente-2026-09-23.md`): la supervisora tuvo que cargar **30 piezas a mano** porque no volvían solas a la lista. Tres arreglos: el sync no excluía `aprobada_automatica` y la re-aprobaba a `aprobada` ~10 min después de cada cierre de día (**49 veces ese día**), lo que borraba la marca de «nadie la inspeccionó» —**los KPIs de cobertura de la v6.4 venían inflados**— y le movía el `updated_at`, del que depende `conservarAprobacionDelDia()` desde la v6.10; esa misma regla conservaba un `turnover` (se va un huésped y entra otro el mismo día, justo cuando hay que limpiar) y alcanzaba a las `rechazada`, porque el guard de afuera pregunta por estado terminal; y un trabajador sin ninguna pieza empezable recibía `NO_ES_TU_HABITACION_ACTUAL` —una habitación que no existe— con la ficha sin recargarse, o sea un loop (caso real: 19 asignadas, 0 limpiadas). Delta por **FTP**, 4 archivos (`build/limpieza-v611-delta.zip`), **sin SQL**, sin assets → sin bump de `CACHE_VERSION`, sin `.env` ni `vendor/`. Suite 489/489 con 4 tests de regresión nuevos, verificados contra el código viejo (fallan los 4). Verificado: badge **v6.11** (Nicolás); `/api/health` 200 con `checks.esquema.ok: true`, `/login` 200, y `app_core/CHANGELOG.md` + `app_core/src/Services/CloudbedsSyncService.php` → 403. **NO incluye el hallazgo más grave, que no es código:** en cPanel hay **dos** entradas de `aprobar-pendientes-cierre-dia.php`, `55 23` (correcta) y **`50 15`**, así que el cierre de día corre también en plena jornada y aprueba solas las piezas que esperan inspección (**83 el 23/09**). Se recomendó a jefatura eliminar la de las 15:50, por resumen ejecutivo; la decisión es de ellos. Ojo: el runbook documenta 4 cron (§8) y ni el cierre de día ni el reporte de las 23:50 están ahí — los agregó jefatura por fuera, y nada compara esa lista. |

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

> **⚠️ Que la pantalla de Checklists cargue NO prueba que este SQL haya corrido**
> (falso positivo real del 20/07/2026). La lista se arma con un `SELECT ct.*`, que
> funciona igual sin las columnas nuevas, y el badge del front dice `t.version || 1`
> → **muestra "v1" aunque el dato no exista**. Mismo error de razonamiento que dar por
> bueno un `/api/health` verde. **El chequeo que sí sirve es el botón "Historial"**:
> su consulta pide `version`, `raiz_id` y `creado_por` por nombre, así que sin la
> migración devuelve lista vacía + toast rojo. Es de solo lectura: se puede tocar en
> cualquier momento, incluso con gente limpiando.
>
> Si el código sube antes que el SQL (lo que pasó), **nada se rompe para la
> operación**: limpiar, marcar ítems, auditar, asignar y los reportes no tocan esas
> columnas. Solo quedan caídos el editor de checklists, su historial y el alta/edición
> de áreas comunes, hasta que se aplique la migración.

### 11.3 SQL del release "alerta de inventario Cloudbeds" (phpMyAdmin)

Detección automática de altas/bajas de piezas en Cloudbeds → alerta a la supervisora
con Aceptar/Rechazar (ver `docs/cloudbeds-import-inventario.md`). Dos cambios en prod:
un **tipo de alerta nuevo** (CHECK) y un **permiso nuevo**.

**Vía recomendada (prod tiene PHP CLI):** correr los dos, son idempotentes y no borran
nada:

```bash
/opt/alt/php84/usr/bin/php scripts/migrate-add-inventario-alerta.php   # amplía el CHECK
/opt/alt/php84/usr/bin/php scripts/init-db.php                         # sync RBAC: agrega el permiso + rol_permisos
```

`init-db.php` detecta que el schema ya está y **solo** corre el sync idempotente de
permisos (INSERT OR IGNORE); no toca datos. `migrate-add-inventario-alerta.php` es
re-corrible (si el tipo ya está, no hace nada).

**Fallback phpMyAdmin** (si no se quiere tocar la CLI). Correr **antes** de extraer el ZIP:

```sql
-- 1) Tipo de alerta nuevo: ampliar el CHECK de `tipo`. El CHECK tiene nombre
--    autogenerado; ubicalo primero (suele ser 'CONSTRAINT_1' o similar):
SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE()
   AND TABLE_NAME = 'limpieza_alertas_activas'
   AND CHECK_CLAUSE LIKE '%cloudbeds_sync_failed%';

-- ...y con ese nombre (reemplazá <NOMBRE>):
ALTER TABLE limpieza_alertas_activas
  DROP CONSTRAINT `<NOMBRE>`,
  ADD  CONSTRAINT `<NOMBRE>` CHECK (tipo IN (
    'cloudbeds_sync_failed','trabajador_en_riesgo','habitacion_rechazada',
    'fin_turno_pendientes','trabajador_disponible','ticket_nuevo',
    'habitacion_saltada','inventario_cambios_pendientes'));

-- 2) Permiso nuevo + otorgarlo a Supervisora y Admin (Admin materializa __ALL__ en filas).
INSERT IGNORE INTO limpieza_permisos (codigo, descripcion, categoria, scope)
VALUES ('habitaciones.importar_inventario',
        'Aplicar altas/bajas de habitaciones detectadas en Cloudbeds', 'Cloudbeds', 'global');

INSERT IGNORE INTO limpieza_rol_permisos (rol_id, permiso_codigo)
SELECT id, 'habitaciones.importar_inventario' FROM limpieza_roles WHERE nombre IN ('Supervisora','Admin');
```

Si el código sube antes que el SQL: la operación normal no se entera; solo que la
alerta de inventario no se puede levantar (el `INSERT` de la alerta viola el CHECK) ni
aplicar (falta el permiso → 403) hasta correr la migración.

**Smoke específico** (toca lo que cambió, no un health verde): con un usuario Supervisora,
`POST /api/inventario/rechazar` sin body debe dar **400** (`ALERTA_REQUERIDA`), no 403 —
eso prueba que el permiso llegó. Y forzar un chequeo con
`php scripts/check-inventario-cloudbeds.php --force` debe imprimir "sin cambios" o
"alerta levantada" sin error de CHECK.

### 11.4 SQL del release "checklist por tipo real + override por hotel" (phpMyAdmin)

El tipo de cada pieza pasa a ser el `roomTypeName` real de Cloudbeds (no `maxGuests`), y
se agrega el toggle "separar checklists por hotel" con override por propiedad. Ver
[checklist.md](checklist.md) §2.6 y [cloudbeds-import-inventario.md](cloudbeds-import-inventario.md).

**Un cambio de esquema** (columna `hotel_id` en `checklists_template`) + **un re-import obligatorio**.

**Vía recomendada (PHP CLI), idempotente, no borra nada:**

```bash
/opt/alt/php84/usr/bin/php scripts/migrate-add-checklist-por-hotel.php   # columna hotel_id + flag
# ⚠️ PASO CRÍTICO — re-tipar el inventario ANTES de que el cron de inventario levante alerta:
/opt/alt/php84/usr/bin/php scripts/import-inventario-cloudbeds.php --dry-run   # revisar el plan
/opt/alt/php84/usr/bin/php scripts/import-inventario-cloudbeds.php             # aplicar: re-tipa + crea tipos+checklists
```

> **Por qué el re-import es obligatorio y va primero.** El import re-tipa las piezas de los
> baldes viejos (`Singular`/`Doble/Matrimonial`/`Suite/Familiar`) a sus `roomTypeName` reales y
> crea un checklist default por cada tipo nuevo. El histórico queda intacto (cada ejecución guarda
> su `template_id` en snapshot; los reportes no agrupan por tipo). **Pero** el chequeo diario de
> inventario (`check-inventario-cloudbeds.php`) compara el tipo actual contra el de Cloudbeds: si
> el re-import no corrió, la primera corrida vería las ~150 piezas como "Cambio" y le levantaría a
> la supervisora una alerta enorme. Correr el import (aplicar) en el deploy lo evita.

**Fallback phpMyAdmin** (correr **antes** de extraer el ZIP; el re-import igual hay que correrlo por CLI o botón):

```sql
-- 1) Columna hotel_id (NULL = checklist compartido; != NULL = override de ese hotel) + índice.
ALTER TABLE limpieza_checklists_template ADD COLUMN hotel_id INT NULL;
CREATE INDEX idx_checklists_template_tipo_hotel
  ON limpieza_checklists_template (tipo_habitacion_id, hotel_id);

-- 2) Flag del toggle (default apagado = checklists compartidos).
INSERT IGNORE INTO limpieza_cloudbeds_config (clave, valor, descripcion)
VALUES ('tipos_checklist_por_hotel', '0',
        'Separar los checklists de tipo por hotel (override por propiedad)');
```

Si el código sube antes que el SQL: la app falla al leer `checklists_template` (la columna
`hotel_id` no existe todavía) — por eso el ALTER va **antes** de extraer el ZIP.

**Smoke específico** (toca lo que cambió): en incógnito, con Admin/Supervisora, entrar a
**Ajustes → Checklists**; las tarjetas deben mostrar los `roomTypeName` reales (no los 3 baldes
viejos). Activar el toggle "Separar por hotel", elegir un hotel, "Personalizar" un tipo y guardar:
la tarjeta pasa de "Usa el compartido" a mostrar el override; la vista Compartido y el otro hotel
quedan intactos. `POST /api/habitaciones/{id}/iniciar` de una pieza cualquiera no debe dar 500
`TEMPLATE_NO_ENCONTRADO`.

### 11.5 SQL del release "ficha de KPIs en Reportes" → v6.4 (phpMyAdmin)

Reportes trae la ficha completa de KPIs (`docs/kpis-sueldos.md`). **Dos cambios de datos**, ambos
**ANTES de subir el código** (`ReportesService` lee la columna nueva y el controller consulta el permiso):

1. Columna `auditoria_iniciada_at` en `ejecuciones_checklist` (inicio de la inspección → KPI «tiempo por auditación»).
2. Permiso `reportes.ver_supervisoras` (privacidad jerárquica de tiempos), concedido a los roles que
   administran la matriz (los que tienen `permisos.asignar_a_rol`), NO a las supervisoras.

**Vía recomendada (PHP CLI o cron de una sola vez, ver v2.5), idempotente:**

```bash
/opt/alt/php84/usr/bin/php scripts/migrate-add-auditoria-iniciada-at.php
/opt/alt/php84/usr/bin/php scripts/migrate-add-permiso-reportes-supervisoras.php
```

**Fallback phpMyAdmin** (equivalente, con prefijo `limpieza_`):

```sql
-- 1) Inicio de la inspección (se sobreescribe en cada apertura del detalle).
ALTER TABLE limpieza_ejecuciones_checklist ADD COLUMN IF NOT EXISTS auditoria_iniciada_at VARCHAR(30) NULL;

-- 2) Permiso nuevo + concesión a los roles administradores.
INSERT INTO limpieza_permisos (codigo, descripcion, categoria, scope)
SELECT 'reportes.ver_supervisoras',
       'Ver los KPIs y tiempos de las supervisoras en Reportes (sección Supervisora · Inspección)',
       'Reportes', 'global'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM limpieza_permisos WHERE codigo = 'reportes.ver_supervisoras');

INSERT INTO limpieza_rol_permisos (rol_id, permiso_codigo)
SELECT DISTINCT rp.rol_id, 'reportes.ver_supervisoras'
  FROM limpieza_rol_permisos rp
 WHERE rp.permiso_codigo = 'permisos.asignar_a_rol'
   AND NOT EXISTS (SELECT 1 FROM limpieza_rol_permisos x
                    WHERE x.rol_id = rp.rol_id AND x.permiso_codigo = 'reportes.ver_supervisoras');
```

Si el código sube antes que el SQL: `GET /api/reportes/ficha` cae con 500 (columna inexistente) y
la sección de supervisoras no aparece para nadie (permiso inexistente) — por eso el SQL va **antes**.
**Sin `.env`, sin `vendor/`, sin `sw.js`/assets (no cambió ningún asset → sin bump de `CACHE_VERSION`).**

**Smoke específico:** badge home = **v6.4** (incógnito); en Reportes, con Admin, aparecen «Ficha de
KPIs · Trabajador» (columna «Asignadas») y «Supervisora · Inspección» con la tabla «Por turno»; con una
supervisora (si tuviera `reportes.ver`) NO aparece la segunda. Contraste de rutas: `GET /api/reportes/ficha`
→ 401 sin sesión (existe) vs ruta inventada → 404; `POST /api/auditoria/1/iniciar` → 401. Abrir una pieza
pendiente en Inspección y aprobarla: en Reportes, «T. por insp.» de esa inspectora deja de ser «—».

### 11.6 Release "reintegración del jefe + seguridad" → v6.5 (incluye la v6.4)

Junta en un solo deploy: la **v6.4** (ficha de KPIs, nunca desplegada), la **reintegración** de lo que el jefe
subió directo a prod entre el 16 y el 21/09, la **reposición de la v6.3** (anti-bloqueo) que esa subida revirtió,
y **arreglos de seguridad**. Detalle en el CHANGELOG (filas v6.4 y v6.5).

**0. Antes que nada — mitigación de `app_core/` (22/09, a mano en cPanel).** Si todavía no se hizo:

- en `public_html/limpieza/.htaccess`, justo debajo de `RewriteBase /limpieza/`, las reglas
  `RewriteRule (^|/)app_core(/|$) - [F,L]`, `RewriteRule (^|/)\. - [F,L]` y `RewriteRule (^|/)error_log$ - [F,L]`;
- `public_html/limpieza/app_core/.htaccess` con `RewriteEngine On` + `RewriteRule ^ - [F]` además del
  `Require all denied`;
- borrar del servidor lo que sobra: `scratch.php`, `docs/` y `.DS_Store` del docroot, y en `app_core/`:
  `.env_local` (**no** `.env`), `database/atankalama.db*` (**no** `database/seeds/`), `scripts/dryrun.log`,
  `storage/logs/fallback.log`, `error_log`.

Los dos `.htaccess` del repo (`deployment/cpanel/`) ya traen esas reglas y viajan en el delta. **Prueba:**
`https://atankalama.com/limpieza/app_core/CHANGELOG.md` debe dar **403**. **Nunca** probar abriendo la URL de un
script: eso lo ejecuta. Desde v6.5 cada script de `app_core/scripts/` además se niega a correr si lo pide la web
(guarda «solo consola»), así que la protección ya no depende solo del `.htaccess`.

**1. SQL, ANTES de subir el código** (idempotente):

1. **`tickets_asignados`** (la usa Tickets). Lo más probable es que ya exista, porque la feature del jefe está
   viva (chequeo: `SHOW TABLES LIKE 'limpieza_tickets_asignados';`). Igual correr el bloque completo: es
   idempotente, y el `INSERT IGNORE` del final completa los tickets asignados antes de la tabla (si faltan,
   se ven con «Responsable» en vez del nombre y no aparecen en «Asignados a mí»):

   ```sql
   CREATE TABLE IF NOT EXISTS limpieza_tickets_asignados (
       ticket_id    INT NOT NULL,
       usuario_id   INT NOT NULL,
       asignado_por INT NOT NULL,
       created_at   VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
       PRIMARY KEY (ticket_id, usuario_id),
       FOREIGN KEY (ticket_id) REFERENCES limpieza_tickets(id) ON DELETE CASCADE,
       FOREIGN KEY (usuario_id) REFERENCES limpieza_usuarios(id) ON DELETE CASCADE,
       FOREIGN KEY (asignado_por) REFERENCES limpieza_usuarios(id) ON DELETE RESTRICT
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   CREATE INDEX IF NOT EXISTS idx_tickets_asignados_usuario ON limpieza_tickets_asignados(usuario_id);
   -- Backfill: el responsable único que ya tenían los tickets.
   INSERT IGNORE INTO limpieza_tickets_asignados (ticket_id, usuario_id, asignado_por, created_at)
   SELECT id, asignado_a, levantado_por, COALESCE(asignado_at, created_at)
     FROM limpieza_tickets WHERE asignado_a IS NOT NULL;
   ```

   (Equivale a `php scripts/migrate-add-tickets-asignados.php`.)
2. **La v6.4**: la columna `auditoria_iniciada_at` y el permiso `reportes.ver_supervisoras` — el SQL de §11.5, tal
   cual.

Los scripts de migración ahora son **solo consola**: correrlos por la Terminal de cPanel o como cron de una sola
vez (ver v2.5), o usar el SQL de arriba en phpMyAdmin.

**2. Archivos.** Delta por FTP (`build/limpieza-v65-delta.zip`, armado con `scripts/zip-stage.ps1`; la lista
exacta va en el ZIP). Dos cuidados:

- `app.js` y `custom.css` van a **las dos copias**: la del docroot (`assets/…`, la que se sirve) **y**
  `app_core/public/assets/…`. El `?v=` de cache-busting sale de la fecha de la copia de `app_core`: si solo se sube
  la del docroot, el `?v=` no cambia y los celulares siguen con la versión vieja cacheada. Con eso **no** hace falta
  subir `CACHE_VERSION` del `sw.js`.
- Limpieza opcional en `app_core/public/`: `assets/js/cola-offline.js` (la cola offline a medio desplegar del jefe;
  ya nadie la carga).

Sin `.env`, sin `vendor/`.

**3. Smoke específico:**

- badge home = **v6.5** (incógnito) y `app_core/CHANGELOG.md` → 403;
- Tickets: asignar un ticket a dos trabajadores y que **el segundo** pueda marcarlo resuelto; «Tomar» un ticket
  sin dueño lo deja «En progreso»;
- Inspección: un área común terminada aparece **una sola vez** y sigue ahí después de 5 minutos (refresco
  automático);
- Reportes: aparece la ficha de KPIs (§11.5);
- Ajustes → Usuarios: el último administrador tiene deshabilitados Desactivar y quitar el rol (anti-bloqueo de
  vuelta).

### 11.7 Release "habitación actual + piezas en progreso" → v6.7 (desplegado el 22/09/2026)

Dos cambios (detalle en la fila v6.7 del CHANGELOG):

- **Arreglo de la «habitación actual» del trabajador:** siempre es la que tiene en curso. Antes quedaba
  trabado si le rechazaban una pieza mientras limpiaba otra. No lleva SQL.
- **Permiso nuevo `asignaciones.mover_en_progreso`:** reasignar o quitar una pieza en progreso queda
  solo para Admin. La supervisora la ve con candado.

**Delta:** se calcula contra el **último deploy confirmado** en la tabla de arriba. Si la v6.6 (RUT en
los buscadores) no alcanzó a subirse, su archivo (`views/componentes/modal-cambiar-password.php`)
va en este mismo delta.

**1. Permiso, ANTES de subir el código** (idempotente). Por la Terminal de cPanel o como cron de una
sola vez (ver v2.5):

```bash
/opt/alt/php84/usr/bin/php scripts/migrate-add-permiso-mover-en-progreso.php
```

O el equivalente en phpMyAdmin, con el mismo patrón de §11.5:

```sql
INSERT INTO limpieza_permisos (codigo, descripcion, categoria, scope)
SELECT 'asignaciones.mover_en_progreso',
       'Reasignar o quitar habitaciones que están en progreso (el trabajador pierde lo avanzado)',
       'Asignaciones', 'global'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM limpieza_permisos WHERE codigo = 'asignaciones.mover_en_progreso');

INSERT INTO limpieza_rol_permisos (rol_id, permiso_codigo)
SELECT DISTINCT rp.rol_id, 'asignaciones.mover_en_progreso'
  FROM limpieza_rol_permisos rp
 WHERE rp.permiso_codigo = 'permisos.asignar_a_rol'
   AND NOT EXISTS (SELECT 1 FROM limpieza_rol_permisos x
                    WHERE x.rol_id = rp.rol_id AND x.permiso_codigo = 'asignaciones.mover_en_progreso');
```

Se concede a los roles que administran la matriz (`permisos.asignar_a_rol`), igual que el de la v6.4.
Si el código sube antes que el SQL, nadie tiene el permiso: tampoco el Admin puede mover una pieza en
progreso hasta correrlo. Falla hacia el lado seguro.

**2. Archivos**, todos a `app_core/`:

- `src/Services/AsignacionService.php`, `src/Services/EspacioService.php` y
  `src/Services/Copilot/CopilotToolExecutor.php`;
- `src/Controllers/AsignacionesController.php`, `src/Controllers/EspaciosController.php` y
  `src/Controllers/HomeController.php`;
- `views/asignaciones.php`, `views/home-supervisora.php` y `views/componentes/candado-en-progreso.php`
  (nuevo);
- `database/seeds/permisos.php` y `scripts/migrate-add-permiso-mover-en-progreso.php` (nuevo);
- `CHANGELOG.md`.

No cambia ningún asset (`sw.js`, `app.js`, `custom.css`), así que no hay que subir `CACHE_VERSION`. Sin
`.env` y sin `vendor/`.

**3. Smoke específico** (con las piezas de test, nunca con una real):

- badge home = **v6.7** (incógnito);
- **como Supervisora:** en Asignaciones, una pieza en progreso muestra el candado y el aviso, sin
  botones, y en el Tablero no se arrastra. En «Reasignar carga» del Inicio sale deshabilitada;
- **como Admin:** la misma pieza tiene Reasignar/Quitar y pide confirmación. Cancelar para no moverla;
- **como Trabajador:** con una pieza en curso, rechazar en Inspección otra que ya había terminado. El
  Inicio debe seguir mostrando la que está limpiando.
