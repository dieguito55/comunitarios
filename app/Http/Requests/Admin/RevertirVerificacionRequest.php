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
        $this->errorBag = 'reversion_'.$this->route('donacion')?->getKey();

        $this->merge(['motivo_reversion' => self::limpiar($this->input('motivo_reversion'))]);
    }

    /**
     * Normaliza el motivo antes de validarlo y guardarlo.
     *
     * ── EL «+» DEL FINAL ────────────────────────────────────────────────────
     *
     * Se reporto un motivo guardado como «aprobada por error+». Ese `+` es la
     * forma en que `application/x-www-form-urlencoded` codifica un ESPACIO: si
     * un valor llega sin decodificar —un proxy que lo reenvia ya codificado, un
     * gestor de contraseñas que rellena el campo, una extension— un espacio
     * final se convierte en un `+` visible.
     *
     * `trim()` no lo quitaba porque un `+` no es un espacio en blanco. Aqui se
     * quita explicitamente, junto con el resto de normalizacion: se colapsan
     * las rachas de espacios y se recortan los extremos. Es lo unico que va a
     * explicar, dentro de seis meses, por que una donacion dejo de estar
     * verificada; merece llegar limpio.
     */
    private static function limpiar(mixed $valor): string
    {
        $texto = trim(strip_tags((string) $valor));

        // Rachas de espacios, tabuladores y saltos de linea -> un espacio.
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        // Y los «+» de los extremos, que solo pueden venir de una codificacion
        // sin deshacer: nadie empieza ni termina un motivo con un signo de mas.
        return trim($texto, ' +');
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
