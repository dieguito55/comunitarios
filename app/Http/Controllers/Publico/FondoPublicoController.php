<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Fondo;
use App\Services\Fondos\CalcularMetricasFondo;
use Illuminate\View\View;

/**
 * GET /fondos/{fondo:slug} — la página de un fondo.
 *
 * Un borrador responde 404, no 403: la existencia de un fondo que todavía no
 * se ha publicado no es información pública. Un 403 confirmaría que ese slug
 * existe.
 */
final class FondoPublicoController extends Controller
{
    public function __construct(private readonly CalcularMetricasFondo $metricas) {}

    public function __invoke(Fondo $fondo): View
    {
        abort_unless($fondo->estado->visiblePublicamente(), 404);

        return view('publico.fondo', [
            'fondo' => $fondo->load('medios'),
            'metricas' => ($this->metricas)($fondo),
            'simboloMoneda' => $fondo->simboloMoneda(),
        ]);
    }
}
