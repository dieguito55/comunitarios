<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Ataduras entre el código y los archivos de despliegue.
 *
 * Son pruebas raras: no ejercitan una funcionalidad, comprueban que dos cosas
 * que viven en sitios distintos siguen diciendo lo mismo. Existen porque los
 * fallos que atrapan no se ven en local —el sitio funciona igual— y solo
 * aparecen en producción, con una donación real de por medio.
 */
final class ConfiguracionDespliegueTest extends TestCase
{
    /**
     * Las tres `MP_BACK_URL_*` de la plantilla de producción tienen que llevar
     * el parámetro que ResultadoDonacionController lee de verdad.
     *
     * Esto ya se desincronizó una vez: la plantilla ponía `?estado=exitosa` y
     * el controlador leía `?donacion=`. El resultado era que TODO donante
     * volvía del checkout a la pantalla genérica en vez de a su mensaje. No lo
     * detectó nadie porque el JavaScript corregía el texto un segundo después;
     * quien tuviera el JS desactivado se quedaba con lo genérico y punto.
     */
    public function test_las_back_urls_de_produccion_usan_el_parametro_que_el_controlador_lee(): void
    {
        $plantilla = (string) file_get_contents(base_path('.env.production.example'));

        /** @var array<string, string> $esperado */
        $esperado = [
            'MP_BACK_URL_SUCCESS' => 'aprobado',
            'MP_BACK_URL_FAILURE' => 'rechazado',
            'MP_BACK_URL_PENDING' => 'en_proceso',
        ];

        $rutaDelResultado = (string) parse_url(route('donacion.resultado'), PHP_URL_PATH);

        foreach ($esperado as $clave => $estadoProvisional) {
            $url = $this->valorDe($plantilla, $clave);

            $this->assertNotSame('', $url, "{$clave} está vacía en la plantilla de producción.");

            // 1. Apunta a la ruta que existe. La documentación llegó a apuntar
            //    a /donaciones, que no es ninguna ruta de este proyecto.
            $this->assertSame(
                $rutaDelResultado,
                (string) parse_url($url, PHP_URL_PATH),
                "{$clave} no apunta a la pantalla de resultado."
            );

            // 2. HTTPS, o Mercado Pago rechaza la preferencia (regla dura 3).
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME), "{$clave} no es HTTPS.");

            // 3. Y el parámetro dice algo que el controlador entiende.
            $consulta = (string) parse_url($url, PHP_URL_QUERY);

            $respuesta = $this->get(route('donacion.resultado').'?'.$consulta)->assertOk();

            $this->assertSame(
                $estadoProvisional,
                $respuesta->viewData('estadoProvisional'),
                "El parámetro de {$clave} no lo reconoce ResultadoDonacionController: "
                .'la plantilla y el controlador se han desincronizado.'
            );
        }
    }

    /**
     * En producción, Laravel genera enlaces https aunque el hosting le reenvíe
     * la petición por HTTP tras terminar el TLS por su cuenta.
     */
    public function test_en_produccion_las_urls_se_generan_con_https(): void
    {
        $this->assertStringStartsWith('http://', URL::to('/donar'), 'En local no debe forzarse nada.');

        $this->app->detectEnvironment(static fn (): string => 'production');
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/donar'));
        $this->assertStringStartsWith('https://', route('donacion.resultado'));
    }

    private function valorDe(string $plantilla, string $clave): string
    {
        return preg_match('/^'.preg_quote($clave, '/').'=(.*)$/m', $plantilla, $coincidencias) === 1
            ? trim($coincidencias[1])
            : '';
    }
}
