<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Services\Publico\ConstruirDashboard;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/dashboard — el único endpoint público de lectura.
 *
 * Alimenta la barra de progreso, las tarjetas de fondo y el feed de donantes.
 * El navegador lo pide al cargar y cada 30 segundos.
 *
 * Todo el trabajo —y sobre todo las tres reglas de privacidad— vive en
 * ConstruirDashboard. Aquí solo se envuelve en el mismo sobre que usa el resto
 * de la API pública, para que el front tenga un único contrato.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly ConstruirDashboard $construirDashboard) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(['success' => true] + ($this->construirDashboard)());
    }
}
