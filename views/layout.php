<!DOCTYPE html>
<html lang="es" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Atankalama Limpieza') ?></title>

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
    <link rel="stylesheet" href="/assets/css/custom.css">

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

    <div class="<?= isset($usuario) ? 'md:ml-64' : '' ?>">
        <!-- Contenido de la página -->
        <?= $__contenido ?>
    </div>

    <?php if (isset($usuario)): ?>
        <!-- Bottom nav móvil -->
        <?php include __DIR__ . '/componentes/bottom-nav.php'; ?>

        <!-- FAB Copilot -->
        <?php if ($usuario->tienePermiso('copilot.usar_nivel_1_consultas')): ?>
            <?php include __DIR__ . '/componentes/fab-copilot.php'; ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- App JS -->
    <script src="/assets/js/app.js"></script>
    <script>
        // Inicializar iconos Lucide después del render
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });
        // Re-inicializar cuando Alpine actualice el DOM
        document.addEventListener('alpine:initialized', function() {
            lucide.createIcons();
        });
    </script>
</body>
</html>
