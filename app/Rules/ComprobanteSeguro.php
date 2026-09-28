<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * El comprobante de una transferencia: una imagen de verdad, o un PDF de verdad.
 *
 * Extiende el criterio de `ImagenSegura` —de la que reutiliza la lectura del
 * tipo real— añadiendo PDF, porque los bancos peruanos entregan la constancia
 * en ese formato y obligar a fotografiarla sería absurdo.
 *
 * ── LO QUE NO CAMBIA RESPECTO A ImagenSegura ────────────────────────────────
 *
 * El tipo se lee del CONTENIDO con finfo, nunca de la extensión ni de lo que
 * declare el navegador: las dos cosas las controla quien sube el archivo, y
 * renombrar `puerta-trasera.php` a `captura.jpg` supera cualquier regla
 * `mimes:` de Laravel sin despeinarse.
 *
 * Las imágenes pasan además por `getimagesize()`: un PHP con cabecera JPEG
 * falsa supera el primer filtro pero no el segundo. El PDF se comprueba por su
 * firma `%PDF-`, que es su equivalente.
 *
 * SVG queda fuera a propósito, igual que en la fase 3B: es una imagen, pero
 * puede llevar JavaScript dentro y se ejecutaría al servirla. Un comprobante
 * bancario no necesita ser vectorial.
 */
final class ComprobanteSeguro implements ValidationRule
{
    /** Tipos reales aceptados, mapeados a la extensión que les pondremos. */
    public const TIPOS_PERMITIDOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('No se pudo subir el comprobante. Vuelve a intentarlo.');

            return;
        }

        $ruta = $value->getRealPath();

        if ($ruta === false || ! is_readable($ruta)) {
            $fail('No se pudo leer el comprobante.');

            return;
        }

        $tipoReal = ImagenSegura::tipoReal($ruta);

        if (! array_key_exists($tipoReal, $this->tiposPermitidos())) {
            $fail('El comprobante debe ser una imagen (JPG, PNG o WEBP) o un PDF.');

            return;
        }

        if ($tipoReal === 'application/pdf') {
            if (! $this->pareceUnPdf($ruta)) {
                $fail('El archivo dice ser un PDF pero está dañado.');
            }

            return;
        }

        // Segundo filtro para las imágenes: que además tengan estructura de imagen.
        if (@getimagesize($ruta) === false) {
            $fail('El archivo parece una imagen pero está dañado o no lo es.');
        }
    }

    /**
     * Los tipos aceptados, cruzados con los que permite la configuración.
     *
     * Así tesorería puede cerrar el PDF desde `config/donaciones.php` sin tocar
     * código, y nunca al revés: la configuración puede quitar tipos de esta
     * lista, jamás añadir uno que este archivo no sepa validar.
     *
     * @return array<string, string>
     */
    private function tiposPermitidos(): array
    {
        /** @var list<string> $configurados */
        $configurados = (array) config('donaciones.comprobante.mimes_permitidos', []);

        if ($configurados === []) {
            return self::TIPOS_PERMITIDOS;
        }

        return array_intersect_key(self::TIPOS_PERMITIDOS, array_flip($configurados));
    }

    /** La firma de un PDF son sus cinco primeros bytes. */
    private function pareceUnPdf(string $ruta): bool
    {
        $manejador = @fopen($ruta, 'rb');

        if ($manejador === false) {
            return false;
        }

        $cabecera = (string) fread($manejador, 5);
        fclose($manejador);

        return $cabecera === '%PDF-';
    }

    /** La extensión que corresponde a un tipo real, o null si no se acepta. */
    public static function extensionDe(string $tipoReal): ?string
    {
        return self::TIPOS_PERMITIDOS[$tipoReal] ?? null;
    }
}
