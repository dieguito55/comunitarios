<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

/**
 * Configura el SDK de Mercado Pago y expone sus clientes por el contenedor.
 *
 * El SDK guarda la configuración en propiedades estáticas, así que se ajusta
 * una sola vez y de forma perezosa: si una petición nunca toca Mercado Pago, no
 * se lee ni una credencial.
 */
final class MercadoPagoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PreferenceClient::class, function (): PreferenceClient {
            $this->configurarSdk();

            return new PreferenceClient;
        });

        $this->app->singleton(PaymentClient::class, function (): PaymentClient {
            $this->configurarSdk();

            return new PaymentClient;
        });

        $this->app->singleton(MerchantOrderClient::class, function (): MerchantOrderClient {
            $this->configurarSdk();

            return new MerchantOrderClient;
        });
    }

    private function configurarSdk(): void
    {
        MercadoPagoConfig::setAccessToken((string) config('mercadopago.access_token'));

        // SIEMPRE SERVER, también en sandbox: MercadoPagoConfig::LOCAL desactiva
        // la verificación del certificado TLS. El sandbox de MP se sirve por
        // HTTPS con certificado válido, así que no hay ninguna razón para
        // bajar esa defensa. Si en local falla la verificación, lo que falta es
        // curl.cainfo en php.ini, no relajar el cliente.
        MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::SERVER);

        // El SDK cuenta el tiempo de conexión en milisegundos; la configuración
        // de la aplicación lo expresa en segundos.
        MercadoPagoConfig::setConnectionTimeout(max(1, (int) config('mercadopago.timeout')) * 1000);
    }
}
