<?php

declare(strict_types=1);

namespace App\Services\Fondos;

use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Donaciones\CalcularPendientes;
use Illuminate\Support\Facades\Cache;

/**
 * Métricas públicas de un fondo.
 *
 * Reparto deliberado del trabajo:
 *
 *  - `recaudado` y `donaciones_count` se LEEN del contador denormalizado, que
 *    mantiene ReconciliarDonacion en la misma transacción que aprueba el pago.
 *    Sumar en cada visita a la portada no escala y pelea con la caché.
 *
 *  - Los **donantes únicos NO se denormalizan**. Contarlos de forma incremental
 *    se equivoca en cuanto alguien dona dos veces, y el error es silencioso y
 *    acumulativo: nadie se entera hasta que la cifra publicada ya lleva meses
 *    mal. Se calculan al vuelo con COUNT(DISTINCT correo) y se cachean 30 s,
 *    que es tiempo de sobra para absorber el refresco del dashboard sin que el
 *    dato deje de parecer vivo.
 *
 * Vive en un servicio y no en el modelo a propósito: es una consulta con caché,
 * no un atributo de la fila.
 */
final class CalcularMetricasFondo
{
    public function __construct(private readonly CalcularPendientes $pendientes) {}

    /** Suficiente para absorber el refresco del dashboard cada 30 s. */
    private const SEGUNDOS_DE_CACHE = 30;

    /**
     * @return array{
     *     fondo_id: int,
     *     slug: string,
     *     recaudado: float,
     *     donaciones: int,
     *     donantes_unicos: int,
     *     pendiente: float,
     *     pendientes_aportes: int,
     *     meta: float|null,
     *     porcentaje: float|null,
     *     porcentaje_pendiente: float|null,
     *     moneda: string,
     *     acepta_donaciones: bool
     * }
     */
    public function __invoke(Fondo $fondo): array
    {
        $pendiente = $this->pendientes->porFondo($fondo);

        return [
            'fondo_id' => (int) $fondo->id,
            'slug' => (string) $fondo->slug,

            // Contadores denormalizados: lectura directa, sin agregación.
            'recaudado' => (float) $fondo->recaudado,
            'donaciones' => (int) $fondo->donaciones_count,

            // Este sí se calcula, por lo explicado arriba.
            'donantes_unicos' => $this->donantesUnicos($fondo),

            // Recibido por Yape o Plin y todavía sin verificar. NO entra en
            // `recaudado`: es una cifra aparte, con su propia etiqueta.
            'pendiente' => $pendiente['monto'],
            'pendientes_aportes' => $pendiente['aportes'],

            'meta' => $fondo->meta !== null ? (float) $fondo->meta : null,
            'porcentaje' => $fondo->porcentajeDeMeta(),

            // El tramo de la barra que corresponde a lo pendiente. El
            // porcentaje que se ANUNCIA sigue siendo solo el confirmado.
            'porcentaje_pendiente' => $this->porcentajePendiente($fondo, $pendiente['monto']),
            'moneda' => (string) $fondo->moneda,
            'acepta_donaciones' => $fondo->aceptaDonaciones(),
        ];
    }

    /**
     * Qué porción de la barra ocupa lo pendiente, a continuación de lo
     * confirmado y sin pasarse del 100 %.
     *
     * Devuelve null si no hay meta —entonces no hay barra— o si no hay nada
     * pendiente: un tramo de 0 % solo añade ruido.
     */
    private function porcentajePendiente(Fondo $fondo, float $pendiente): ?float
    {
        $meta = $fondo->meta !== null ? (float) $fondo->meta : null;

        if ($meta === null || $meta <= 0 || $pendiente <= 0) {
            return null;
        }

        $confirmado = $fondo->porcentajeDeMeta() ?? 0.0;
        $margen = max(0.0, 100.0 - $confirmado);

        return round(min($margen, ($pendiente / $meta) * 100), 2);
    }

    /**
     * Personas distintas que han donado a este fondo, identificadas por correo.
     *
     * El correo es el único identificador estable que pedimos siempre: el
     * documento puede repetirse entre persona y empresa, y el nombre se escribe
     * de mil formas.
     */
    public function donantesUnicos(Fondo $fondo): int
    {
        return (int) Cache::remember(
            $this->claveDeCache($fondo),
            self::SEGUNDOS_DE_CACHE,
            static fn (): int => Donacion::query()
                ->where('fondo_id', $fondo->id)
                ->whereIn('estado', Fondo::estadosQueSuman())
                ->distinct()
                ->count('correo')
        );
    }

    /**
     * Invalida la caché de un fondo. La llama el comando de recálculo para que
     * su resultado se vea de inmediato en vez de esperar 30 segundos.
     */
    public function olvidarCache(Fondo $fondo): void
    {
        Cache::forget($this->claveDeCache($fondo));
    }

    private function claveDeCache(Fondo $fondo): string
    {
        // El estado contable entra en la clave: si cambia
        // DASHBOARD_INCLUDE_PENDING, la cifra cacheada deja de servir.
        $estados = implode('-', Fondo::estadosQueSuman());

        return "fondo:{$fondo->id}:donantes-unicos:{$estados}";
    }
}
