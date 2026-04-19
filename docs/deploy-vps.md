# Deploy a VPS — Atankalama Limpieza

Guía paso-a-paso para desplegar la aplicación en un VPS Linux (testado sobre Ubuntu 22.04 LTS; otras distros similares deberían funcionar ajustando los gestores de paquetes).

**Arquitectura objetivo:**

- **Caddy** como servidor web y reverse proxy (HTTPS automático vía Let's Encrypt)
- **PHP 8.2-FPM** ejecutando la app
- **SQLite** como base de datos (archivo en disco)
- **Cron** para `sync-cloudbeds.php` (2×/día) y `recalcular-alertas.php` (c/15 min)
- **Usuario de servicio dedicado** (`atankalama`), sin shell de admin
- **Backups diarios** de SQLite con rotación a 7 días
- **Firewall UFW** permitiendo solo SSH + HTTPS

---

## 0. Requisitos del VPS

- 1 vCPU, 2 GB RAM, 20 GB disco (mínimo razonable para MVP)
- IP pública
- DNS: registro `A` apuntando a la IP (ej. `limpieza.atankalama.cl`)
- Acceso root o sudo vía SSH con llave (no password)

---

## 1. Instalación del sistema base

Como root (o vía `sudo`):

```bash
apt update && apt upgrade -y

# Herramientas básicas
apt install -y git curl unzip sqlite3 ufw

# PHP 8.2 y extensiones requeridas
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y \
    php8.2-fpm \
    php8.2-sqlite3 \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-curl \
    php8.2-opcache

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Caddy (repo oficial)
apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update
apt install -y caddy
```

### Verificar versiones

```bash
php -v              # >= 8.2
composer --version  # >= 2.0
caddy version       # >= 2.7
sqlite3 --version   # >= 3.30
```

---

## 2. Firewall

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp           # Caddy redirige a HTTPS
ufw allow 443/tcp
ufw --force enable
ufw status verbose
```

---

## 3. Usuario de servicio

```bash
useradd --system --shell /usr/sbin/nologin --home /var/www/atankalama-limpieza --create-home atankalama
```

Agrega al grupo `www-data` para que PHP-FPM pueda leer archivos:

```bash
usermod -aG www-data atankalama
```

---

## 4. Clonar el repositorio

```bash
cd /var/www
git clone https://github.com/NicoCalama/atankalama-limpieza.git
chown -R atankalama:atankalama atankalama-limpieza
cd atankalama-limpieza
```

Instalar dependencias (sin dev):

```bash
sudo -u atankalama composer install --no-dev --optimize-autoloader --no-interaction
```

---

## 5. Variables de entorno

```bash
cp .env.production.example .env
chown atankalama:atankalama .env
chmod 600 .env

# Generar SESSION_SECRET aleatorio
openssl rand -hex 32

nano .env   # pegar el SESSION_SECRET y las API keys de Cloudbeds + Claude
```

Claves a completar (vienen de 1Password / Nicolás):

- `SESSION_SECRET` — el hex generado arriba
- `CLOUDBEDS_API_KEY_INN`, `CLOUDBEDS_PROPERTY_ID_INN`
- `CLOUDBEDS_API_KEY_PRINCIPAL`, `CLOUDBEDS_PROPERTY_ID_PRINCIPAL`
- `CLAUDE_API_KEY`
- `APP_URL` — el dominio real con `https://`

---

## 6. Inicializar la base de datos

```bash
sudo -u atankalama php scripts/init-db.php
sudo -u atankalama php scripts/seed.php
```

`seed.php` imprimirá la **contraseña temporal del admin** (`11111111-1`, Nicolás Campos). Guárdala — se cambiará en el primer login.

> ⚠️ En producción **no corras** `scripts/seed-demo-data.php`. Esa base de datos es solo para desarrollo local.

Permisos correctos sobre la BD:

```bash
chown atankalama:www-data database/atankalama.db
chmod 660 database/atankalama.db
chmod 770 database/
```

---

## 7. Configurar PHP-FPM para el usuario de servicio

Edita `/etc/php/8.2/fpm/pool.d/atankalama.conf` (crea el archivo):

```ini
[atankalama]
user = atankalama
group = atankalama
listen = /run/php/php8.2-fpm-atankalama.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500
php_admin_value[error_log] = /var/log/php-fpm/atankalama-error.log
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 128M
```

Producción: revisa `/etc/php/8.2/fpm/php.ini` y asegura:

```ini
display_errors = Off
display_startup_errors = Off
expose_php = Off
opcache.enable = 1
opcache.validate_timestamps = 0
```

Aplica:

```bash
mkdir -p /var/log/php-fpm && chown atankalama:atankalama /var/log/php-fpm
systemctl restart php8.2-fpm
systemctl enable php8.2-fpm
```

---

## 8. Configurar Caddy

```bash
cp /var/www/atankalama-limpieza/Caddyfile.example /etc/caddy/Caddyfile
nano /etc/caddy/Caddyfile
```

Ajusta dentro del Caddyfile:

- `limpieza.atankalama.cl` → tu dominio
- `/var/www/atankalama-limpieza` → ruta de instalación (debería coincidir)
- `unix//run/php/php8.2-fpm.sock` → reemplaza por `unix//run/php/php8.2-fpm-atankalama.sock`

Valida y aplica:

```bash
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
systemctl enable caddy
```

Caddy solicita el certificado Let's Encrypt automáticamente en el primer request HTTPS. Asegúrate de que los puertos 80 y 443 estén abiertos y que el DNS esté propagado.

---

## 9. Cron

Edita el crontab del usuario de servicio:

```bash
sudo -u atankalama crontab -e
```

Pega:

```cron
# Sincronización con Cloudbeds 2×/día (horas definidas en .env)
0 9 * * *  cd /var/www/atankalama-limpieza && php scripts/sync-cloudbeds.php >> /var/log/atankalama/cron-sync.log 2>&1
0 21 * * * cd /var/www/atankalama-limpieza && php scripts/sync-cloudbeds.php >> /var/log/atankalama/cron-sync.log 2>&1

# Recálculo de alertas predictivas cada 15 min
*/15 * * * * cd /var/www/atankalama-limpieza && php scripts/recalcular-alertas.php >> /var/log/atankalama/cron-alertas.log 2>&1

# Backup diario de SQLite a las 03:30
30 3 * * * /var/www/atankalama-limpieza/scripts/backup-db.sh >> /var/log/atankalama/backup.log 2>&1
```

Crea el directorio de logs:

```bash
mkdir -p /var/log/atankalama
chown atankalama:atankalama /var/log/atankalama
```

---

## 10. Logrotate

Crea `/etc/logrotate.d/atankalama`:

```
/var/log/atankalama/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 0640 atankalama atankalama
}
```

---

## 11. Verificación post-deploy

### Smoke tests

```bash
# Health check (sin auth — debe devolver 200 con ok=true)
curl -sS https://limpieza.atankalama.cl/api/health | jq

# HTTPS redirect funciona
curl -sSI http://limpieza.atankalama.cl | head -5

# Headers de seguridad presentes
curl -sSI https://limpieza.atankalama.cl | grep -iE 'strict-transport|x-frame|content-security'
```

### Manual

- [ ] Abrir `https://limpieza.atankalama.cl` en navegador
- [ ] Login con `11111111-1` + contraseña temporal impresa por `seed.php`
- [ ] Cambiar contraseña en el primer login
- [ ] Crear un usuario de prueba desde `/ajustes/usuarios`
- [ ] Verificar `/api/health` devuelve 200 y `ok: true`
- [ ] Revisar `/var/log/caddy/atankalama-access.log` muestra requests
- [ ] Esperar un cron de `recalcular-alertas.php` (15 min) y revisar `/var/log/atankalama/cron-alertas.log`

---

## Deploys subsecuentes

Para actualizar la app tras cambios en `main`:

```bash
sudo -u atankalama /var/www/atankalama-limpieza/scripts/deploy.sh
```

El script:

1. Verifica que no haya cambios locales sin commitear
2. `git pull --ff-only`
3. `composer install --no-dev --optimize-autoloader`
4. `php scripts/init-db.php` (idempotente — solo agrega tablas/columnas nuevas)
5. Recarga PHP-FPM para refrescar OPcache

Si `init-db.php` falla porque hubo un cambio destructivo en el schema, revisa manualmente y aplica las migraciones necesarias antes de reintentar.

---

## Rollback

MVP no tiene rollback automático. Para rollback manual:

```bash
cd /var/www/atankalama-limpieza
sudo -u atankalama git log --oneline -10     # encontrar el commit previo
sudo -u atankalama git reset --hard <hash-previo>
sudo -u atankalama composer install --no-dev --optimize-autoloader
sudo systemctl reload php8.2-fpm
```

Si el rollback requiere revertir datos, restaura desde `/var/backups/atankalama/`:

```bash
systemctl stop php8.2-fpm
gunzip -c /var/backups/atankalama/atankalama-YYYYMMDD-HHMMSS.db.gz > database/atankalama.db
chown atankalama:www-data database/atankalama.db
chmod 660 database/atankalama.db
systemctl start php8.2-fpm
```

---

## Hardening adicional (opcional)

- **Fail2ban** contra bruteforce en `/login` y SSH
- **Unattended-upgrades** para parches de seguridad automáticos
- **Monitoreo externo** (UptimeRobot, Betterstack) apuntando a `/api/health`
- **Backups offsite** — sincroniza `/var/backups/atankalama/` a S3/Backblaze con `rclone`
