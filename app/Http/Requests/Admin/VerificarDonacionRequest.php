<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Donacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La decisión sobre un comprobante: aprobar con un monto, o rechazar con un
 * motivo.
 *
 * ── EL MONTO REAL SE CONFIRMA, NO SE ACEPTA SOLO ────────────────────────────
 *
 * El formulario precarga el monto declarado porque en la mayoría de los casos
 * coincide, pero es un campo que hay que mirar: el trabajo de esta pantalla es
 * precisamente comprobar que lo declarado y lo transferido son lo mismo.
 *
 * Cuando NO coinciden se exige una confirmación explícita, con las dos cifras
 * delante. Registrar S/ 80 donde alguien declaró S/ 100 es una decisión con
 * consecuencias contables, y un campo precargado que se envía sin leer no es
 * una decisión.
 */
final class VerificarDonacionRequest extends FormRequest
{
    /** La autorización real la hace la Policy en el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cada diálogo tiene su propia bolsa de errores.
     *
     * ── POR QUÉ, Y QUÉ FALLO ARREGLA ────────────────────────────────────────
     *
     * Una administradora abrió el diálogo, pulsó «Rechazar» sin escribir el
     * motivo, el diálogo se cerró, la página recargó y el mensaje apareció
     * ARRIBA DEL TODO, fuera de su vista. Pensó que el sistema estaba roto y lo
     * intentó varias veces.
     *
     * Con la bolsa nombrada por donación, la vista sabe A QUÉ diálogo pertenece
     * cada error: puede volver a abrir ese y solo ese, y pintar el mensaje
     * junto al campo que falla. Con una bolsa común no habría forma de saberlo
     * en una pantalla con veinte filas.
     */
    protected function prepareForValidation(): void
    {
        $this->errorBag = 'verificacion_'.$this->route('donacion')?->getKey();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['aprobar', 'rechazar'])],

            'monto_real' => [
                Rule::requiredIf(fn (): bool => $this->esAprobacion()),
                'nullable',
                'numeric',
                'min:0.01',
                'max:'.(float) config('donaciones.monto_maximo'),
            ],

            'motivo' => [
                Rule::requiredIf(fn (): bool => ! $this->esAprobacion()),
                'nullable',
                'string',
                'min:4',
                'max:300',
            ],

            'confirmo_monto' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            if (! $this->esAprobacion() || $validador->errors()->isNotEmpty()) {
                return;
            }

            $donacion = $this->donacion();

            if ($donacion === null) {
                return;
            }

            $declarado = round((float) $donacion->monto_referencial, 2);

            if ($this->montoReal() === $declarado) {
                return;
            }

            if (! $this->boolean('confirmo_monto')) {
                $validador->errors()->add('confirmo_monto', sprintf(
                    'Declaró %s %s y vas a registrar %s %s. Marca la casilla para confirmar la diferencia.',
                    $donacion->moneda,
                    number_format($declarado, 2),
                    $donacion->moneda,
                    number_format($this->montoReal(), 2),
                ));
            }
        });
    }

    public function esAprobacion(): bool
    {
        return trim((string) $this->input('decision')) === 'aprobar';
    }

    public function montoReal(): float
    {
        return round((float) $this->input('monto_real', 0), 2);
    }

    public function motivo(): string
    {
        return trim((string) $this->input('motivo', ''));
    }

    private function donacion(): ?Donacion
    {
        $donacion = $this->route('donacion');

        return $donacion instanceof Donacion ? $donacion : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Indica si apruebas o rechazas.',
            'decision.in' => 'Indica si apruebas o rechazas.',

            'monto_real.required' => 'Escribe el monto que entró de verdad, según el comprobante.',
            'monto_real.numeric' => 'El monto real debe ser un número.',
            'monto_real.min' => 'El monto real tiene que ser mayor que cero.',
            'monto_real.max' => 'El monto real supera el máximo permitido (:max).',

            'motivo.required' => 'Escribe por qué se rechaza. Queda registrado.',
            'motivo.min' => 'El motivo es demasiado corto para que se entienda después.',
            'motivo.max' => 'El motivo no puede superar los 300 caracteres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'decision' => 'decisión',
            'monto_real' => 'monto real',
            'motivo' => 'motivo',
            'confirmo_monto' => 'confirmación del monto',
        ];
    }
}
