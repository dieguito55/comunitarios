<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Enums\EstadoDonacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Segunda red de seguridad: el navegador del donante vuelve del checkout y
 * confirma con lo que tenga a mano. Atrapa lo que el webhook pierde.
 *
 * Es público y sin sesión, así que buena parte de estos casos comprueban que no
 * se puede sacar información ni forzar un estado desde fuera.
 */
final class ReconciliarDonacionTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const RUTA = '/api/donaciones/reconciliar';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
        ]);
    }

    protected function tearDown(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);

        parent::tearDown();
    }

    private function usarMercadoPago(ClienteHttpMercadoPagoFalso $falso): ClienteHttpMercadoPagoFalso
    {
        MercadoPagoConfig::setHttpClient($falso);
        $this->app->forgetInstance(PaymentClient::class);
        $this->app->forgetInstance(MerchantOrderClient::class);

        return $falso;
    }

    public function test_confirma_la_donacion_con_el_payment_id_de_la_url_de_retorno(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9700, 'approved', (string) $donacion->id, 300.00)
        );

        $this->postJson(self::RUTA, ['payment_id' => 9700])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'estado' => 'aprobado',
                'reconciliado' => true,
            ]);

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::APROBADO, $donacion->estado);
        $this->assertSame(300.00, (float) $donacion->monto_real);
        $this->assertSame(150.00, (float) $donacion->monto_referencial);
    }

    public function test_encuentra_el_pago_solo_con_el_id_de_la_donacion(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9800, 'approved', (string) $donacion->id, 75.50)
        );

        $this->postJson(self::RUTA, ['donacion_id' => $donacion->id])
            ->assertOk()
            ->assertJson(['estado' => 'aprobado', 'reconciliado' => true]);

        $this->assertSame(1, $falso->busquedasDePago);
        $this->assertSame(75.50, (float) $donacion->refresh()->monto_real);
    }

    /** El caso habitual: el donante vuelve sin payment_id en la URL. */
    public function test_encuentra_la_donacion_por_la_preferencia(): void
    {
        $donacion = $this->donacionPendiente(['mp_preference_id' => 'PREF-ABC-123']);
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9801, 'approved', (string) $donacion->id, 40.00)
        );

        $this->postJson(self::RUTA, ['preference_id' => 'PREF-ABC-123'])
            ->assertOk()
            ->assertJson(['estado' => 'aprobado', 'reconciliado' => true]);

        $this->assertSame(40.00, (float) $donacion->refresh()->monto_real);
    }

    public function test_acepta_collection_id_como_alias_de_payment_id(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9700, 'approved', (string) $donacion->id)
        );

        $this->postJson(self::RUTA, ['collection_id' => 9700])
            ->assertOk()
            ->assertJson(['estado' => 'aprobado']);
    }

    public function test_si_todavia_no_hay_pago_devuelve_el_estado_sin_error(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $this->postJson(self::RUTA, ['donacion_id' => $donacion->id])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'estado' => 'pendiente',
                'reconciliado' => false,
            ]);

        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->refresh()->estado);
    }

    public function test_es_idempotente(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9700, 'approved', (string) $donacion->id, 150.00)
        );

        $this->postJson(self::RUTA, ['payment_id' => 9700])->assertOk()->assertJson(['reconciliado' => true]);

        // La segunda vez ya no hay nada que cambiar, pero el estado es el mismo.
        $this->postJson(self::RUTA, ['payment_id' => 9700])
            ->assertOk()
            ->assertJson(['estado' => 'aprobado', 'reconciliado' => false]);

        $this->assertSame(150.00, (float) $donacion->refresh()->monto_real);
    }

    // ── AT-19 ────────────────────────────────────────────────────────────────

    /**
     * El endpoint es público: no puede servir para enumerar donaciones ajenas
     * ni para filtrar datos de otro donante.
     */
    public function test_at19_una_donacion_ajena_no_revela_nada(): void
    {
        $ajena = $this->donacionPendiente([
            'nombre' => 'Donante Ajeno',
            'correo' => 'ajeno@ejemplo.com',
            'monto_referencial' => 5000.00,
            'estado' => EstadoDonacion::APROBADO,
            'monto_real' => 5000.00,
            'mp_payment_id' => '9999',
        ]);

        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $respuesta = $this->postJson(self::RUTA, ['donacion_id' => $ajena->id]);
        $respuesta->assertOk();

        // Solo el estado. Ni nombre, ni correo, ni monto, ni ids internos.
        $this->assertSame(
            ['success', 'estado', 'reconciliado'],
            array_keys($respuesta->json())
        );

        $cuerpo = $respuesta->getContent();
        $this->assertStringNotContainsString('Donante Ajeno', (string) $cuerpo);
        $this->assertStringNotContainsString('ajeno@ejemplo.com', (string) $cuerpo);
        $this->assertStringNotContainsString('5000', (string) $cuerpo);
        $this->assertStringNotContainsString('9999', (string) $cuerpo);
    }

    /** Una donación inexistente se ve igual que una pendiente. */
    public function test_at19_una_donacion_inexistente_responde_como_una_pendiente(): void
    {
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $inexistente = $this->postJson(self::RUTA, ['donacion_id' => 999999999]);
        $pendiente = $this->postJson(self::RUTA, ['donacion_id' => $this->donacionPendiente()->id]);

        $inexistente->assertOk()->assertExactJson([
            'success' => true,
            'estado' => 'pendiente',
            'reconciliado' => false,
        ]);

        $this->assertSame($pendiente->getStatusCode(), $inexistente->getStatusCode());
        $this->assertSame($pendiente->json(), $inexistente->json());
    }

    public function test_el_cliente_no_puede_afirmar_un_estado(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9700, 'rejected', (string) $donacion->id)
        );

        // El cliente manda "estado: aprobado". Manda lo que diga Mercado Pago.
        $this->postJson(self::RUTA, ['payment_id' => 9700, 'estado' => 'aprobado', 'monto_real' => 99999])
            ->assertOk()
            ->assertJson(['estado' => 'rechazado']);

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->estado);
        $this->assertNull($donacion->monto_real);
    }

    public function test_exige_al_menos_una_de_las_tres_llaves(): void
    {
        $this->postJson(self::RUTA, [])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_si_mercado_pago_falla_devuelve_502_sin_filtrar_el_detalle(): void
    {
        $this->usarMercadoPago(ClienteHttpMercadoPagoFalso::queFalla(503, 'detalle interno de mp'));

        $respuesta = $this->postJson(self::RUTA, ['payment_id' => 9700]);

        $respuesta->assertStatus(502)->assertJson(['success' => false]);
        $this->assertStringNotContainsString('detalle interno', (string) $respuesta->json('error'));
    }

    public function test_sin_credenciales_responde_503(): void
    {
        config(['mercadopago.access_token' => '']);
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $this->postJson(self::RUTA, ['payment_id' => 9700])
            ->assertStatus(503)
            ->assertJson(['success' => false]);
    }

    /** A diferencia del webhook, este SÍ se limita: lo llama el público. */
    public function test_limita_los_intentos_por_ip(): void
    {
        $porMinuto = (int) config('donaciones.limite_peticiones.por_minuto');

        for ($intento = 0; $intento < $porMinuto; $intento++) {
            $this->postJson(self::RUTA, [])->assertStatus(422);
        }

        $this->postJson(self::RUTA, [])->assertStatus(429);
    }
}
