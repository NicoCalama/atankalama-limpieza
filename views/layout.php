<!DOCTYPE html>
<html lang="es" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Atankalama Limpieza') ?></title>

    <!-- Base path de la app ('' en dev, '/limpieza' en prod). Define u() para el
         JS inline de las vistas; apiFetch/apiPost/apiPut (app.js) prefijan solos. -->
    <script>
        window.BASE_PATH = <?= json_encode(\Atankalama\Limpieza\Core\Url::base()) ?>;
        window.u = function (p) { return window.BASE_PATH + p; };
    </script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                },
            },
        };
    </script>

    <!-- Google Fonts (Inter) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= u('/assets/css/custom.css') ?>">

    <!-- Colores configurables (Ajustes → Colores): variables consumidas por las
         clases semánticas .chip-estado-* / .hotel-accent-* de custom.css -->
    <style id="ui-colores">
<?= (new \Atankalama\Limpieza\Services\UiConfigService())->cssVars() . "\n" ?>
    </style>

    <!-- PWA -->
    <link rel="manifest" href="<?= u('/manifest') ?>">
    <meta name="theme-color" content="#2563eb" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1e40af" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Limpieza">
    <link rel="apple-touch-icon" href="<?= u('/assets/img/icon-192.png') ?>">

    <!-- Tema: aplicar antes de que Alpine cargue para evitar flash -->
    <script>
        (function() {
            var tema = localStorage.getItem('tema');
            if (tema === 'dark' || (!tema && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 font-sans min-h-screen">

    <?php if (isset($usuario)): ?>
        <!-- Sidebar desktop -->
        <?php include __DIR__ . '/componentes/sidebar.php'; ?>
    <?php endif; ?>

    <div id="app-content" class="<?= isset($usuario) ? 'md:ml-64' : '' ?>">
        <!-- Contenido de la página -->
        <?= $__contenido ?>
    </div>

    <?php if (isset($usuario)): ?>
        <!-- Centro de notificaciones (popup global) -->
        <?php include __DIR__ . '/componentes/notificaciones-popup.php'; ?>

        <!-- Bottom nav móvil -->
        <?php include __DIR__ . '/componentes/bottom-nav.php'; ?>

        <!-- FAB Copilot -->
        <?php // Oculto vía flag mientras el equipo aprende la app; se reactiva con COPILOT_HABILITADO=true al conectar Claude API. ?>
        <?php if (\Atankalama\Limpieza\Core\Config::getBool('COPILOT_HABILITADO', false) && $usuario->tienePermiso('copilot.usar_nivel_1_consultas')): ?>
            <?php include __DIR__ . '/componentes/fab-copilot.php'; ?>
        <?php endif; ?>

        <!-- Modal reutilizable "Nuevo ticket" (abrible vía evento abrir-modal-ticket) -->
        <?php if ($usuario->tienePermiso('tickets.crear')): ?>
            <?php include __DIR__ . '/componentes/modal-ticket-nuevo.php'; ?>
        <?php endif; ?>

        <!-- Modal reutilizable "Nuevo usuario" (abrible vía evento abrir-modal-usuario-nuevo) -->
        <?php if ($usuario->tienePermiso('usuarios.crear')): ?>
            <?php include __DIR__ . '/componentes/modal-usuario-nuevo.php'; ?>
        <?php endif; ?>

        <!-- Modal reutilizable "Detalle de usuario" (abrible vía evento abrir-modal-usuario-detalle) -->
        <?php if ($usuario->tienePermiso('usuarios.ver')): ?>
            <?php include __DIR__ . '/componentes/modal-usuario-detalle.php'; ?>
        <?php endif; ?>

        <!-- Modales RBAC (abribles vía eventos abrir-modal-rol-nuevo / abrir-modal-rol-editar) -->
        <?php if ($usuario->tienePermiso('permisos.asignar_a_rol')): ?>
            <?php include __DIR__ . '/componentes/modal-rol-nuevo.php'; ?>
            <?php include __DIR__ . '/componentes/modal-rol-editar.php'; ?>
        <?php endif; ?>

        <!-- Modal cambiar contraseña (disponible para todo usuario autenticado) -->
        <?php include __DIR__ . '/componentes/modal-cambiar-password.php'; ?>

        <!-- Modal editor de turno (crear/editar catálogo) -->
        <?php if ($usuario->tienePermiso('turnos.crear_editar')): ?>
            <?php include __DIR__ . '/componentes/modal-turno-editor.php'; ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- App JS -->
    <script src="<?= u('/assets/js/app.js') ?>"></script>
    <script>
        // Store global de notificaciones (badge count)
        document.addEventListener('alpine:init', function() {
            Alpine.store('notif', { sinLeer: 0 });
        });

        // Inicializar iconos Lucide después del render
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            <?php if (isset($usuario)): ?>
            // Cargar conteo de no leídas para el badge
            fetch(u('/api/notificaciones/sin-leer'))
                .then(function(r) { return r.json(); })
                .then(function(j) { if (j.ok) Alpine.store('notif').sinLeer = j.data.sin_leer; })
                .catch(function() {});
            <?php endif; ?>
        });
        // Re-inicializar cuando Alpine actualice el DOM
        document.addEventListener('alpine:initialized', function() {
            lucide.createIcons();
        });

        // ─── Service Worker ───────────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                // El scope del SW queda en BASE_PATH/ (raíz en dev, /limpieza/ en prod);
                // sw.js deriva su prefijo de ese scope.
                navigator.serviceWorker.register(u('/sw.js')).catch(function() {});
            });
        }

        // ─── Banner de instalación PWA ────────────────────────────────────
        var _pwaPrompt = null;

        // Mostrar/ocultar el banner togglea también 'pwa-banner-open' en <body>, que
        // reserva espacio al pie del contenido para que el banner flotante (fixed) no
        // tape los botones de acción al final de pantallas cortas (p. ej. "Habitación
        // terminada" o los veredictos de auditoría). Ver el <style> debajo del banner.
        function mostrarBannerPwa() {
            var banner = document.getElementById('pwa-install-banner');
            if (banner) banner.classList.remove('hidden');
            document.body.classList.add('pwa-banner-open');
        }

        function ocultarBannerPwa() {
            var banner = document.getElementById('pwa-install-banner');
            if (banner) banner.classList.add('hidden');
            document.body.classList.remove('pwa-banner-open');
        }

        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            _pwaPrompt = e;
            mostrarBannerPwa();
        });

        function instalarPWA() {
            if (!_pwaPrompt) return;
            _pwaPrompt.prompt();
            _pwaPrompt.userChoice.then(function() {
                _pwaPrompt = null;
                ocultarBannerPwa();
            });
        }

        window.addEventListener('appinstalled', ocultarBannerPwa);
    </script>

    <!-- Banner de instalación PWA (visible solo cuando el browser lo permite) -->
    <style>
        /* Mientras el banner de instalación PWA está visible reservamos espacio al pie
           del contenido para que no tape los botones de acción al final de la página
           (el banner es position:fixed). Solo aplica con la clase puesta por el JS. */
        body.pwa-banner-open #app-content { padding-bottom: 11rem; }
        @media (min-width: 768px) { body.pwa-banner-open #app-content { padding-bottom: 7rem; } }
    </style>
    <div id="pwa-install-banner"
         class="hidden fixed bottom-20 md:bottom-6 left-4 right-4 md:left-auto md:right-6 md:w-80 z-50
                bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                rounded-2xl shadow-xl p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center flex-shrink-0">
            <img src="<?= u('/assets/img/icon-192.png') ?>" alt="" class="w-8 h-8 rounded-lg">
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Instalar app</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">Acceso rápido desde tu pantalla de inicio</p>
        </div>
        <div class="flex gap-2">
            <button onclick="instalarPWA()"
                    class="min-h-[36px] px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                Instalar
            </button>
            <button onclick="ocultarBannerPwa()"
                    class="min-h-[36px] min-w-[36px] flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
</body>
</html>
