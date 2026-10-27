# PHP2Shell (octubre 2026): exposición y revisión

**Estado al 27/10/2026:** producción **vulnerable** (responde `X-Powered-By: PHP/8.4.26`). El hosting avisó que
pasa a PHP 8.5 antes del viernes 30/10. El código queda listo para 8.5 en la **v7.12** (runbook §11.41 de
`docs/deploy-cpanel.md`).

## Qué pasó

- **CVE-2026-109618** y **CVE-2026-109619** («PHP2Shell»): ejecución remota de código **sin login**, CVSS 9.2.
  La primera corrompe memoria cuando PHP se queda sin memoria armando un request (formularios multipart, como
  las fotos); la segunda usa estado que queda vivo de un request al siguiente en el mismo proceso, y además puede
  **filtrar memoria de otros requests** (claves, tokens, contraseñas) sin dejar rastro.
- Corregidas el **21/10/2026** en **8.4.27**, **8.5.12**, 8.3.36 y 8.2.35. No hay configuración que las tape del
  todo: solo actualizar el PHP del servidor. **Nuestro código no las arregla ni las causa.**
- Afecta a todo PHP que atiende la web, LiteSpeed incluido (nuestro hosting: CloudLinux + LiteSpeed, `lsphp`).
- Intentos de ataque observados desde el 21/10 y uno fechado el **17/10** (antes del anuncio). CISA las agregó a
  su catálogo de vulnerabilidades explotadas el 26/10.
- Aparte, **CVE-2026-115560** (23/10): sin parche todavía, solo con configuraciones no estándar; su mitigación es
  la misma `session.upload_progress.enabled=0`.

Fuentes: [CISA](https://www.cisa.gov/news-events/alerts/2026/10/21/critical-vulnerabilities-php-require-immediate-patching),
[CERT-EU](https://cert.europa.eu/publications/security-advisories/2026-018/),
[Rapid7](https://www.rapid7.com/blog/post/etr-cve-2026-109618-and-cve-2026-109619-critical-unauthenticated-remote-code-execution-in-php/),
[watchTowr](https://watchtowr.com/intelligence/php-cve-2026-109618-faq/),
[PHP ChangeLog 8](https://www.php.net/ChangeLog-8.php).

## 1. Mientras el hosting no actualice

**Ticket al hosting** (texto listo para pegar):

> Asunto: Urgente — PHP 8.4.26 vulnerable a PHP2Shell (CVE-2026-109618 / CVE-2026-109619), cuenta cat6852
>
> Hola. Nuestra cuenta cat6852 (atankalama.com) responde con PHP 8.4.26 (cabecera `X-Powered-By`, 27/10/2026).
> PHP publicó el 21/10 las versiones 8.4.27 y 8.5.12, que corrigen CVE-2026-109618 y CVE-2026-109619 (ejecución
> remota de código sin autenticación, CVSS 9.2, con explotación activa; CISA las agregó al catálogo KEV el 26/10).
> Nos informaron que pasan a PHP 8.5 antes del viernes. Mientras tanto les pedimos:
> 1. Actualizar alt-php84 a 8.4.27 y reiniciar los procesos `lsphp` de la cuenta, o adelantar el cambio a 8.5.12.
> 2. Si no se puede hoy, desactivar `session.upload_progress.enabled` a nivel de servidor (no por `.user.ini`),
>    que es la mitigación parcial que recomienda PHP, y reiniciar los procesos.
> 3. Confirmar la versión exacta de 8.5 que va a quedar (necesitamos 8.5.12 o superior), con qué extensiones
>    (usamos curl, exif, gd, mbstring, openssl, pdo_mysql) y si `/opt/alt/php84/usr/bin/php` seguirá disponible
>    para nuestros cron después del cambio.
> 4. Si sus registros (WAF, ModSecurity, Imunify) muestran intentos contra la cuenta desde el 17/10, les
>    agradeceríamos el detalle.
>
> Gracias.

Si en cPanel → **Select PHP Version → Options** aparece `session.upload_progress.enabled`, ponerlo en **Off**: la
app no usa esa función, así que no se nota. Un `.user.ini` o un `ini_set()` **no sirven** (se aplican tarde).

## 2. Revisar si alguien entró

Nada de esto prueba que **no** entraron (el ataque ocurre antes de que corra nuestro código y puede no dejar
rastro), pero encuentra lo burdo: archivos plantados, usuarios o permisos nuevos, crons ajenos.

### 2.1 Servidor (script de solo lectura)

`deployment/cpanel/revisar-php2shell.sh`: subirlo a `$HOME/cron/` (LF, como los wrappers) y correrlo una vez por
la Terminal de cPanel (`sh $HOME/cron/revisar-php2shell.sh`) o como cron de una sola vez. Deja
`$HOME/logs/revision-php2shell.txt` (informe) y `$HOME/logs/revision-php2shell-huellas.txt` (huella de cada
archivo de la app, para compararla con git). Bajar los dos y borrar el script.

Cómo leer el informe:

| Sección | Qué es normal | Qué mirar |
|---|---|---|
| 1. Versiones | 8.4.27+ / 8.5.12+ una vez parchado | 8.4.26 o menos = sigue vulnerable |
| 3. Archivos modificados desde el 17/10 | Los de nuestros deploys del 18/10 (v7.10 y v7.10.1) y del 25/10 (v7.11), lista abajo | Cualquier otro, sobre todo fuera de `limpieza/` o con nombre raro |
| 4. No-fotos en `uploads` | Vacío | Cualquier `.php`, `.phtml` u otro ejecutable |
| 5. Código de webshell | Vacío (otro sitio de la cuenta puede dar falsos positivos) | Archivos de `limpieza/` |
| 6. Crons | Los 4 wrappers, el cierre de día, el correo de las 23:50 y los de otras apps conocidas | Cualquier entrada que nadie reconozca |
| 7. Errores de PHP | Pocos y conocidos | Muchos «Allowed memory size» o caídas en horas raras |

Archivos de la app que nuestros deploys tocaron desde el 17/10 (en `limpieza/app_core/`): `CHANGELOG.md`,
`database/seeds/{listas_opciones,permisos,roles}.php`, `docs/database-schema{,.mariadb}.sql`,
`scripts/migrate-add-{listas-opciones,sin-inspeccion}.php`,
`src/Controllers/{Auditoria,Espacios,Reportes}Controller.php`, `src/Models/Auditoria.php`,
`src/Services/{Auditoria,Checklist,Email,Espacio,Home,ListasOpciones,Reportes}Service.php`,
`src/Support/Tours.php`, `views/{auditoria-detalle,espacios,habitacion-detalle,home-admin,reportes}.php`,
`views/recursos/ajustes/listas-opciones.js`.

Las **huellas** se comparan en el PC de dev contra `main` (cada archivo de `app_core/` contra su versión en git,
quitando `\r`): un archivo distinto o que no existe en git es la señal más fuerte de todas.

### 2.2 Base de datos (phpMyAdmin, solo `SELECT`)

```sql
-- A. Usuarios creados o modificados desde el 17/10 (normal: cambios de clave o datos hechos por ustedes)
SELECT id, rut, nombre, activo, created_at, updated_at, last_login_at
  FROM cat6852_australia.limpieza_usuarios
 WHERE created_at >= '2026-10-17' OR updated_at >= '2026-10-17'
 ORDER BY updated_at DESC;

-- B. Roles y permisos agregados desde el 17/10
--    (esperado: 3 filas de auditoria.sin_inspeccion del 25/10, para Admin, Recepción y Supervisora)
SELECT 'rol de usuario' AS que, u.nombre AS quien, r.nombre AS detalle, ur.created_at
  FROM cat6852_australia.limpieza_usuarios_roles ur
  JOIN cat6852_australia.limpieza_usuarios u ON u.id = ur.usuario_id
  JOIN cat6852_australia.limpieza_roles r ON r.id = ur.rol_id
 WHERE ur.created_at >= '2026-10-17'
UNION ALL
SELECT 'permiso de rol', r.nombre, rp.permiso_codigo, rp.created_at
  FROM cat6852_australia.limpieza_rol_permisos rp
  JOIN cat6852_australia.limpieza_roles r ON r.id = rp.rol_id
 WHERE rp.created_at >= '2026-10-17'
 ORDER BY created_at;

-- C. Desde qué IP entró cada persona desde el 17/10 (mirar IPs que no son de los hoteles ni de los
--    celulares de siempre, sobre todo en cuentas Admin; las sesiones viejas se borran solas)
SELECT s.ip, u.nombre, COUNT(*) AS sesiones, MIN(s.created_at) AS primera, MAX(s.created_at) AS ultima
  FROM cat6852_australia.limpieza_sesiones s
  JOIN cat6852_australia.limpieza_usuarios u ON u.id = s.usuario_id
 WHERE s.created_at >= '2026-10-17'
 GROUP BY s.ip, u.nombre
 ORDER BY sesiones, ultima DESC;

-- D. Acciones sensibles registradas desde el 17/10
SELECT a.created_at, u.nombre, a.accion, a.entidad, a.entidad_id, a.origen, a.ip
  FROM cat6852_australia.limpieza_audit_log a
  LEFT JOIN cat6852_australia.limpieza_usuarios u ON u.id = a.usuario_id
 WHERE a.created_at >= '2026-10-17'
   AND (a.accion LIKE 'usuario.%' OR a.accion LIKE 'rol%' OR a.accion LIKE 'permiso%'
        OR a.accion IN ('auth.cambiar_password', 'auth.recuperar_password'))
 ORDER BY a.created_at;
```

Las fechas de la BD están en UTC (Chile = UTC−3). Ojo: alguien con control del servidor puede escribir en la BD
sin pasar por la app, y eso no queda en `audit_log`.

## 3. Después del parche: cambiar las claves

La CVE-2026-109619 puede leer memoria de otros requests sin dejar rastro, y la app carga el `.env` en cada request.
Cambiar las claves **antes** del parche no sirve (se pueden volver a leer). Una vez confirmada la versión parchada:

| Clave | Dónde se cambia | Efecto |
|---|---|---|
| `CLOUDBEDS_API_KEY_INN`, `CLOUDBEDS_API_KEY_PRINCIPAL` | Cloudbeds (nueva clave, revocar la vieja) + `.env` | Ninguno si se hace en orden |
| `SMTP_PASS` | Proveedor de correo + `.env` | Ninguno |
| `NOVEDADES_SYNC_TOKEN` | En las dos apps a la vez (limpieza y Novedades) | Tickets a Novedades fallan mientras no coincidan |
| `CLAUDE_API_KEY` (si tiene valor) | Consola de Anthropic + `.env` | Ninguno (copilot apagado) |
| Clave de la BD (`cat6852_zurk`) | cPanel → MySQL + `app_core/.env` + `.my.cnf` + Maisterchef | **Se cae la app** hasta que el `.env` tome la clave nueva (1–2 min) |
| Sesiones abiertas (el token viaja en cada request) | phpMyAdmin: `DELETE FROM cat6852_australia.limpieza_sesiones;` | Todos vuelven a entrar con RUT y clave: hacerlo fuera del turno |
| `VAPID_PRIVATE_KEY` | No cambiarla salvo evidencia | Todos tendrían que volver a activar las notificaciones |

Decisión pendiente (Nicolás / jefatura): cambiarlas todas por precaución o solo si la revisión encuentra algo.
Las contraseñas de las personas solo se resetean si hay evidencia de que alguien entró.

## 4. Resultados

_(completar al correr la revisión)_
