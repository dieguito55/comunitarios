<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Models\Donacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Contrato HTTP de POST /api/donaciones/mercadopago.
 *
 * El navegador solo entiende dos formas de respuesta:
 *   éxito → {"success": true, "init_point", "preference_id", "donacion_id", ...}
 *   fallo → {"success": false, "error": "<mensaje para el donante>"}
 *
 * Cualquier ruta de error que se salga de ahí deja al donante con un mensaje
 * genérico y sin pista de qué hacer.
 */
final class CrearDonacionEndpointTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const RUTA = '/api/donaciones/mercadopago';

    protected function setUp(): void
    {
        parent::setUp();

        // El límite por IP vive en la caché: sin limpiarla, los intentos de un
        // test se acumulan sobre los del siguiente.
        $this->app->make('cache')->flush();

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
            'mercadopago.public_key' => 'TEST-abcdef-1234',
            'mercadopago.currency' => 'PEN',
            'mercadopago.sandbox_payer_email' => '',
            'mercadopago.back_urls.success' => 'https://comunitarios.org/donaciones',
            'mercadopago.back_urls.failure' => 'https://comunitarios.org/donaciones',
            'mercadopago.back_urls.pending' => 'https://comunitarios.org/donaciones',
            'donaciones.monto_minimo_mp' => 5,
            'donaciones.monto_maximo' => 10000,
        ]);
    }

    protected function tearDown(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);

        parent::tearDown();
    }

    private function usarMercadoPagoFalso(): ClienteHttpMercadoPagoFalso
    {
        $falso = new ClienteHttpMercadoPagoFalso;
        MercadoPagoConfig::setHttpClient($falso);
        $this->app->forgetInstance(PreferenceClient::class);

        return $falso;
    }

    /** @return array<string, mixed> */
    private function formulario(array $sobrescribir = []): array
    {
        return array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'telefono' => '+51 999 888 777',
            'fondo_id' => $this->fondoDePrueba()->id,
            'monto' => 150,
            'moneda' => 'PEN',
            'tipo_aportante' => 'persona',
            'visible_publico' => true,
            'acepta_terminos' => true,
        ], $sobrescribir);
    }

    public function test_devuelve_el_init_point_y_registra_la_donacion(): void
    {
        $this->usarMercadoPagoFalso();

        $respuesta = $this->postJson(self::RUTA, $this->formulario());

        $respuesta->assertOk()
            ->assertJson([
                'success' => true,
                'preference_id' => '1234567890-abcd-efgh',
                'modo_sandbox' => true,
                'punto_de_checkout' => 'sandbox_init_point',
            ])
            ->assertJsonStructure(['success', 'donacion_id', 'preference_id', 'init_point']);

        $donacion = Donacion::query()->sole();

        $this->assertSame($donacion->id, $respuesta->json('donacion_id'));
        $this->assertSame('María Pérez', $donacion->nombre);
    }

    public function test_el_id_de_la_donacion_es_la_llave_que_viaja_a_mercado_pago(): void
    {
        $falso = $this->usarMercadoPagoFalso();

        $respuesta = $this->postJson(self::RUTA, $this->formulario());

        $this->assertSame(
            (string) $respuesta->json('donacion_id'),
            $falso->ultimoPayload['external_reference']
        );
    }

    public function test_rechaza_un_formulario_vacio_en_castellano(): void
    {
        $respuesta = $this->postJson(self::RUTA, []);

        $respuesta->assertStatus(422)->assertJson(['success' => false]);

        $errores = $respuesta->json('errores');
        $this->assertIsArray($errores);
        $this->assertNotEmpty($errores);

        // Ninguna regla puede salir como clave de traducción sin traducir.
        foreach ($errores as $mensaje) {
            $this->assertStringNotContainsString('validation.', (string) $mensaje);
        }

        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_respeta_los_montos_minimo_y_maximo_configurados(): void
    {
        $this->postJson(self::RUTA, $this->formulario(['monto' => 2]))->assertStatus(422);
        $this->postJson(self::RUTA, $this->formulario(['monto' => 99999]))->assertStatus(422);

        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_exige_aceptar_los_terminos(): void
    {
        $this->postJson(self::RUTA, $this->formulario(['acepta_terminos' => false]))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_respeta_el_anonimato_pedido_por_el_donante(): void
    {
        $this->usarMercadoPagoFalso();

        $this->postJson(self::RUTA, $this->formulario(['visible_publico' => false]))->assertOk();

        $this->assertFalse(Donacion::query()->sole()->visible_publico);
    }

    public function test_sin_credenciales_responde_503_y_no_registra_nada(): void
    {
        config(['mercadopago.access_token' => '']);

        $this->postJson(self::RUTA, $this->formulario())
            ->assertStatus(503)
            ->assertJson(['success' => false]);

        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_si_mercado_pago_responde_error_devuelve_502_sin_filtrar_el_detalle(): void
    {
        $falso = ClienteHttpMercadoPagoFalso::queFalla(400, 'invalid back_urls');
        MercadoPagoConfig::setHttpClient($falso);
        $this->app->forgetInstance(PreferenceClient::class);

        $respuesta = $this->postJson(self::RUTA, $this->formulario());

        $respuesta->assertStatus(502)->assertJson(['success' => false]);
        $this->assertStringNotContainsString('back_urls', (string) $respuesta->json('error'));
        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_limita_los_intentos_por_ip_y_mantiene_el_contrato_de_error(): void
    {
        $porMinuto = (int) config('donaciones.limite_peticiones.por_minuto');

        for ($intento = 0; $intento < $porMinuto; $intento++) {
            $this->postJson(self::RUTA, [])->assertStatus(422);
        }

        $this->postJson(self::RUTA, [])
            ->assertStatus(429)
            ->assertJson(['success' => false])
            ->assertHeader('Retry-After');
    }
}
