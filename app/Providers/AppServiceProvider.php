<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forzarHttpsEnProduccion();
        $this->registrarLimitesDePeticiones();
    }

    /**
     * En produccion, todas las URLs que genere Laravel salen con https.
     *
     * El hosting termina el TLS en su capa y nos reenvia la peticion por HTTP,
     * asi que Laravel ve `http` y construiria enlaces `http://`. Eso rompe dos
     * cosas: las back_urls que se le mandan a Mercado Pago —que exige HTTPS
     * para aceptar auto_return, regla dura 3— y cualquier enlace absoluto de la
     * pantalla de retorno, que acabaria dando avisos de contenido mixto.
     *
     * Solo en produccion: en local no hay certificado y forzarlo dejaria el
     * sitio inaccesible.
     */
    private function forzarHttpsEnProduccion(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Límite por IP de los endpoints públicos de donación (deuda técnica 10).
     *
     * El tope por minuto frena el clic repetido y los bots de formulario; el
     * diario evita que una sola IP llene la tabla de donaciones pendientes
     * basura, que luego habría que limpiar a mano.
     */
    private function registrarLimitesDePeticiones(): void
    {
        /*
         * El dashboard es solo lectura y esta cacheado, asi que el limite es
         * holgado: existe para frenar un bucle, no para racionar visitas. Un
         * 429 aqui dejaria la portada con las cifras congeladas.
         */
        RateLimiter::for('dashboard', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->ip()));

        RateLimiter::for('donaciones', function (Request $request): array {
            $ip = (string) $request->ip();

            return [
                Limit::perMinute((int) config('donaciones.limite_peticiones.por_minuto', 8))->by($ip),
                Limit::perDay((int) config('donaciones.limite_peticiones.por_dia', 40))->by($ip),
            ];
        });
    }
}
