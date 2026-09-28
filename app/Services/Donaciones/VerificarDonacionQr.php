<?php

declare(strict_types=1);

namespace App\Services\Donaciones;

use App\Enums\EstadoDonacion;
use App\Models\AdminUser;
use App\Models\Donacion;
use App\Services\Fondos\MoverContadoresFondo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La decisión humana sobre un comprobante: aprobar o rechazar.
 *
 * ── ES EL ÚNICO SITIO DONDE UNA PERSONA MUEVE DINERO PÚBLICO ────────────────
 *
 * Todo ocurre dentro de UNA transacción con `lockForUpdate()` sobre la fila de
 * la donación. Dos administradores que abren el mismo comprobante a la vez es
 * un escenario real —la cola es compartida—, y sin el bloqueo los dos leerían
 * «pendiente», los dos aprobarían, y el contador del fondo subiría dos veces.
 *
 * El segundo en llegar encuentra la donación ya verificada y recibe un aviso
 * con quién la revisó y cuándo. Nunca se pisa en silencio el trabajo del otro.
 *
 * ── monto_referencial NO SE TOCA ────────────────────────────────────────────
 *
 * Al aprobar se escribe `monto_real`, que es lo que el administrador leyó en la
 * captura. Lo que el donante declaró se queda donde estaba, para siempre. Esas
 * dos cifras juntas son la rendición de cuentas: sin ellas nadie podría
 * explicar después por qué un aporte de «S/ 100» sumó S/ 80.
 *
 * Los contadores se mueven con `MoverContadoresFondo`, el mismo servicio que
 * usa la reconciliación de Mercado Pago. Hay una sola implementación.
 */
final class VerificarDonacionQr
{
    public function __construct(private readonly MoverContadoresFondo $moverContadores) {}

    /** Aprueba la donación registrando el monto que entró de verdad. */
    public function aprobar(Donacion $donacion, AdminUser $admin, float $montoReal): ResultadoVerificacion
    {
        return $this->decidir(
            $donacion,
            $admin,
            EstadoDonacion::APROBADO,
            round($montoReal, 2),
            null,
        );
    }

    /** Rechaza la donación dejando constancia del motivo. */
    public function rechazar(Donacion $donacion, AdminUser $admin, string $motivo): ResultadoVerificacion
    {
        return $this->decidir(
            $donacion,
            $admin,
            EstadoDonacion::RECHAZADO,
            null,
            trim($motivo),
        );
    }

    private function decidir(
        Donacion $donacion,
        AdminUser $admin,
        EstadoDonacion $nuevo,
        ?float $montoReal,
        ?string $motivo,
    ): ResultadoVerificacion {
        return DB::transaction(function () use ($donacion, $admin, $nuevo, $montoReal, $motivo): ResultadoVerificacion {
            // Se relee con bloqueo: lo que trajo el controlador puede ser de
            // hace medio minuto, y en ese medio minuto otra persona pudo decidir.
            $fresca = Donacion::query()->lockForUpdate()->find($donacion->getKey());

            if ($fresca === null) {
                return ResultadoVerificacion::noEncontrada();
            }

            if ($fresca->estado !== EstadoDonacion::PENDIENTE) {
                return ResultadoVerificacion::yaVerificada($fresca->fresh(['verificadoPor']));
            }

            $anterior = $fresca->estado;

            $fresca->forceFill(array_filter([
                'estado' => $nuevo,
                'monto_real' => $montoReal,
                'motivo_rechazo' => $motivo,
                'verificado_por' => $admin->getKey(),
                'verificado_at' => now(),
            ], static fn (mixed $valor): bool => $valor !== null))->save();

            // El movimiento usa montoEfectivo(), que ya devuelve el monto_real
            // recién escrito. Al rechazar no hay nada que mover: el cambio es de
            // pendiente a rechazado y ninguno de los dos cuenta.
            $movimiento = ($this->moverContadores)($fresca, $anterior, $nuevo);

            Log::channel('payments')->info('Donación por QR verificada a mano', [
                'donacion_id' => $fresca->id,
                'fondo_id' => $fresca->fondo_id,
                'decision' => $nuevo->value,
                'admin_id' => $admin->getKey(),
                'monto_referencial' => (float) $fresca->monto_referencial,
                'monto_real' => $fresca->monto_real !== null ? (float) $fresca->monto_real : null,
                'movimiento_del_fondo' => $movimiento,
                'motivo' => $motivo,
            ]);

            return ResultadoVerificacion::decidida($fresca, $movimiento);
        });
    }
}
