<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dbPath = getenv('DB_PATH') ?: 'database/atankalama.db';
$dbAbs  = str_starts_with($dbPath, '/') ? $dbPath : __DIR__ . '/../' . $dbPath;

if (!file_exists($dbAbs)) {
    echo "Error: base de datos no encontrada en {$dbAbs}\n";
    exit(1);
}

$db       = new PDO("sqlite:{$dbAbs}");
$password = 'Admin2025!';
$hash     = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare('UPDATE usuarios SET password_hash = ?, requiere_cambio_pwd = 1 WHERE rut = ?');
$stmt->execute([$hash, '11111111-1']);

if ($stmt->rowCount() === 0) {
    echo "No se encontró usuario con RUT 11111111-1\n";
    exit(1);
}

echo "Contraseña reseteada correctamente.\n";
echo "RUT:        11111111-1\n";
echo "Contraseña: {$password}\n";
echo "Deberás cambiarla al primer inicio de sesión.\n";
