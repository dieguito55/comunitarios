<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Enums\ProveedorPago;
use App\Enums\TipoAportante;
use App\Exceptions\MercadoPagoNoConfigurado;
use App\Models\Donacion;
use App\Services\MercadoPago\CrearPreferencia;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Cada caso de aquí corresponde a una regla dura: un bug real que costó pagos
 * en el sistema de referencia. Si alguno se pone en rojo, hay dinero en juego.
 */
final class ReglasDurasMercadoPagoTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // MercadoPagoConfig guarda el cliente en una propiedad estática: sin
        // esto, el transporte falso se filtraría al resto de la suite.
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);

        parent::tearDown();
    }

    private function configurarSandboxConCredencialesDePrueba(): void
    {
        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
            'mercadopago.public_key' => 'TEST-abcdef-1234',
            'mercadopago.use_sandbox_init_point' => false,
            'mercadopago.currency' => 'PEN',
            'mercadopago.sandbox_payer_email' => '',
            'mercadopago.webhook_url' => 'https://comunitarios.org/api/donaciones/webhook',
            'mercadopago.statement_descriptor' => 'COMUNITARIOS',
            'mercadopago.back_urls.success' => 'https://comunitarios.org/donaciones',
            'mercadopago.back_urls.failure' => 'https://comunitarios.org/donaciones',
            'mercadopago.back_urls.pending' => 'https://comunitarios.org/donaciones',
        ]);
    }

    private function servicioCon(ClienteHttpMercadoPagoFalso $falso): CrearPreferencia
    {
        MercadoPagoConfig::setHttpClient($falso);
        $this->app->forgetInstance(PreferenceClient::class);
        $this->app->forgetInstance(CrearPreferencia::class);

        return $this->app->make(CrearPreferencia::class);
    }

    /** @return array<string, mixed> */
    private function datos(array $sobrescribir = []): array
    {
        return array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'telefono' => '+51 999 888 777',
            'tipo_aportante' => TipoAportante::PERSONA,
            'fondo_id' => $this->fondoDePrueba()->id,
            'monto' => 150.0,
            'moneda' => 'PEN',
            'visible_publico' => true,
            'acepta_terminos' => true,
        ], $sobrescribir);
    }

    /** Reglas 1 y 2: la llave de cruce con MP, y su respaldo en metadata. */
    public function test_regla_1_y_2_external_reference_es_el_id_y_se_duplica_en_metadata(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        $falso = new ClienteHttpMercadoPagoFalso;

        $resultado = ($this->servicioCon($falso))($this->datos(), '203.0.113.9');

        $this->assertIsString($falso->ultimoPayload['external_reference']);
        $this->assertSame((string) $resultado->donacionId, $falso->ultimoPayload['external_reference']);
        $this->assertSame((string) $resultado->donacionId, $falso->ultimoPayload['metadata']['donation_id']);
    }

    /** Regla 3: con success en HTTP, MP rechaza la preferencia con un 400. */
    public function test_regla_3_auto_return_solo_con_success_https(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();

        $conHttps = new ClienteHttpMercadoPagoFalso;
        ($this->servicioCon($conHttps))($this->datos());
        $this->assertSame('approved', $conHttps->ultimoPayload['auto_return']);

        config(['mercadopago.back_urls.success' => 'http://localhost/donaciones']);
        $conHttp = new ClienteHttpMercadoPagoFalso;
        ($this->servicioCon($conHttp))($this->datos());
        $this->assertArrayNotHasKey('auto_return', $conHttp->ultimoPayload);
    }

    /** Regla 7: mezclar credenciales y punto de checkout rompe el pago. */
    public function test_regla_7_credenciales_test_usan_sandbox_init_point(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();

        $resultado = ($this->servicioCon(new ClienteHttpMercadoPagoFalso))($this->datos());

        $this->assertSame('sandbox_init_point', $resultado->puntoDeCheckout);
        $this->assertStringContainsString('sandbox', $resultado->initPoint);
        $this->assertTrue($resultado->modoSandbox);
    }

    public function test_regla_7_credenciales_app_usr_usan_init_point(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config([
            'mercadopago.env' => 'production',
            'mercadopago.access_token' => 'APP_USR-0000-abcdef',
            'mercadopago.public_key' => 'APP_USR-abcdef',
        ]);

        $resultado = ($this->servicioCon(new ClienteHttpMercadoPagoFalso))($this->datos());

        $this->assertSame('init_point', $resultado->puntoDeCheckout);
        $this->assertStringNotContainsString('sandbox', $resultado->initPoint);
        $this->assertFalse($resultado->modoSandbox);
    }

    /** Regla 8: en sandbox, un pagador que no es de prueba hace fallar el pago. */
    public function test_regla_8_en_sandbox_no_se_envia_el_correo_real_del_donante(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos());

        $this->assertArrayNotHasKey('payer', $falso->ultimoPayload);
    }

    public function test_regla_8_en_sandbox_si_hay_usuario_de_prueba_configurado_se_usa_ese(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config(['mercadopago.sandbox_payer_email' => 'test_user_123@testuser.com']);
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos());

        $this->assertSame('test_user_123@testuser.com', $falso->ultimoPayload['payer']['email']);
    }

    public function test_regla_8_en_produccion_van_los_datos_reales_del_donante(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config([
            'mercadopago.env' => 'production',
            'mercadopago.access_token' => 'APP_USR-0000-abcdef',
        ]);
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos());

        $this->assertSame('maria@ejemplo.com', $falso->ultimoPayload['payer']['email']);
        $this->assertSame('DNI', $falso->ultimoPayload['payer']['identification']['type']);
        $this->assertSame('44556677', $falso->ultimoPayload['payer']['identification']['number']);
    }

    public function test_un_ruc_de_once_digitos_no_se_envia_como_dni(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config([
            'mercadopago.env' => 'production',
            'mercadopago.access_token' => 'APP_USR-0000-abcdef',
        ]);
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos([
            'documento' => '20512345678',
            'tipo_aportante' => TipoAportante::EMPRESA,
        ]));

        $this->assertArrayNotHasKey('identification', $falso->ultimoPayload['payer']);
    }

    /** Regla 9: si MP falla, no puede quedar una donación huérfana. */
    public function test_regla_9_si_mercado_pago_falla_la_donacion_no_se_guarda(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();

        $this->expectException(MPApiException::class);

        try {
            ($this->servicioCon(ClienteHttpMercadoPagoFalso::queFalla()))($this->datos());
        } finally {
            $this->assertSame(0, Donacion::query()->count());
        }
    }

    /** Regla 10: plan B para encontrar el pago si el external_reference se pierde. */
    public function test_regla_10_mp_preference_id_se_guarda_de_inmediato(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();

        $resultado = ($this->servicioCon(new ClienteHttpMercadoPagoFalso))($this->datos());

        $this->assertSame('1234567890-abcd-efgh', Donacion::query()->findOrFail($resultado->donacionId)->mp_preference_id);
    }

    public function test_la_donacion_nace_pendiente_y_con_el_monto_declarado(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();

        $resultado = ($this->servicioCon(new ClienteHttpMercadoPagoFalso))($this->datos(), '203.0.113.9');
        $donacion = Donacion::query()->findOrFail($resultado->donacionId);

        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->estado);
        $this->assertSame(CanalPago::MERCADOPAGO, $donacion->canal_pago);
        $this->assertSame(ProveedorPago::MERCADOPAGO, $donacion->proveedor_pago);

        // monto_real solo lo fija quien confirma el dinero. Aquí todavía no.
        $this->assertSame(150.0, (float) $donacion->monto_referencial);
        $this->assertNull($donacion->monto_real);
        $this->assertSame(150.0, $donacion->montoEfectivo());
        $this->assertSame('203.0.113.9', $donacion->ip_origen);
    }

    public function test_sin_access_token_falla_rapido_y_no_llama_a_mercado_pago(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config(['mercadopago.access_token' => '']);
        $falso = new ClienteHttpMercadoPagoFalso;

        $this->expectException(MercadoPagoNoConfigurado::class);

        try {
            ($this->servicioCon($falso))($this->datos());
        } finally {
            $this->assertSame(0, $falso->peticiones);
            $this->assertSame(0, Donacion::query()->count());
        }
    }

    /** El enlace de pago caduca: nadie debe poder pagar una preferencia vieja. */
    public function test_la_preferencia_caduca_segun_su_propia_clave_de_configuracion(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config(['donaciones.preferencia_expira_horas' => 6]);
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos());

        $this->assertTrue($falso->ultimoPayload['expires']);

        $desde = new DateTimeImmutable($falso->ultimoPayload['expiration_date_from']);
        $hasta = new DateTimeImmutable($falso->ultimoPayload['expiration_date_to']);

        $this->assertSame(6 * 3600, $hasta->getTimestamp() - $desde->getTimestamp());
    }

    /** `expiracion_horas` es la barrida interna de pendientes: otra cosa. */
    public function test_la_caducidad_del_enlace_no_usa_la_clave_de_la_barrida_interna(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        config(['donaciones.preferencia_expira_horas' => 3, 'donaciones.expiracion_horas' => 48]);
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos());

        $desde = new DateTimeImmutable($falso->ultimoPayload['expiration_date_from']);
        $hasta = new DateTimeImmutable($falso->ultimoPayload['expiration_date_to']);

        $this->assertSame(3 * 3600, $hasta->getTimestamp() - $desde->getTimestamp());
    }

    public function test_las_back_urls_avisan_a_la_pagina_de_retorno_que_paso(): void
    {
        $this->configurarSandboxConCredencialesDePrueba();
        $falso = new ClienteHttpMercadoPagoFalso;

        ($this->servicioCon($falso))($this->datos());

        $this->assertStringContainsString('donacion=exitosa', $falso->ultimoPayload['back_urls']['success']);
        $this->assertStringContainsString('donacion=fallida', $falso->ultimoPayload['back_urls']['failure']);
        $this->assertStringContainsString('donacion=pendiente', $falso->ultimoPayload['back_urls']['pending']);
    }
}
