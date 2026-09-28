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
    public function __construct(
        private readonly MoverContadoresFondo $moverContadores,
        private readonly CalcularPendientes $pendientes,
    ) {}

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

    /**
     * Deshace una verificación ya tomada y devuelve la donación a `pendiente`.
     *
     * ── REVERTIR, NO BORRAR ─────────────────────────────────────────────────
     *
     * Una donación es un registro contable. Borrarla haría desaparecer el
     * dinero del historial sin rastro y la rendición de cuentas de la fundación
     * dejaría de cuadrar. Revertir la devuelve al estado en que estaba antes de
     * que alguien decidiera, dejando escrito quién deshizo qué y por qué.
     *
     * ── SIEMPRE A PENDIENTE, NUNCA DIRECTAMENTE A RECHAZADO ─────────────────
     *
     * Revertir significa «deshago la decisión», no «tomo otra distinta». Tomar
     * la otra es lo que ya hace `rechazar()`, con su motivo y su firma. Si se
     * permitiera saltar de aprobada a rechazada en un solo paso, el registro
     * diría que se rechazó sin que nadie hubiera vuelto a mirar el comprobante.
     * Así son dos actos, y los dos quedan firmados.
     *
     * ── EL ORDEN IMPORTA ────────────────────────────────────────────────────
     *
     * Los contadores se mueven ANTES de limpiar `monto_real`, porque
     * MoverContadoresFondo resta `montoEfectivo()`: si se limpiara primero,
     * restaría el monto DECLARADO en vez del que de verdad se sumó, y el fondo
     * quedaría descuadrado justo al intentar corregirlo.
     */
    public function revertir(Donacion $donacion, AdminUser $admin, string $motivo): ResultadoVerificacion
    {
        return DB::transaction(function () use ($donacion, $admin, $motivo): ResultadoVerificacion {
            $fresca = Donacion::query()->lockForUpdate()->find($donacion->getKey());

            if ($fresca === null) {
                return ResultadoVerificacion::noEncontrada();
            }

            // Ya la revirtió otra persona entre la carga de la página y el clic.
            if ($fresca->estado === EstadoDonacion::PENDIENTE) {
                return ResultadoVerificacion::yaVerificada($fresca);
            }

            $anterior = $fresca->estado;

            // Primero el dinero, con monto_real todavía puesto.
            $movimiento = ($this->moverContadores)($fresca, $anterior, EstadoDonacion::PENDIENTE);

            $fresca->forceFill([
                'estado' => EstadoDonacion::PENDIENTE,

                // Vuelve a no saberse cuánto entró: lo dirá quien la verifique
                // de nuevo. monto_referencial —lo que declaró el donante— no se
                // toca aquí ni en ningún otro sitio.
                'monto_real' => null,
                'motivo_rechazo' => null,

                // verificado_por y verificado_at NO se limpian: son el rastro
                // de la primera decisión, y es lo que habrá que poder consultar
                // después para saber quién se equivocó.
                'revertido_por' => $admin->getKey(),
                'revertido_at' => now(),
                'motivo_reversion' => trim($motivo),
            ])->save();

            $this->pendientes->olvidarCacheDeFondo($fresca->fondo_id);

            Log::channel('payments')->warning('Verificación revertida a mano', [
                'donacion_id' => $fresca->id,
                'fondo_id' => $fresca->fondo_id,
                'estado_anterior' => $anterior->value,
                'revertido_por' => $admin->getKey(),
                'verificado_por' => $fresca->verificado_por,
                'movimiento_del_fondo' => $movimiento,
                'motivo' => trim($motivo),
            ]);

            return ResultadoVerificacion::decidida($fresca, $movimiento);
        });
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

            $this->pendientes->olvidarCacheDeFondo($fresca->fondo_id);

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
