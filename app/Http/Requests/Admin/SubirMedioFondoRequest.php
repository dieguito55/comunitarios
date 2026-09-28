<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\ImagenSegura;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una imagen más para la galería de un fondo.
 *
 * Mismas defensas que la portada, salvo las dimensiones mínimas: la portada es
 * la que se comparte en redes y necesita 1200×630; una foto de galería puede
 * ser más pequeña sin que eso rompa nada.
 */
final class SubirMedioFondoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'medio' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', new ImagenSegura],
            'alt' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'medio.max' => 'La imagen no debe pesar más de 4 MB. Comprímela antes de subirla.',
        ];
    }
}
