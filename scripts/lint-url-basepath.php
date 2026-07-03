<?php
/**
 * lint-url-basepath.php — Verifica que ninguna URL propia de la app quede
 * hardcodeada root-absolute sin pasar por el helper de base-path.
 *
 * Por qué existe: en dev BASE_PATH es '' y una URL '/home' hardcodeada funciona
 * IGUAL que u('/home') → la suite y el uso local no detectan la falta. En prod
 * (subpath /limpieza, patrón Maisterchef) esa URL apunta fuera de la app y rompe
 * navegación/API/PWA. Este linter es la red de seguridad del base-path, análogo
 * a lint-prefix-tokens.php para el prefijo de tablas.
 *
 * Reglas:
 *  - Vistas (views/**.php): href/src/action literales, bindings :href="'/...",
 *    fetch('/...') directo y window.location = '/...' deben usar u(...).
 *  - apiFetch/apiPost/apiPut NUNCA reciben u(...) (prefijan solas → doble prefijo).
 *  - public/sw.js: toda ruta propia debe construirse con BASE + '...'.
 *  - src/**.php: headers Location y paths de cookie deben pasar por Url::a()/Url::base().
 *
 * Uso:  php scripts/lint-url-basepath.php
 * Sale 0 si está limpio; 1 (y lista las faltas) si hay URLs sin base-path.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$findings = [];

/**
 * Recorre archivos por extensión bajo un directorio.
 * @return iterable<string>
 */
function archivos(string $base, array $exts): iterable
{
    if (!is_dir($base)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (in_array($file->getExtension(), $exts, true)) {
            yield $file->getPathname();
        }
    }
}

function revisar(string $path, string $root, array $reglas, array &$findings): void
{
    $rel = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        foreach ($reglas as [$pattern, $motivo, $exento]) {
            if (!preg_match($pattern, $line)) {
                continue;
            }
            if ($exento !== null && preg_match($exento, $line)) {
                continue;
            }
            $findings[] = sprintf('%s:%d  -> %s', $rel, $i + 1, $motivo);
        }
    }
}

// ── Vistas: HTML y JS inline ──────────────────────────────────────────────
// [patrón, motivo, patrón-exento|null]
$reglasVistas = [
    ['/href="\/[a-z0-9]/i', 'href literal root-absolute: usar href="<?= u(\'...\') ?>"', null],
    ['/(src|action)="\/[a-z0-9]/i', 'src/action literal root-absolute: usar u(...)', null],
    ['/:href="\'\//', 'binding Alpine con ruta literal: envolver con u(...)', null],
    ['/\bfetch\(\s*[\'"]\//', 'fetch() directo con ruta literal: usar fetch(u(...))', null],
    ['/window\.location(\.href)?\s*=\s*[\'"`]\//', 'window.location con ruta literal: usar u(...)', null],
    ['/\bapi(Fetch|Post|Put)\(\s*u\(/', 'apiFetch/apiPost/apiPut ya prefijan: quitar u(...) (prefijo doble)', null],
    ['/\bu\(\s*u\(/', 'u(u(...)): prefijo doble', null],
];

foreach (archivos($root . '/views', ['php']) as $path) {
    revisar($path, $root, $reglasVistas, $findings);
}

// ── JS estático (app.js y demás assets propios) ──────────────────────────
$reglasJs = [
    ['/\bfetch\(\s*[\'"]\/(?!\/)/', 'fetch() con ruta literal: anteponer (window.BASE_PATH || \'\')', null],
    ['/window\.location(\.href)?\s*=\s*[\'"`]\//', 'window.location con ruta literal: anteponer (window.BASE_PATH || \'\')', null],
];

foreach (archivos($root . '/public/assets/js', ['js']) as $path) {
    revisar($path, $root, $reglasJs, $findings);
}

// ── Service worker: rutas propias siempre con BASE + '...' ────────────────
$reglasSw = [
    // Cualquier ruta propia entre comillas debe convivir con BASE + en la línea.
    // Exentas: líneas que ya usan BASE + y comentarios.
    ['/[\'"]\/[a-z0-9]/i', 'ruta literal en sw.js: construir con BASE + \'...\'', '/BASE \+ [\'"]|^\s*(\/\/|\*)/'],
];

$sw = $root . '/public/sw.js';
if (is_file($sw)) {
    revisar($sw, $root, $reglasSw, $findings);
}

// ── HTML estático (offline.html) ──────────────────────────────────────────
$reglasHtml = [
    ['/(href|src|action)="\/[a-z0-9]/i', 'URL root-absolute en HTML estático: quitarla o inyectarla vía SW/JS', null],
    ['/\bfetch\(\s*[\'"]\//', 'fetch() con ruta literal en HTML estático', null],
];

foreach (archivos($root . '/public', ['html']) as $path) {
    revisar($path, $root, $reglasHtml, $findings);
}

// ── Backend: redirects y cookies ──────────────────────────────────────────
$reglasSrc = [
    ["/conHeader\(\s*'Location',\s*[\'\"]/", 'Location con URL literal: pasar por Url::a(...)', '/Url::a\(/'],
    ["/'path'\s*=>\s*'\/'/", "cookie con path '/' hardcodeado: usar Url::base() ?: '/'", null],
];

foreach (archivos($root . '/src', ['php']) as $path) {
    revisar($path, $root, $reglasSrc, $findings);
}

if (!$findings) {
    echo "OK: todas las URLs propias pasan por el helper de base-path\n";
    exit(0);
}

echo 'URLs sin base-path (' . count($findings) . "):\n";
echo implode("\n", $findings) . "\n";
exit(1);
