<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ColorFondo;
use App\Models\Fondo;
use App\Rules\ImagenSegura;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un fondo.
 *
 * Dos reglas que no son de formato sino de negocio, y que por eso viven aquí y
 * no en el navegador:
 *
 *  - **El slug de un fondo con donaciones no se puede cambiar.** Está en la URL
 *    pública del fondo, en los enlaces que la gente comparte y, muy
 *    posiblemente, en QR ya impresos. Cambiarlo los rompe todos, y quien los
 *    tenga impresos no se entera.
 *
 *  - **`fecha_fin` posterior a `fecha_inicio`.** Una campaña que termina antes
 *    de empezar deja la interfaz en un estado que nadie sabe interpretar.
 */
final class GuardarFondoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización real la hace la Policy en el controlador: aquí solo
        // se valida la forma de los datos.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nombre = trim((string) $this->input('nombre', ''));
        $slug = trim((string) $this->input('slug', ''));

        $this->merge([
            'nombre' => $nombre,
            // Se autogenera del nombre si el formulario lo dejó vacío.
            'slug' => Str::slug($slug !== '' ? $slug : $nombre),
            'resumen' => trim((string) $this->input('resumen', '')),
            'meta' => $this->metaNormalizada(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fondo = $this->fondo();

        return [
            'nombre' => ['required', 'string', 'min:3', 'max:200'],

            'slug' => [
                'required',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('fondos', 'slug')->ignore($fondo?->getKey()),
            ],

            'resumen' => ['required', 'string', 'min:10', 'max:300'],
            'descripcion' => ['nullable', 'string', 'max:20000'],

            // Vacía = sin meta pública. La barra de progreso se oculta sola.
            'meta' => ['nullable', 'numeric', 'min:1', 'max:99999999.99'],

            'moneda' => ['required', 'string', Rule::in([(string) config('mercadopago.currency', 'PEN')])],

            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after:fecha_inicio'],

            'color_token' => ['required', Rule::enum(ColorFondo::class)],
            'orden' => ['required', 'integer', 'min:0', 'max:9999'],

            'imagen_portada' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096',                       // 4 MB
                'dimensions:min_width=1200,min_height=630',
                new ImagenSegura,
            ],

            'video' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            $fondo = $this->fondo();

            if ($fondo === null) {
                return;
            }

            $slugNuevo = (string) $this->input('slug', '');

            if ($slugNuevo !== $fondo->slug && $fondo->donaciones()->exists()) {
                $validador->errors()->add(
                    'slug',
                    'Este fondo ya tiene donaciones, así que su dirección web no se puede cambiar: '
                    .'rompería los enlaces compartidos y los códigos QR ya impresos.'
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'La dirección web solo admite minúsculas, números y guiones simples (por ejemplo: fundacion-antonia).',
            'fecha_fin.after' => 'La fecha de cierre debe ser posterior a la de inicio.',
            'meta.min' => 'La meta debe ser mayor que cero. Déjala vacía si este fondo no tiene meta pública.',
        ];
    }

    /** El fondo que se está editando, o null si es un alta. */
    public function fondo(): ?Fondo
    {
        $fondo = $this->route('fondo');

        return $fondo instanceof Fondo ? $fondo : null;
    }

    /**
     * Datos listos para el modelo. La imagen NO va aquí: la guarda el servicio
     * de imágenes, que necesita el id del fondo para su carpeta.
     *
     * @return array<string, mixed>
     */
    public function datosDelFondo(): array
    {
        /** @var array<string, mixed> $validado */
        $validado = $this->validated();

        return [
            'nombre' => $validado['nombre'],
            'slug' => $validado['slug'],
            'resumen' => $validado['resumen'],
            'descripcion' => $this->textoONulo($validado['descripcion'] ?? null),
            'meta' => $validado['meta'] !== null ? round((float) $validado['meta'], 2) : null,
            'moneda' => $validado['moneda'],
            'fecha_inicio' => $this->textoONulo($validado['fecha_inicio'] ?? null),
            'fecha_fin' => $this->textoONulo($validado['fecha_fin'] ?? null),
            'color_token' => ColorFondo::from((string) $validado['color_token']),
            'orden' => (int) $validado['orden'],
            'video' => $this->textoONulo($validado['video'] ?? null),
        ];
    }

    /** Una meta vacía es null, no 0: son cosas distintas. */
    private function metaNormalizada(): ?string
    {
        $meta = trim((string) $this->input('meta', ''));

        return $meta !== '' ? $meta : null;
    }

    private function textoONulo(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }
}
