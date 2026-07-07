<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

try {
    $keys = VAPID::createVapidKeys();

    echo "=== Claves VAPID generadas ===\n\n";
    echo "VAPID_PUBLIC_KEY=" . $keys['publicKey'] . "\n";
    echo "VAPID_PRIVATE_KEY=" . $keys['privateKey'] . "\n";
    echo "\nCopia estas claves a tu archivo .env o configúralas en Easypanel.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
