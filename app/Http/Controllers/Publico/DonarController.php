<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Fondo;
use App\Services\Donaciones\CalcularPendientes;
use Illuminate\View\View;

/**
 * GET /donar y GET /donar/{fondo:slug} — la página de donación.
 *
 * Los fondos se pintan desde el servidor para que la página funcione y se
 * indexe aunque el JavaScript no llegue a ejecutarse; el JS solo le añade el
 * paso a paso y la petición sin recarga.
 */
final class DonarController extends Controller
{
    public function __invoke(?Fondo $fondo = null): View
    {
        $abiertos = Fondo::query()->abiertos()->ordenados()->get();

        // Un fondo que ya no recibe no puede venir preseleccionado: el
        // formulario quedaría bloqueado sin explicar por qué.
        $preseleccionado = $fondo !== null && $fondo->aceptaDonaciones()
            ? $fondo
            : ($abiertos->count() === 1 ? $abiertos->first() : null);

        /*
         * Lo pendiente de TODOS los fondos en una sola consulta cacheada, no
         * una por tarjeta: con quince fondos serían quince viajes a la base
         * para pintar una línea de texto.
         */
        $pendientes = app(CalcularPendientes::class)->totales()['por_fondo'];

        return view('publico.donar', [
            'fondos' => $abiertos,
            'preseleccionado' => $preseleccionado,

            // Solicitado desde la URL pero cerrado: se avisa en vez de callar.
            'fondoNoDisponible' => $fondo !== null && ! $fondo->aceptaDonaciones() ? $fondo : null,

            'montosSugeridos' => (array) config('donaciones.montos_sugeridos', []),
            'montoMinimo' => (float) config('donaciones.monto_minimo_mp'),
            'montoMaximo' => (float) config('donaciones.monto_maximo'),
            'pendientesPorFondo' => $pendientes,
            'moneda' => $moneda = (string) config('mercadopago.currency', 'PEN'),

            // El simbolo, no el codigo ISO: en la pantalla se lee «S/ 50», no
            // «PEN 50». Es el mismo que usa Intl.NumberFormat en el navegador,
            // asi que el importe no cambia de forma al refrescarse.
            'simboloMoneda' => match ($moneda) {
                'PEN' => 'S/',
                'USD' => '$',
                'EUR' => '€',
                default => $moneda,
            },
        ]);
    }
}
