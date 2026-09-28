<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProveedorPago;
use App\Rules\ComprobanteSeguro;
use Illuminate\Validation\Rule;

/**
 * Validación del canal QR: los mismos datos del donante que en tarjeta, más el
 * comprobante de la transferencia.
 *
 * Hereda de `CrearDonacionRequest` para que el donante se valide igual en los
 * dos canales. Lo único que cambia de verdad:
 *
 *   - El mínimo es `monto_minimo_qr` (10), no el de tarjeta (5). Verificar un
 *     comprobante a mano cuesta el tiempo de una persona, y por debajo de cierto
 *     importe ese tiempo vale más que el aporte.
 *   - El comprobante es obligatorio: sin él no hay nada que verificar.
 *   - El proveedor solo puede ser Yape o Plin. Aceptar `mercadopago` aquí
 *     dejaría donaciones que ningún canal sabe cerrar.
 */
final class CrearDonacionQrRequest extends CrearDonacionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reglas = parent::rules();

        // El mínimo del canal manual sustituye al de tarjeta.
        $reglas['monto'] = [
            'required',
            'numeric',
            'min:'.(float) config('donaciones.monto_minimo_qr'),
            'max:'.(float) config('donaciones.monto_maximo'),
        ];

        $reglas['proveedor_pago'] = [
            'required',
            Rule::in([ProveedorPago::YAPE_QR->value, ProveedorPago::PLIN_QR->value]),
        ];

        // El número de operación que muestra Yape. Opcional: no todo el mundo
        // lo apunta, y exigirlo perdería donaciones por un dato que además
        // podemos leer del propio comprobante.
        $reglas['referencia_pago'] = ['nullable', 'string', 'max:100'];

        $reglas['comprobante'] = [
            'required',
            'file',
            'max:'.((int) config('donaciones.comprobante.max_mb', 10) * 1024),
            new ComprobanteSeguro,
        ];

        return $reglas;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'proveedor_pago' => trim((string) $this->input('proveedor_pago', ProveedorPago::YAPE_QR->value)),
            'referencia_pago' => trim((string) $this->input('referencia_pago', '')),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'monto.min' => 'El monto mínimo para este medio es :min.',

            'proveedor_pago.required' => 'Indica si pagaste con Yape o con Plin.',
            'proveedor_pago.in' => 'Indica si pagaste con Yape o con Plin.',

            'referencia_pago.string' => 'El número de operación solo puede llevar números y letras.',
            'referencia_pago.max' => 'El número de operación no puede superar los 100 caracteres.',

            'comprobante.required' => 'Adjunta la captura o el PDF de tu transferencia.',
            'comprobante.file' => 'El comprobante no se subió correctamente.',
            'comprobante.max' => 'El comprobante no puede superar los '
                .(int) config('donaciones.comprobante.max_mb', 10).' MB.',
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'proveedor_pago' => 'medio de pago',
            'referencia_pago' => 'número de operación',
            'comprobante' => 'comprobante',
        ]);
    }

    /**
     * Los datos del donante, más lo propio del canal.
     *
     * El archivo NO viaja aquí: lo recoge el servicio con `file('comprobante')`,
     * porque guardarlo es su responsabilidad y así esta lista sigue siendo solo
     * datos que se pueden registrar.
     *
     * @return array<string, mixed>
     */
    public function datosDonacion(): array
    {
        $datos = parent::datosDonacion();

        $referencia = trim((string) $this->input('referencia_pago', ''));

        $datos['proveedor_pago'] = ProveedorPago::from((string) $this->input('proveedor_pago'));
        $datos['referencia_pago'] = $referencia !== '' ? $referencia : null;

        return $datos;
    }
}
