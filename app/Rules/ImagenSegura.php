<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * El archivo es una imagen DE VERDAD.
 *
 * La regla `mimes:jpg,png,webp` de Laravel mira la extensión y lo que declara
 * el navegador, y las dos cosas las controla quien sube el archivo. Renombrar
 * `puerta-trasera.php` a `foto.jpg` la supera sin despeinarse.
 *
 * Aquí el tipo se lee del CONTENIDO con finfo, y además se comprueba con
 * getimagesize() que el archivo tenga de verdad estructura de imagen: un PHP
 * con una cabecera JPEG falsa pasa el primer filtro pero no el segundo.
 *
 * SVG queda fuera a propósito aunque sea una imagen: puede llevar JavaScript
 * dentro y se ejecuta al servirlo.
 */
final class ImagenSegura implements ValidationRule
{
    /** Tipos reales aceptados, mapeados a la extensión que les pondremos. */
    public const TIPOS_PERMITIDOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('No se pudo subir :attribute. Vuelve a intentarlo.');

            return;
        }

        $ruta = $value->getRealPath();

        if ($ruta === false || ! is_readable($ruta)) {
            $fail('No se pudo leer :attribute.');

            return;
        }

        $tipoReal = $this->tipoReal($ruta);

        if (! array_key_exists($tipoReal, self::TIPOS_PERMITIDOS)) {
            $fail('El archivo no es una imagen válida. Usa JPG, PNG o WEBP.');

            return;
        }

        // Segundo filtro: que además tenga estructura de imagen.
        if (@getimagesize($ruta) === false) {
            $fail('El archivo parece una imagen pero está dañado o no lo es.');
        }
    }

    /** El tipo leído del contenido, nunca de la extensión. */
    public static function tipoReal(string $ruta): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        $tipo = finfo_file($finfo, $ruta);
        finfo_close($finfo);

        return is_string($tipo) ? mb_strtolower($tipo) : '';
    }

    /** La extensión que corresponde a un tipo real, o null si no se acepta. */
    public static function extensionDe(string $tipoReal): ?string
    {
        return self::TIPOS_PERMITIDOS[$tipoReal] ?? null;
    }
}
