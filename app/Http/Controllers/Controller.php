<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Controlador base del proyecto.
 *
 * Desde Laravel 11 el esqueleto ya no incluye AuthorizesRequests, así que
 * `$this->authorize()` no existe salvo que se añada aquí. Se añade porque el
 * panel de administración apoya TODO su control de acceso en Policies: sin
 * este trait, cada controlador tendría que llamar a Gate a mano y sería
 * cuestión de tiempo que alguien se olvidara en una ruta.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
