<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Fondo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El fondo elegido debe existir y estar recibiendo dinero.
 *
 * Un fondo pausado o cerrado sigue siendo visible —es rendición de cuentas—
 * pero no admite aportes nuevos. Sin esta regla, alguien podría reabrir una
 * campaña terminada con una petición fabricada a mano.
 */
final class FondoAceptaDonaciones implements ValidationRule
{
    private const MENSAJE = 'Ese fondo no está recibiendo donaciones en este momento.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail(self::MENSAJE);

            return;
        }

        $fondo = Fondo::query()->find((int) $value);

        // Un fondo inexistente da el mismo mensaje que uno cerrado: este
        // endpoint es público y no tiene por qué confirmar qué ids existen.
        if ($fondo === null || ! $fondo->aceptaDonaciones()) {
            $fail(self::MENSAJE);
        }
    }
}
