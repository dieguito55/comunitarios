<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoFondo;
use App\Models\AdminUser;
use App\Models\Fondo;

/**
 * Quién puede hacer qué con un fondo.
 *
 * La división no es caprichosa: **publicar un fondo y decidir dónde va el
 * dinero es una decisión de la organización, no de quien redacta**. Por eso un
 * editor puede mejorar los textos y las imágenes de una campaña existente, pero
 * no puede abrir una nueva, cerrarla, publicarla ni elegir cuál sale marcada
 * por defecto en el formulario de donación.
 */
final class FondoPolicy
{
    public function verCualquiera(AdminUser $admin): bool
    {
        return true;
    }

    public function ver(AdminUser $admin, Fondo $fondo): bool
    {
        return true;
    }

    /** Abrir una campaña nueva es decidir que se va a pedir dinero para algo. */
    public function crear(AdminUser $admin): bool
    {
        return $admin->esSuperadmin();
    }

    /** Textos e imágenes: es justo el trabajo del editor. */
    public function actualizar(AdminUser $admin, Fondo $fondo): bool
    {
        return true;
    }

    /**
     * Borrar solo tiene sentido con un fondo que nunca recibió nada. Un fondo
     * con historial se cierra, no se borra: su rastro es rendición de cuentas.
     */
    public function eliminar(AdminUser $admin, Fondo $fondo): bool
    {
        return $admin->esSuperadmin()
            && $fondo->estado === EstadoFondo::BORRADOR
            && ! $fondo->donaciones()->exists();
    }

    /** Pasar a `activo` es el momento en que empieza a entrar dinero real. */
    public function cambiarEstado(AdminUser $admin, Fondo $fondo): bool
    {
        return $admin->esSuperadmin();
    }

    public function marcarPredeterminado(AdminUser $admin, Fondo $fondo): bool
    {
        return $admin->esSuperadmin();
    }

    /** La galería es contenido: el editor la gestiona. */
    public function gestionarMedios(AdminUser $admin, Fondo $fondo): bool
    {
        return true;
    }
}
