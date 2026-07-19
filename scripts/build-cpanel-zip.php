<?php
/**
 * build-cpanel-zip.php — Arma el ZIP de deploy para cPanel (patrón Maisterchef).
 *
 * Produce build/limpieza-cpanel.zip con esta estructura (se sube a public_html/
 * y se extrae con File Manager → queda public_html/limpieza/):
 *
 *   limpieza/
 *     index.php            ← stub (deployment/cpanel/docroot/)
 *     .htaccess            ← rewrite + deny (deployment/cpanel/docroot/)
 *     assets/  sw.js  offline.html  uploads/   ← estáticos de public/
 *     app_core/
 *       .htaccess          ← Require all denied
 *       src/ views/ scripts/ database/seeds/ docs/(2 schemas) storage/ public/
 *       vendor/            ← composer install --no-dev FRESCO (sin phpunit ni .git)
 *       composer.json composer.lock .env.production.example
 *
 * Lecciones de Maisterchef aplicadas:
 *  - El ZIP se genera con tar.exe (libarchive): separadores '/' garantizados
 *    (Compress-Archive/.NET mete '\' y la extracción en Linux crea archivos
 *    con backslash en el nombre).
 *  - Auditoría integrada del artefacto ANTES de declararlo listo: sin .env,
 *    sin *.db, sin tests/, sin .git/, y con las entradas críticas presentes.
 *
 * Uso:  php scripts/build-cpanel-zip.php   (o composer build-cpanel)
 * Requiere: Windows con tar.exe (Win10+), composer.phar en la raíz, red o
 * cache de composer para el vendor de prod.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$stage = $root . '/build/cpanel';
$appDir = $stage . '/limpieza';
$coreDir = $appDir . '/app_core';
$zipPath = $root . '/build/limpieza-cpanel.zip';

// ── Helpers ───────────────────────────────────────────────────────────────

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $path = $f->getPathname();
        if ($f->isDir()) {
            rmdir($path);
        } else {
            @chmod($path, 0666); // los pack files de git quedan read-only en Windows
            unlink($path);
        }
    }
    rmdir($dir);
}

/** Copia recursiva con lista de exclusiones por nombre de entrada. */
function rcopy(string $src, string $dst, array $excluir = []): void
{
    if (is_file($src)) {
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0777, true);
        }
        copy($src, $dst);
        return;
    }
    if (!is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $excluir, true)) {
            continue;
        }
        rcopy($src . '/' . $item, $dst . '/' . $item, $excluir);
    }
}

function fallar(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

// ── 1. Staging limpio ─────────────────────────────────────────────────────

echo "1/5 Staging en build/cpanel/ ...\n";
rrmdir($stage);
if (file_exists($zipPath)) {
    unlink($zipPath);
}
mkdir($coreDir, 0777, true);

// Docroot: stub + .htaccess + estáticos de public/ (sin el index.php de dev).
rcopy($root . '/deployment/cpanel/docroot/index.php', $appDir . '/index.php');
rcopy($root . '/deployment/cpanel/docroot/.htaccess', $appDir . '/.htaccess');
foreach (['assets', 'sw.js', 'offline.html', 'uploads'] as $publico) {
    rcopy($root . '/public/' . $publico, $appDir . '/' . $publico);
}

// app_core: código completo (lista de inclusión explícita — nada entra por accidente).
rcopy($root . '/deployment/cpanel/app_core/.htaccess', $coreDir . '/.htaccess');
rcopy($root . '/src', $coreDir . '/src');
rcopy($root . '/views', $coreDir . '/views');
rcopy($root . '/scripts', $coreDir . '/scripts');
rcopy($root . '/database/seeds', $coreDir . '/database/seeds');
rcopy($root . '/docs/database-schema.sql', $coreDir . '/docs/database-schema.sql');
rcopy($root . '/docs/database-schema.mariadb.sql', $coreDir . '/docs/database-schema.mariadb.sql');
rcopy($root . '/public', $coreDir . '/public');           // front controller real (el stub lo requiere)
rcopy($root . '/CHANGELOG.md', $coreDir . '/CHANGELOG.md');   // fuente de /ajustes/versiones y del badge de versión
rcopy($root . '/composer.json', $coreDir . '/composer.json');
rcopy($root . '/composer.lock', $coreDir . '/composer.lock');
rcopy($root . '/.env.production.example', $coreDir . '/.env.production.example');
mkdir($coreDir . '/storage/logs', 0777, true);
mkdir($coreDir . '/storage/sessions', 0777, true);
touch($coreDir . '/storage/logs/.gitkeep');
touch($coreDir . '/storage/sessions/.gitkeep');

// ── 2. Vendor de producción fresco ────────────────────────────────────────

echo "2/5 composer install --no-dev en el stage ...\n";
// --prefer-dist SIEMPRE: los archives respetan export-ignore (sin .git/ ni
// tests/ de los paquetes); un install from-source mete .git de 30+ MB y
// fixtures .env que la auditoría de abajo rechaza.
$cmd = sprintf(
    'php %s install --no-dev --optimize-autoloader --prefer-dist --no-interaction --ignore-platform-req=php --working-dir=%s 2>&1',
    escapeshellarg($root . '/composer.phar'),
    escapeshellarg($coreDir)
);
exec($cmd, $out, $rc);
if ($rc !== 0) {
    fallar("composer install falló:\n" . implode("\n", array_slice($out, -15)));
}

// Cinturón y tiradores: si algún paquete igual quedó from-source, ningún .git
// viaja al ZIP. (SELF_FIRST: el modo default LEAVES_ONLY no entrega directorios.)
$gits = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($coreDir . '/vendor', FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $f) {
    if ($f->isDir() && $f->getFilename() === '.git') {
        $gits[] = $f->getPathname();
    }
}
foreach ($gits as $g) {
    rrmdir($g);
}

// Algunos paquetes shippean sus tests en el dist (ej. brick/math): podarlos.
// Es solo bloat — el autoload de dependencias nunca apunta a tests/ — y así
// la auditoría puede mantener la regla estricta "sin /tests/ en el ZIP".
foreach (glob($coreDir . '/vendor/*/*/{tests,Tests,test}', GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $t) {
    rrmdir($t);
}

// ── 3. Chequeos previos al empaquetado ────────────────────────────────────

echo "3/5 Chequeos del stage ...\n";
foreach ([
    $appDir . '/index.php',
    $appDir . '/.htaccess',
    $appDir . '/sw.js',
    $appDir . '/assets/js/app.js',
    $coreDir . '/.htaccess',
    $coreDir . '/vendor/autoload.php',
    $coreDir . '/public/index.php',
    $coreDir . '/docs/database-schema.mariadb.sql',
    $coreDir . '/database/seeds/permisos.php',
    $coreDir . '/scripts/sync-cloudbeds.php',
] as $requerido) {
    if (!file_exists($requerido)) {
        fallar("falta en el stage: {$requerido}");
    }
}
if (is_dir($coreDir . '/vendor/phpunit')) {
    fallar('el vendor del stage incluye phpunit (¿install sin --no-dev?)');
}

// ── 4. ZIP con .NET (System.IO.Compression) vía PowerShell ────────────────
// NO usar tar.exe: bsdtar de este Windows NO escribe zip (cae a tar/pax
// disfrazado de .zip que el unzip de cPanel rechaza). NO usar Compress-Archive:
// mete separadores '\'. El .ps1 usa ZipArchive forzando '/'. (Lección 18/07/2026.)

echo "4/5 Empaquetando con .NET ZipArchive (scripts/zip-stage.ps1) ...\n";
$cmd = sprintf(
    'powershell.exe -NoProfile -ExecutionPolicy Bypass -File %s -Stage %s -Zip %s 2>&1',
    escapeshellarg($root . '/scripts/zip-stage.ps1'),
    escapeshellarg($stage),
    escapeshellarg($zipPath)
);
exec($cmd, $out2, $rc2);
if ($rc2 !== 0 || !file_exists($zipPath)) {
    fallar("empaquetado falló:\n" . implode("\n", $out2));
}

// Guard: el artefacto DEBE ser un zip real (firma local-file-header 'PK\x03\x04').
// Si bsdtar produjo un tar disfrazado de .zip, el unzip del hosting lo rechaza.
$firma = (string) file_get_contents($zipPath, false, null, 0, 4);
if ($firma !== "PK\x03\x04") {
    fallar("el artefacto no es un zip válido (firma: " . bin2hex($firma) . "). "
        . "Esperado 504b0304. Revisá el comando de tar.exe (--format=zip).");
}

// ── 5. Auditoría del artefacto ────────────────────────────────────────────

echo "5/5 Auditando el ZIP ...\n";
// Listar con .NET (mismo motor que empaqueta): tar.exe no lee de forma
// confiable los zip de System.IO.Compression (data descriptors).
exec(sprintf(
    'powershell.exe -NoProfile -ExecutionPolicy Bypass -File %s -Zip %s 2>&1',
    escapeshellarg($root . '/scripts/zip-list.ps1'),
    escapeshellarg($zipPath)
), $entradas, $rc3);
$entradas = array_values(array_filter(array_map('trim', $entradas), static fn($e) => $e !== ''));
if ($rc3 !== 0 || $entradas === []) {
    fallar("no se pudo listar el ZIP:\n" . implode("\n", $entradas));
}

// La única variante de .env permitida en el ZIP es la plantilla de ejemplo.
$permitidosExactos = [
    'limpieza/app_core/.env.production.example',
];
$prohibidos = [
    '~\.env(\.|$)~',       // .env, .env.local, .env.docker, .env.bak, ...
    '~secrets\.json$~',
    '~credentials\.json$~',
    '~\.(pem|key|p12)$~',
    '~\.db(-journal|-wal|-shm)?$~',
    '~/tests/~',
    '~/\.git(/|$)~',
    '~node_modules~',
    '~composer\.phar~',
    '~vendor/phpunit~',
    '~\\\\~',  // backslash en nombre de entrada = extracción rota en Linux
];
$criticos = [
    'limpieza/index.php',
    'limpieza/.htaccess',
    'limpieza/sw.js',
    'limpieza/offline.html',
    'limpieza/assets/js/app.js',
    'limpieza/app_core/.htaccess',
    'limpieza/app_core/vendor/autoload.php',
    'limpieza/app_core/public/index.php',
    'limpieza/app_core/docs/database-schema.mariadb.sql',
    'limpieza/app_core/.env.production.example',
];

$violaciones = [];
foreach ($entradas as $e) {
    if (in_array($e, $permitidosExactos, true)) {
        continue;
    }
    foreach ($prohibidos as $p) {
        if (preg_match($p, $e)) {
            $violaciones[] = "prohibido: {$e}";
        }
    }
}
$set = array_flip($entradas);
foreach ($criticos as $c) {
    if (!isset($set[$c])) {
        $violaciones[] = "falta entrada crítica: {$c}";
    }
}

if ($violaciones) {
    // Sin ZIP contaminado en disco: que "existe el ZIP" siempre implique "auditoría OK".
    unlink($zipPath);
    fallar("auditoría del ZIP (artefacto borrado):\n  " . implode("\n  ", array_unique($violaciones)));
}

$mb = round(filesize($zipPath) / 1048576, 1);
echo "\nOK: build/limpieza-cpanel.zip ({$mb} MB, " . count($entradas) . " entradas, separadores '/')\n";
echo "Siguiente: subir a public_html/ por FileZilla y extraer con File Manager\n";
echo "(runbook completo en docs/deploy-cpanel.md).\n";
