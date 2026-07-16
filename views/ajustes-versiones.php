<?php
/**
 * /ajustes/versiones — historial de versiones de la app.
 *
 * Render de servidor: la fuente es CHANGELOG.md (ver src/Helpers/Changelog.php),
 * no hay endpoint ni estado que sincronizar. Versión a la izquierda, cambios
 * enumerados en horizontal a la derecha.
 *
 * Variables requeridas: $usuario (Models\Usuario), $versiones (list, ver Changelog)
 */

$actual = null;
foreach ($versiones as $v) {
    if ($v['publicada']) {
        $actual = $v['version'];
        break;
    }
}
?>

<div>
    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-5xl mx-auto">
            <a href="<?= u('/ajustes') ?>" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver a Ajustes">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Versiones</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <?php if ($actual !== null): ?>
                        Estás usando la v<?= htmlspecialchars($actual, ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        Historial de cambios de la app
                    <?php endif; ?>
                </p>
            </div>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <main class="max-w-5xl mx-auto p-4 pb-24 md:pb-6">
        <?php if ($versiones === []): ?>
            <!-- Estado vacío: el CHANGELOG.md no llegó al deploy o cambió de formato -->
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center">
                <i data-lucide="git-branch" class="w-10 h-10 text-gray-400 dark:text-gray-500 mx-auto mb-3"></i>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Todavía no hay historial</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No pudimos leer el historial de versiones.</p>
            </div>
        <?php else: ?>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($versiones as $v): ?>
                    <div class="grid grid-cols-[4.5rem,1fr] md:grid-cols-[9rem,1fr] gap-3 md:gap-5 p-4">
                        <!-- Izquierda: versión -->
                        <div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-sm font-semibold
                                         <?= $v['publicada']
                                             ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                             : 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' ?>">
                                v<?= htmlspecialchars($v['version'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                <?= $v['publicada'] ? htmlspecialchars((string) $v['fecha'], ENT_QUOTES, 'UTF-8') : 'Sin publicar' ?>
                            </p>
                            <?php if ($v['version'] === $actual): ?>
                                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 mt-0.5">En uso</p>
                            <?php endif; ?>
                        </div>

                        <!-- Derecha: los cambios, enumerados en horizontal.
                             El separador va DENTRO del span del ítem que lo precede: si fuera un
                             span suelto, al envolver en móvil quedaría un "·" solo en una línea. -->
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm text-gray-700 dark:text-gray-300">
                            <?php $ultimo = count($v['cambios']) - 1; ?>
                            <?php foreach ($v['cambios'] as $i => $cambio): ?>
                                <span>
                                    <?= htmlspecialchars($cambio, ENT_QUOTES, 'UTF-8') ?><?php if ($i < $ultimo): ?><span class="text-gray-300 dark:text-gray-600 ml-2" aria-hidden="true">·</span><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
