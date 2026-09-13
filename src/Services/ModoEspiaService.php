<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Usuario;

/**
 * Modo espía: un admin ve la app exactamente como la vería otro usuario
 * (mismo menú, mismos permisos, mismos datos), en solo lectura. Nunca crea
 * una sesión aparte: reutiliza la sesión activa del admin marcándola con el
 * id del usuario objetivo. AuthCheck es quien, en cada request, sustituye
 * $request->usuario por el objetivo y bloquea cualquier mutación mientras
 * el modo espía está activo.
 */
final class ModoEspiaService
{
    public function __construct(
        private readonly UsuarioService $usuarios = new UsuarioService(),
    ) {
    }

    public function activar(Usuario $admin, string $tokenSesionAdmin, int $objetivoId, ?string $ip = null): void
    {
        if ($objetivoId === $admin->id) {
            throw new UsuarioException('ESPIA_OBJETIVO_INVALIDO', 'No puedes activar el modo espía sobre ti mismo.', 400);
        }

        $objetivo = $this->usuarios->buscarPorId($objetivoId);
        if ($objetivo === null) {
            throw new UsuarioException('USUARIO_NO_ENCONTRADO', 'Usuario no encontrado.', 404);
        }
        if (!$objetivo->activo) {
            throw new UsuarioException('USUARIO_INACTIVO', 'No puedes ver la app como un usuario inactivo.', 400);
        }
        // No se puede espiar a otro admin (o a cualquiera que también pueda espiar):
        // esta feature es para ver cómo ve la app un perfil con menos privilegios,
        // no para vigilar a otro administrador.
        if ($objetivo->tienePermiso('usuarios.modo_espia')) {
            throw new UsuarioException(
                'ESPIA_OBJETIVO_INVALIDO',
                'No puedes activar el modo espía sobre otro administrador.',
                400
            );
        }

        Database::execute(
            'UPDATE #__sesiones SET espia_objetivo_id = ? WHERE token = ?',
            [$objetivoId, $tokenSesionAdmin]
        );

        Logger::audit($admin->id, 'usuario.modo_espia_iniciado', 'usuario', $objetivoId, [], 'ui', $ip);
    }

    public function desactivar(string $tokenSesion, int $adminId, ?int $objetivoId = null, ?string $ip = null): void
    {
        Database::execute('UPDATE #__sesiones SET espia_objetivo_id = NULL WHERE token = ?', [$tokenSesion]);
        Logger::audit($adminId, 'usuario.modo_espia_finalizado', 'usuario', $objetivoId, [], 'ui', $ip);
    }

    /**
     * Si la sesión del token tiene un modo espía activo, retorna el admin real
     * (ya hidratado por el caller, se lo pasamos para no repetir la query) y el
     * usuario objetivo hidratado. Si el objetivo ya no existe o quedó inactivo,
     * apaga el modo espía por su cuenta (no deja al admin "atrapado" en una
     * vista rota) y retorna null.
     *
     * @return array{admin: Usuario, objetivo: Usuario}|null
     */
    public function resolverParaSesion(string $token, Usuario $usuarioDeLaSesion): ?array
    {
        $fila = Database::fetchOne('SELECT espia_objetivo_id FROM #__sesiones WHERE token = ?', [$token]);
        $objetivoId = $fila['espia_objetivo_id'] ?? null;
        if ($objetivoId === null) {
            return null;
        }

        $objetivo = $this->usuarios->buscarPorId((int) $objetivoId);
        if ($objetivo === null || !$objetivo->activo) {
            Database::execute('UPDATE #__sesiones SET espia_objetivo_id = NULL WHERE token = ?', [$token]);
            return null;
        }

        return ['admin' => $usuarioDeLaSesion, 'objetivo' => $objetivo];
    }
}
