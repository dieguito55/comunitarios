<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Entrada de la reconciliación. El navegador manda lo que tenga a mano: lo que
 * guardó en localStorage antes de redirigir, y lo que Mercado Pago le haya
 * puesto en la URL de retorno (`payment_id` o `collection_id`, según el flujo).
 *
 * Basta con una de las tres llaves. Exigir las tres haría inútil la red 2 justo
 * en los casos en que hace falta.
 *
 * Aquí NO se decide nada sobre el pago: estos valores solo sirven para saber
 * qué preguntarle a Mercado Pago.
 */
final class ReconciliarDonacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'donacion_id' => $this->primerEntero(['donacion_id', 'donation_id', 'id']),
            'payment_id' => $this->primerEntero(['payment_id', 'collection_id', 'data_id']),
            'preference_id' => trim((string) $this->input('preference_id', '')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'donacion_id' => ['nullable', 'integer', 'min:1'],
            'payment_id' => ['nullable', 'integer', 'min:1'],
            'preference_id' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            if ($this->idPago() === 0 && $this->idDonacion() === 0 && $this->idPreferencia() === '') {
                $validador->errors()->add(
                    'donacion_id',
                    'Necesitamos al menos el identificador del pago, de la donación o de la preferencia.'
                );
            }
        });
    }

    public function idDonacion(): int
    {
        return (int) $this->input('donacion_id', 0);
    }

    public function idPago(): int
    {
        return (int) $this->input('payment_id', 0);
    }

    public function idPreferencia(): string
    {
        return trim((string) $this->input('preference_id', ''));
    }

    /** @param  list<string>  $claves */
    private function primerEntero(array $claves): ?int
    {
        foreach ($claves as $clave) {
            $valor = trim((string) $this->input($clave, ''));

            if ($valor !== '' && ctype_digit($valor)) {
                return (int) $valor;
            }
        }

        return null;
    }

    /** Mismo sobre de error que el resto de endpoints públicos. */
    protected function failedValidation(Validator $validador): void
    {
        /** @var list<string> $mensajes */
        $mensajes = array_values($validador->errors()->all());

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => $mensajes[0] ?? 'Datos inválidos.',
            'errores' => $mensajes,
        ], 422));
    }
}
