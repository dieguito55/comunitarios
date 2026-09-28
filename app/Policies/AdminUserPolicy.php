<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;

/**
 * Gestión de administradores: solo el superadmin.
 *
 * Con dos salvaguardas contra quedarse sin dueño del sistema: nadie puede
 * borrarse a sí mismo ni quitarse su propio rol de superadmin. Si fuera
 * posible, bastaría un despiste para dejar el panel sin nadie capaz de
 * publicar un fondo ni de crear otro administrador.
 */
final class AdminUserPolicy
{
    public function verCualquiera(AdminUser $admin): bool
    {
        return $admin->esSuperadmin();
    }

    public function crear(AdminUser $admin): bool
    {
        return $admin->esSuperadmin();
    }

    public function actualizar(AdminUser $admin, AdminUser $objetivo): bool
    {
        return $admin->esSuperadmin();
    }

    /** Nadie se degrada a sí mismo. */
    public function cambiarRol(AdminUser $admin, AdminUser $objetivo): bool
    {
        return $admin->esSuperadmin() && ! $admin->is($objetivo);
    }

    /** Nadie se borra a sí mismo. */
    public function eliminar(AdminUser $admin, AdminUser $objetivo): bool
    {
        return $admin->esSuperadmin() && ! $admin->is($objetivo);
    }
}
