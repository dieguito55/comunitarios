<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EstadoFondo;
use App\Http\Controllers\Controller;
use App\Models\Fondo;
use App\Services\Fondos\CalcularMetricasFondo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pantalla de inicio del panel.
 *
 * Además de las cifras, lleva un **panel de salud** que lee la vista
 * `v_fondos_conciliacion` y avisa si el contador denormalizado de algún fondo
 * no cuadra con la suma real de sus donaciones. Es el primer sitio donde
 * alguien vería un error de contabilidad, y por eso sale arriba y en rojo en
 * lugar de esperar a que alguien ejecute un comando.
 */
final class ResumenController extends Controller
{
    public function __construct(private readonly CalcularMetricasFondo $metricas) {}

    public function __invoke(): View
    {
        $fondos = Fondo::query()->ordenados()->get();

        $tarjetas = $fondos->map(fn (Fondo $fondo): array => [
            'fondo' => $fondo,
            'metricas' => ($this->metricas)($fondo),
        ]);

        return view('admin.resumen', [
            'tarjetas' => $tarjetas,
            'totalRecaudado' => (float) $fondos->sum(static fn (Fondo $f): float => (float) $f->recaudado),
            'totalDonaciones' => (int) $fondos->sum('donaciones_count'),
            'descuadres' => $this->descuadres(),
            'sinRedactar' => $this->fondosActivosSinRedactar($fondos),
        ]);
    }

    /**
     * Fondos cuyo contador no cuadra con la suma real. Si esto devuelve algo,
     * hay un bug de contabilidad y `php artisan fondos:recalcular` lo repara.
     *
     * @return Collection<int, object>
     */
    private function descuadres(): Collection
    {
        return DB::table('v_fondos_conciliacion')
            ->whereRaw('ROUND(diferencia, 2) <> 0')
            ->orderBy('slug')
            ->get();
    }

    /**
     * Fondos ya publicados cuyo resumen sigue siendo el marcador "PENDIENTE".
     * Ese texto se estaría mostrando al donante en el selector.
     *
     * @param  Collection<int, Fondo>  $fondos
     * @return Collection<int, Fondo>
     */
    private function fondosActivosSinRedactar(Collection $fondos): Collection
    {
        return $fondos->filter(
            static fn (Fondo $fondo): bool => $fondo->estado === EstadoFondo::ACTIVO
                && str_starts_with(mb_strtoupper(trim((string) $fondo->resumen)), 'PENDIENTE')
        )->values();
    }
}
