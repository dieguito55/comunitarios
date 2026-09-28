<?php

declare(strict_types=1);

namespace App\Services\Donaciones;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Publico\ConstruirDashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Dinero recibido por Yape o Plin que todavía nadie ha verificado.
 *
 * ── ESTO NO ES RECAUDADO, Y LA DISTINCIÓN NO ES UN TECNICISMO ───────────────
 *
 * `fondos.recaudado` sigue contando SOLO lo confirmado, y
 * `DASHBOARD_INCLUDE_PENDING` sigue en false. Publicar como recaudado un
 * dinero que nadie ha comprobado significa que el total BAJA en cuanto un
 * comprobante resulta falso, y un contador que baja destruye la confianza en
 * una fundación mucho más de lo que la habría construido enseñarlo antes.
 *
 * Lo que sí se hace es enseñarlo APARTE, con su propia etiqueta: «+ S/ X por
 * verificar». Así la responsable ve el dinero desde que entra el formulario, y
 * quien dona sabe que su aporte está registrado, sin que ninguna de las dos
 * cifras mienta.
 *
 * ── POR QUÉ SE CALCULA Y NO SE DENORMALIZA ──────────────────────────────────
 *
 * Son pocas filas y cambian de estado rápido: una donación pendiente dura
 * horas, no meses. Un contador denormalizado para eso tendría que mantenerse en
 * cuatro sitios —alta, aprobación, rechazo y reversión— para ahorrar una
 * consulta sobre un puñado de filas. Se calcula al vuelo y se cachea 30
 * segundos, igual que los donantes únicos.
 */
final class CalcularPendientes
{
    /** El mismo que usa el resto del dashboard. */
    private const SEGUNDOS_DE_CACHE = 30;

    /**
     * Lo pendiente de un fondo concreto.
     *
     * @return array{monto: float, aportes: int}
     */
    public function porFondo(Fondo $fondo): array
    {
        return $this->deCache('fondo:'.$fondo->getKey(), function () use ($fondo): array {
            $fila = $this->consultaBase()
                ->where('fondo_id', $fondo->getKey())
                ->selectRaw('COALESCE(SUM(monto_referencial), 0) AS monto, COUNT(*) AS aportes')
                ->first();

            return [
                'monto' => round((float) ($fila->monto ?? 0), 2),
                'aportes' => (int) ($fila->aportes ?? 0),
            ];
        });
    }

    /**
     * Lo pendiente de todos los fondos visibles, y el desglose por fondo.
     *
     * Devuelve el desglose en la misma consulta para que el panel y el
     * dashboard no hagan una por fondo: con quince fondos serían quince viajes
     * a la base para pintar una columna.
     *
     * @return array{monto: float, aportes: int, por_fondo: array<int, array{monto: float, aportes: int}>}
     */
    public function totales(): array
    {
        return $this->deCache('totales', function (): array {
            $filas = $this->consultaBase()
                ->selectRaw('fondo_id, COALESCE(SUM(monto_referencial), 0) AS monto, COUNT(*) AS aportes')
                ->groupBy('fondo_id')
                ->get();

            $porFondo = [];
            $monto = 0.0;
            $aportes = 0;

            foreach ($filas as $fila) {
                $importe = round((float) $fila->monto, 2);
                $cuenta = (int) $fila->aportes;

                $porFondo[(int) $fila->fondo_id] = ['monto' => $importe, 'aportes' => $cuenta];
                $monto += $importe;
                $aportes += $cuenta;
            }

            return ['monto' => round($monto, 2), 'aportes' => $aportes, 'por_fondo' => $porFondo];
        });
    }

    /**
     * Solo el canal manual y solo lo pendiente.
     *
     * Una donación de Mercado Pago pendiente es otra cosa: es un checkout que
     * se abandonó a medias, y enseñarla como «dinero por verificar» sería
     * contar como recibido algo que nunca llegó a pagarse.
     */
    private function consultaBase(): Builder
    {
        return Donacion::query()
            ->where('canal_pago', CanalPago::QR_MANUAL)
            ->where('estado', EstadoDonacion::PENDIENTE);
    }

    /** @param  \Closure(): array<string, mixed>  $calcular */
    private function deCache(string $sufijo, \Closure $calcular): array
    {
        /** @var array<string, mixed> $valor */
        $valor = Cache::remember('pendientes:'.$sufijo, self::SEGUNDOS_DE_CACHE, $calcular);

        return $valor;
    }

    /**
     * Borra la caché. La llaman la verificación y la reversión para que el
     * cambio se vea al instante en vez de esperar medio minuto: quien acaba de
     * aprobar un aporte espera verlo moverse.
     */
    public function olvidarCache(?Fondo $fondo = null): void
    {
        Cache::forget('pendientes:totales');

        if ($fondo !== null) {
            Cache::forget('pendientes:fondo:'.$fondo->getKey());
        }
    }

    /** Variante por identificador, para cuando no hay el modelo a mano. */
    public function olvidarCacheDeFondo(?int $fondoId): void
    {
        Cache::forget('pendientes:totales');

        if ($fondoId !== null) {
            Cache::forget('pendientes:fondo:'.$fondoId);
        }

        /*
         * Y la del dashboard público, que tiene la suya propia de 30 segundos.
         *
         * Sin esto, la tarjeta del fondo —renderizada en el servidor— enseña
         * «+ S/ 250 por verificar» mientras el panel de cifras de abajo, que
         * viene de la API cacheada, sigue diciendo cero durante medio minuto.
         * Dos cifras distintas del mismo dinero en la misma pantalla.
         */
        app(ConstruirDashboard::class)->olvidarCache();
    }
}
