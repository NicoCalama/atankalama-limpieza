<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Models;

final class Usuario
{
    /**
     * @param string[] $permisos  Códigos de permisos efectivos (unión de todos los roles)
     * @param string[] $roles     Nombres de los roles asignados
     */
    public function __construct(
        public readonly int $id,
        public readonly string $rut,
        public readonly string $nombre,
        public readonly ?string $email,
        public readonly bool $activo,
        public readonly bool $requiereCambioPwd,
        public readonly ?string $hotelDefault,
        public readonly string $temaPreferido,
        public readonly array $permisos,
        public readonly array $roles,
    ) {
    }

    public function tienePermiso(string $codigo): bool
    {
        return in_array($codigo, $this->permisos, true);
    }

    /**
     * @param string[] $codigos
     */
    public function tieneAlgunPermiso(array $codigos): bool
    {
        foreach ($codigos as $codigo) {
            if ($this->tienePermiso($codigo)) {
                return true;
            }
        }
        return false;
    }

    public function tieneRol(string $nombre): bool
    {
        return in_array($nombre, $this->roles, true);
    }

    public function toArrayPublico(): array
    {
        return [
            'id' => $this->id,
            'rut' => $this->rut,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'activo' => $this->activo,
            'requiere_cambio_pwd' => $this->requiereCambioPwd,
            'hotel_default' => $this->hotelDefault,
            'tema_preferido' => $this->temaPreferido,
            'roles' => $this->roles,
        ];
    }
}
