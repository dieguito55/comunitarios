<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deshacer una verificación ya tomada.
 *
 * El motivo es obligatorio y no vale con cuatro letras: mínimo 10 caracteres.
 * Es lo único que va a quedar para explicar, dentro de seis meses, por qué una
 * donación aprobada dejó de estarlo. «error» no explica nada; «aprobada con el
 * comprobante de otra persona» sí.
 */
final class RevertirVerificacionRequest extends FormRequest
{
    /** La autorización real la hace DonacionPolicy en el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo_reversion' => ['required', 'string', 'min:10', 'max:300'],
        ];
    }

    public function motivo(): string
    {
        return trim((string) $this->input('motivo_reversion', ''));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo_reversion.required' => 'Escribe por qué se revierte. Queda registrado para siempre.',
            'motivo_reversion.string' => 'El motivo no es válido.',
            'motivo_reversion.min' => 'Explica el motivo con un poco más de detalle: mínimo 10 caracteres.',
            'motivo_reversion.max' => 'El motivo no puede superar los 300 caracteres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['motivo_reversion' => 'motivo de la reversión'];
    }
}
