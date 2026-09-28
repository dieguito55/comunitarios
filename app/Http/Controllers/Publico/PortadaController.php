<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Fondo;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * GET / — la portada.
 *
 * Existe solo para una cosa: que la tarjeta de «Programa destacado» enseñe lo
 * que de verdad hay en la base de datos. Antes esa tarjeta llevaba las cifras
 * escritas a mano en la plantilla (S/ 26.000 recaudados sobre una meta de
 * S/ 257.000), y una fundación no puede publicar una cifra de recaudación que
 * nadie ha recaudado.
 *
 * Si no hay ningún fondo publicado, `$fondoDestacado` viaja a null y la vista
 * oculta la tarjeta entera. El resto de la portada no depende de esto.
 */
final class PortadaController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome', [
            'fondoDestacado' => $this->fondoDestacado(),
        ]);
    }

    /**
     * El fondo marcado como predeterminado en el panel; si ese no está
     * publicado, el primero que sí lo esté.
     *
     * No se usa Fondo::predeterminado() porque aquel no filtra por visibilidad:
     * vale para preseleccionar en el formulario, pero aquí un borrador acabaría
     * publicado en la portada.
     */
    private function fondoDestacado(): ?Fondo
    {
        /*
         * Hasta ahora la portada no tocaba la base de datos y por tanto no
         * podia caerse por ella. Al conectar la tarjeta le hemos anadido esa
         * dependencia, asi que la aislamos: si la base no responde, cae la
         * tarjeta y no la pagina entera. El resto de la portada —quienes
         * somos, programas, historias, contacto— es contenido estatico y no
         * tiene por que desaparecer porque falle una consulta.
         *
         * Se registra en `error` a proposito: que la tarjeta desaparezca sin
         * que nadie se entere seria peor que el propio fallo.
         */
        try {
            return Fondo::query()->visibles()->where('es_predeterminado', true)->first()
                ?? Fondo::query()->visibles()->ordenados()->first();
        } catch (Throwable $excepcion) {
            Log::error('No se pudo leer el fondo destacado de la portada', [
                'excepcion' => $excepcion::class,
                'mensaje' => $excepcion->getMessage(),
            ]);

            return null;
        }
    }
}
