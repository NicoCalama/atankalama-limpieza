# Colaboración por git

Este proyecto se trabaja entre dos personas sobre el mismo repositorio:
**https://github.com/NicoCalama/atankalama-limpieza**

El flujo acordado es **directo a `main` + pull**: los dos comiteamos a `main` y
hacemos `pull` seguido para quedar al día. Simple y suficiente para dos personas,
siempre que coordinemos y sigamos estas reglas.

> **Por qué existe este documento:** hasta agosto de 2026 uno de los dos
> desplegaba a producción editando archivos y subiéndolos por FTP, **sin pasar
> por git**. El repo quedó atrás y hubo que reconstruir a mano el trabajo de tres
> versiones (v5, v5.1, v5.2). Para que eso no se repita, existe la **regla de oro**
> de más abajo.

---

## 🔑 Regla de oro

**Todo cambio pasa primero por git. Se commitea y se pushea ANTES de desplegar.**

El deploy a producción sigue siendo aparte (FTP delta, ver `docs/deploy-cpanel.md`),
pero nunca se despliega algo que no esté antes en `main`. Así el repositorio es
siempre la fuente de verdad y nadie tiene que adivinar qué hay en producción.

---

## Puesta a punto (una sola vez)

```bash
git clone https://github.com/NicoCalama/atankalama-limpieza.git
cd atankalama-limpieza
php composer.phar install    # instala dependencias en vendor/
```

Requisitos del entorno de desarrollo:

- **PHP 8.4** (el proyecto lo exige en `composer.json`). Con una versión menor,
  `vendor/composer/platform_check.php` bloquea la ejecución de los tests.
- Composer (se incluye `composer.phar` en el repo).

Servidor local de desarrollo:

```bash
php -S localhost:8000 -t public/ public/index.php
```

---

## Ciclo de trabajo diario

1. **Antes de empezar**, traé lo último:
   ```bash
   git pull --rebase
   ```
2. Trabajá y andá commiteando en pasos chicos (mensajes en español, ver la
   convención en `CLAUDE.md`).
3. **Antes de pushear**, sincronizá y verificá:
   ```bash
   git pull --rebase        # traé lo que subió la otra persona y resolvé conflictos
   php vendor/bin/phpunit    # la suite tiene que quedar verde
   git push
   ```

`--rebase` mantiene la historia lineal (evita los merge-commits de "Merge branch
main" que ensucian el log cuando dos personas empujan seguido).

---

## Reglas de convivencia (main compartido)

- **Commits chicos y frecuentes.** Cuanto más grande el commit, más difícil el
  conflicto.
- **Coordinen** cuando vayan a tocar el mismo archivo a la vez. Un aviso corto
  ("estoy en `TicketService.php`") ahorra conflictos.
- **No pushees con la suite en rojo.** Si un test falla, se arregla o se avisa,
  no se sube roto.
- **Pusheá seguido.** Un commit local que no se pushea es trabajo que la otra
  persona no puede ver ni construir encima.

## Qué NO se commitea (ya está en `.gitignore`)

- `.env` — tiene secretos (Cloudbeds, Claude, SMTP, BD). **Nunca** al repo.
  Cada quien tiene el suyo local a partir de `.env.example`.
- `database/*.db`, `storage/logs/*`, `public/uploads/*` — datos, no código.
- `vendor/` — se regenera con `composer install`.

`gitleaks` corre como hook de pre-commit y bloquea si detecta un secreto. Si te
frena, **no uses `--no-verify`**: revisá qué lo activó.

---

## Si el repo y producción se vuelven a desfasar

Pasa si alguien despliega sin commitear (romper la regla de oro). Para
reconstruir: se descarga el docroot de producción y se importan al repo **solo los
archivos de código fuente** (`src/`, `views/`, assets servidos, `scripts/`,
`seeds/`, schemas SQL, `composer.*`, `CHANGELOG.md`), **excluyendo** `.env`,
`*.db`, `vendor/`, las fotos subidas y los artefactos de deploy del docroot. Es
un trabajo manual y propenso a errores: mejor no llegar ahí — ver la regla de oro.
