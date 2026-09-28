<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\EstadoFondo;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado de un fondo.
 *
 * Pasar a `activo` es el momento exacto en que un fondo empieza a recibir
 * dinero real, así que exige una confirmación consciente además del clic: el
 * formulario manda el nombre del fondo escrito a mano y aquí se comprueba.
 * Es el equivalente a la confirmación de tesorería de la guía (§12.7).
 *
 * Y no se puede activar un fondo cuyo resumen siga siendo el marcador
 * "PENDIENTE": ese texto se publicaría tal cual en el selector de donación.
 */
final class CambiarEstadoFondoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(EstadoFondo::class)],
            'confirmacion' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            if ($this->estado() !== EstadoFondo::ACTIVO) {
                return;
            }

            $fondo = $this->route('fondo');

            if ($fondo === null) {
                return;
            }

            if (str_starts_with(mb_strtoupper(trim((string) $fondo->resumen)), 'PENDIENTE')) {
                $validador->errors()->add(
                    'estado',
                    'No se puede publicar un fondo cuyo resumen sigue siendo el marcador «PENDIENTE». '
                    .'Redacta primero el resumen: es el texto que verá el donante al elegir a dónde aporta.'
                );
            }

            // Confirmación consciente: hay que escribir el nombre del fondo.
            if (mb_strtolower(trim((string) $this->input('confirmacion'))) !== mb_strtolower(trim((string) $fondo->nombre))) {
                $validador->errors()->add(
                    'confirmacion',
                    'Para publicar el fondo escribe su nombre exacto: «'.$fondo->nombre.'». '
                    .'A partir de ese momento empieza a recibir dinero real.'
                );
            }
        });
    }

    public function estado(): EstadoFondo
    {
        return EstadoFondo::from((string) $this->input('estado', EstadoFondo::BORRADOR->value));
    }
}
