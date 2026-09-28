<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Enums\ProveedorPago;
use App\Exceptions\MercadoPagoNoConfigurado;
use App\Models\Donacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Preference\PreferenceClient;
use RuntimeException;

/**
 * Registra la donación y crea su preferencia de Checkout Pro.
 *
 * Reglas duras 9 y 10:
 *
 *   Regla 9  — la preferencia se crea DENTRO de la transacción del INSERT. Si
 *              MP falla, la transacción revierte y no queda una donación
 *              huérfana en estado pendiente que nadie va a cerrar nunca.
 *   Regla 10 — mp_preference_id se guarda en cuanto MP lo devuelve: es el plan
 *              B para encontrar el pago vía merchant_orders/search cuando el
 *              external_reference no aparece por ningún lado.
 *
 * La donación nace en `pendiente`. Solo el webhook, la reconciliación o el
 * rescate de la auditoría pueden moverla de ahí.
 */
final class CrearPreferencia
{
    public function __construct(
        private readonly PreferenceClient $clientePreferencias,
        private readonly ConstruirPreferencia $construirPreferencia,
        private readonly ResolverInitPoint $resolverInitPoint,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Salida de CrearDonacionRequest::datosDonacion()
     */
    public function __invoke(array $datos, ?string $ipOrigen = null): ResultadoCheckout
    {
        if (trim((string) config('mercadopago.access_token')) === '') {
            throw MercadoPagoNoConfigurado::faltaAccessToken();
        }

        return DB::transaction(function () use ($datos, $ipOrigen): ResultadoCheckout {
            $donacion = Donacion::query()->create([
                'nombre' => $datos['nombre'],
                'documento' => $datos['documento'],
                'correo' => $datos['correo'],
                'telefono' => $datos['telefono'],
                'tipo_aportante' => $datos['tipo_aportante'],
                'fondo_id' => $datos['fondo_id'],

                // Lo que el donante DECLARÓ. monto_real lo fija después quien
                // confirme el dinero; estas dos columnas no se pisan jamás.
                'monto_referencial' => $datos['monto'],
                'moneda' => $datos['moneda'],

                'canal_pago' => CanalPago::MERCADOPAGO,
                'proveedor_pago' => ProveedorPago::MERCADOPAGO,
                'estado' => EstadoDonacion::PENDIENTE,

                'visible_publico' => $datos['visible_publico'],
                'acepta_terminos' => $datos['acepta_terminos'],
                'ip_origen' => $ipOrigen,
            ]);

            // Regla 9: dentro de la transacción. Si esto lanza, el INSERT se va.
            $preferencia = $this->clientePreferencias->create(
                ($this->construirPreferencia)($donacion)
            );

            $preferenceId = trim((string) ($preferencia->id ?? ''));
            [$initPoint, $puntoUsado] = ($this->resolverInitPoint)($preferencia);

            if ($preferenceId === '' || $initPoint === '') {
                throw new RuntimeException('Mercado Pago no devolvió preference_id/init_point.');
            }

            // Regla 10: de inmediato, antes de responderle al navegador.
            $donacion->update(['mp_preference_id' => $preferenceId]);

            Log::channel('payments')->info('Preferencia de Mercado Pago creada', [
                'donacion_id' => $donacion->id,
                'preference_id' => $preferenceId,
                'monto_referencial' => (float) $donacion->monto_referencial,
                'moneda' => $donacion->moneda,
                'fondo_id' => $donacion->fondo_id,
                'punto_de_checkout' => $puntoUsado,
            ]);

            return new ResultadoCheckout(
                donacionId: (int) $donacion->id,
                preferenceId: $preferenceId,
                initPoint: $initPoint,
                modoSandbox: mb_strtolower((string) config('mercadopago.env')) !== 'production',
                puntoDeCheckout: $puntoUsado,
            );
        });
    }
}
