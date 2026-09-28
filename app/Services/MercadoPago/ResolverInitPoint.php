<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use MercadoPago\Resources\Preference;

/**
 * Decide a qué URL se redirige al donante. Regla dura 7.
 *
 * Con credenciales APP_USR- se usa `init_point`; con credenciales TEST-
 * clásicas, `sandbox_init_point`. Mezclarlos produce un "Algo salió mal" en el
 * checkout que no da ninguna pista de la causa.
 *
 * La bandera MP_USE_SANDBOX_INIT_POINT solo puede FORZAR el punto de sandbox,
 * nunca apagarlo: si el entorno es sandbox y las credenciales no son APP_USR-,
 * usar init_point rompe el checkout sí o sí, y una bandera mal puesta no debe
 * poder provocarlo.
 */
final class ResolverInitPoint
{
    /**
     * @return array{0: string, 1: string} [url, punto usado]
     */
    public function __invoke(Preference $preferencia): array
    {
        $usarSandbox = $this->debeUsarPuntoSandbox();

        $initPoint = trim((string) ($preferencia->init_point ?? ''));
        $sandboxInitPoint = trim((string) ($preferencia->sandbox_init_point ?? ''));

        if ($usarSandbox) {
            $url = $sandboxInitPoint !== '' ? $sandboxInitPoint : $initPoint;
            $punto = $sandboxInitPoint !== '' ? 'sandbox_init_point' : 'init_point';
        } else {
            $url = $initPoint !== '' ? $initPoint : $sandboxInitPoint;
            $punto = $initPoint !== '' ? 'init_point' : 'sandbox_init_point';
        }

        return [$url, $punto];
    }

    private function debeUsarPuntoSandbox(): bool
    {
        if ((bool) config('mercadopago.use_sandbox_init_point')) {
            return true;
        }

        return $this->esSandbox() && ! $this->usaCredencialesDeProduccion();
    }

    private function esSandbox(): bool
    {
        return mb_strtolower((string) config('mercadopago.env')) !== 'production';
    }

    private function usaCredencialesDeProduccion(): bool
    {
        $publica = mb_strtoupper(trim((string) config('mercadopago.public_key')));
        $token = mb_strtoupper(trim((string) config('mercadopago.access_token')));

        return str_starts_with($publica, 'APP_USR-') || str_starts_with($token, 'APP_USR-');
    }
}
