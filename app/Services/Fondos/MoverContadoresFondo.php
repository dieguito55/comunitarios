<?php

declare(strict_types=1);

namespace App\Services\Fondos;

use App\Enums\EstadoDonacion;
use App\Models\Donacion;
use App\Models\Fondo;
use Illuminate\Support\Facades\Log;

/**
 * Mueve `recaudado` y `donaciones_count` de un fondo según el cambio de estado
 * de una donación. Devuelve el importe movido, para dejarlo en el registro.
 *
 * ── POR QUÉ ESTO VIVE AQUÍ Y NO DENTRO DE ReconciliarDonacion ───────────────
 *
 * Hasta la fase 5 era un método privado de aquel servicio, porque solo Mercado
 * Pago movía dinero. Con el canal QR hay una segunda puerta: un administrador
 * que verifica un comprobante a mano. Copiar el método habría sido garantizar
 * que dentro de tres meses los dos difieran —alguien arregla un caso límite en
 * uno y se olvida del otro— y que las cifras públicas dejen de cuadrar sin que
 * nadie sepa por qué.
 *
 * Hay UNA implementación del movimiento de contadores en todo el proyecto, y es
 * esta. Si aparece una tercera puerta, también pasa por aquí.
 *
 * ── LAS REGLAS QUE NO SE TOCAN ──────────────────────────────────────────────
 *
 * El control es EL CAMBIO DE ESTADO, no la llegada de un aviso: por eso una
 * notificación repetida de Mercado Pago no suma dos veces, y por eso dos
 * administradores que aprueban el mismo comprobante tampoco.
 *
 * El contador puede BAJAR: un contracargo o una devolución convierten un
 * `aprobado` en `rechazado`, y es correcto que ese dinero deje de contarse.
 *
 * El importe que se mueve es `montoEfectivo()` —el real si lo hay, el
 * referencial si no—, nunca el declarado cuando ya se conoce el que entró.
 */
final class MoverContadoresFondo
{
    public function __invoke(
        Donacion $donacion,
        EstadoDonacion $anterior,
        EstadoDonacion $nuevo,
    ): float {
        $contabaAntes = $anterior === EstadoDonacion::APROBADO;
        $cuentaAhora = $nuevo === EstadoDonacion::APROBADO;

        if ($contabaAntes === $cuentaAhora || $donacion->fondo_id === null) {
            return 0.0;
        }

        // lockForUpdate: dos donaciones que se aprueban a la vez sobre el mismo
        // fondo leerían el mismo contador y una de las dos se perdería.
        $fondo = Fondo::query()->lockForUpdate()->find($donacion->fondo_id);

        if ($fondo === null) {
            Log::channel('payments')->error('La donación apunta a un fondo inexistente', [
                'donacion_id' => $donacion->id,
                'fondo_id' => $donacion->fondo_id,
            ]);

            return 0.0;
        }

        $importe = round($donacion->montoEfectivo(), 2);
        $signo = $cuentaAhora ? 1 : -1;

        $cuentaCalculada = (int) $fondo->donaciones_count + $signo;
        $recaudadoCalculado = round((float) $fondo->recaudado + ($signo * $importe), 2);

        // Nunca se guarda un negativo, pero un cálculo negativo SIGNIFICA que
        // hay un bug: se está restando una donación que nunca se sumó. Sin este
        // registro, max(0, ...) lo taparía y nadie se enteraría jamás.
        if ($cuentaCalculada < 0 || $recaudadoCalculado < 0) {
            Log::channel('payments')->error('El contador del fondo intentó bajar de cero; hay un desajuste contable', [
                'fondo_id' => $fondo->id,
                'fondo_slug' => $fondo->slug,
                'donacion_id' => $donacion->id,
                'recaudado_actual' => (float) $fondo->recaudado,
                'recaudado_calculado' => $recaudadoCalculado,
                'donaciones_count_actual' => (int) $fondo->donaciones_count,
                'donaciones_count_calculado' => $cuentaCalculada,
                'importe' => $signo * $importe,
                'revisar_con' => 'php artisan fondos:recalcular --dry-run',
            ]);
        }

        $fondo->forceFill([
            'recaudado' => max(0.0, $recaudadoCalculado),
            'donaciones_count' => max(0, $cuentaCalculada),
        ])->save();

        return $signo * $importe;
    }
}
