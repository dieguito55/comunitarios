<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Enums\EstadoDonacion;
use App\Models\Donacion;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Primera red de seguridad. Cada caso corresponde a una regla dura o a una
 * deuda técnica; si alguno se pone en rojo, hay dinero en juego.
 */
final class WebhookMercadoPagoTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const RUTA = '/api/webhooks/mercadopago';

    private const SECRETO = 'secreto-de-prueba-del-webhook';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
            'mercadopago.webhook_secret' => self::SECRETO,
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

    /**
     * Notifica como lo hace Mercado Pago: `data.id` en la query y firma
     * HMAC-SHA256 sobre `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`.
     */
    private function notificar(
        string $dataId,
        string $tipo = 'payment',
        ?string $firma = null,
        ?string $requestId = null,
        ?int $ts = null,
    ): TestResponse {
        $requestId ??= 'req-'.bin2hex(random_bytes(4));
        $marca = (string) ($ts ?? time());

        $firma ??= 'ts='.$marca.',v1='.hash_hmac(
            'sha256',
            "id:{$dataId};request-id:{$requestId};ts:{$marca};",
            self::SECRETO
        );

        return $this->withHeaders(['x-request-id' => $requestId, 'x-signature' => $firma])
            ->postJson(
                self::RUTA.'?data.id='.$dataId.'&type='.$tipo,
                ['type' => $tipo, 'data' => ['id' => $dataId]]
            );
    }

    // ── AT-04 ────────────────────────────────────────────────────────────────

    public function test_at04_orden_sin_pagos_reconsulta_una_vez_e_ignora(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = $this->usarMercadoPago((new ClienteHttpMercadoPagoFalso)->conOrden(7002, []));

        $this->notificar('7002', 'merchant_order')->assertOk()->assertJson(['success' => true]);

        // Una consulta más la reconsulta de la regla 4. Ni una más.
        $this->assertSame(2, $falso->consultasDeOrden);
        $this->assertSame(0, $falso->consultasDePago);
        $this->assertSame('ignored', WebhookLog::query()->sole()->status);
        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->refresh()->estado);
    }

    public function test_at04_orden_que_entrega_su_pago_en_la_reconsulta_si_se_procesa(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)
                ->conPago(9003, 'approved', (string) $donacion->id)
                ->conOrden(7001, [['id' => 9003, 'status' => 'approved']])
        );
        $falso->ordenEntregaPagoDesdeConsulta = 2;

        $this->notificar('7001', 'merchant_order')->assertOk();

        $this->assertSame(2, $falso->consultasDeOrden);
        $this->assertSame(EstadoDonacion::APROBADO, $donacion->refresh()->estado);
    }

    // ── AT-05 ────────────────────────────────────────────────────────────────

    public function test_at05_si_mercado_pago_falla_se_responde_500(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(ClienteHttpMercadoPagoFalso::queFalla(503, 'Service Unavailable'));

        $this->notificar('9001')->assertStatus(500)->assertJson(['success' => false]);

        $bitacora = WebhookLog::query()->sole();

        $this->assertSame('error', $bitacora->status);
        $this->assertFalse($bitacora->procesado);
        $this->assertNotEmpty($bitacora->error_mensaje);
        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->refresh()->estado);
    }

    public function test_at05b_si_la_base_de_datos_falla_responde_500_y_no_deja_nada_a_medias(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'approved', (string) $donacion->id)
        );

        // Se rompe la tabla a mitad del proceso: el registro crudo ya está hecho,
        // pero la escritura de la donación no puede completarse.
        $this->app->make('db')->statement('DROP TABLE donaciones');

        $this->notificar('9001')->assertStatus(500);

        $bitacora = WebhookLog::query()->sole();
        $this->assertSame('error', $bitacora->status);
        $this->assertFalse($bitacora->procesado);
    }

    // ── AT-06 ────────────────────────────────────────────────────────────────

    /** Regla 6: el cuerpo miente, la API manda. */
    public function test_at06_gana_lo_que_dice_la_api_no_el_cuerpo_del_webhook(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'rejected', (string) $donacion->id)
        );

        $requestId = 'req-mentiroso';
        $ts = (string) time();
        $firma = 'ts='.$ts.',v1='.hash_hmac('sha256', "id:9001;request-id:{$requestId};ts:{$ts};", self::SECRETO);

        // El cuerpo afirma "approved". Da igual.
        $this->withHeaders(['x-request-id' => $requestId, 'x-signature' => $firma])
            ->postJson(self::RUTA.'?data.id=9001&type=payment', [
                'type' => 'payment',
                'action' => 'payment.updated',
                'data' => ['id' => '9001', 'status' => 'approved'],
            ])
            ->assertOk();

        $this->assertSame(1, $falso->consultasDePago);
        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->refresh()->estado);
        $this->assertNull($donacion->monto_real);
    }

    // ── AT-12 ────────────────────────────────────────────────────────────────

    public function test_at12_firma_alterada_devuelve_401_y_no_toca_la_donacion(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'approved', (string) $donacion->id)
        );

        $this->notificar('9001', 'payment', 'ts=1700000000,v1=firmafalsa')->assertStatus(401);

        $this->assertSame(0, $falso->consultasDePago);
        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->refresh()->estado);

        // La prueba forense existe incluso para lo que se rechaza.
        $bitacora = WebhookLog::query()->sole();
        $this->assertFalse($bitacora->firma_valida);
        $this->assertSame('firma_invalida', $bitacora->status);
    }

    public function test_at12b_sin_cabecera_de_firma_devuelve_401(): void
    {
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $this->withHeaders(['x-request-id' => 'req-sin-firma'])
            ->postJson(self::RUTA.'?data.id=9001&type=payment', ['type' => 'payment', 'data' => ['id' => '9001']])
            ->assertStatus(401);

        $this->assertFalse(WebhookLog::query()->sole()->firma_valida);
    }

    public function test_at12c_una_firma_de_hace_diez_minutos_devuelve_401(): void
    {
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        // Firma perfectamente válida… pero caducada. Ataque de repetición.
        $this->notificar('9001', 'payment', null, 'req-viejo', time() - 600)->assertStatus(401);

        $this->assertSame(0, Donacion::query()->whereNotNull('mp_payment_id')->count());
    }

    public function test_una_firma_de_otro_recurso_no_sirve(): void
    {
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $requestId = 'req-fijo';
        $ts = (string) time();
        $firmaDeOtro = 'ts='.$ts.',v1='.hash_hmac('sha256', "id:9999;request-id:{$requestId};ts:{$ts};", self::SECRETO);

        $this->notificar('9001', 'payment', $firmaDeOtro, $requestId)->assertStatus(401);
    }

    // ── AT-13 ────────────────────────────────────────────────────────────────

    public function test_at13_la_misma_notificacion_tres_veces_cambia_el_estado_una_sola_vez(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'approved', (string) $donacion->id, 150.00)
        );

        // Misma firma, mismo ts, mismo request-id: es el mismo reenvío.
        $requestId = 'req-repetido';
        $ts = time();

        foreach (range(1, 3) as $ignorado) {
            $this->notificar('9001', 'payment', null, $requestId, $ts)->assertOk();
        }

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::APROBADO, $donacion->estado);
        $this->assertSame(150.00, (float) $donacion->monto_real);
        $this->assertSame(150.00, (float) $donacion->monto_referencial);

        // Solo la primera llegó a consultar a Mercado Pago.
        $this->assertSame(1, $falso->consultasDePago);
        $this->assertSame(2, WebhookLog::query()->where('status', 'duplicado')->count());
    }

    // ── AT-14 ────────────────────────────────────────────────────────────────

    /** Mercado Pago no garantiza el orden de las notificaciones. */
    public function test_at14_una_notificacion_vieja_no_revierte_un_estado_mas_nuevo(): void
    {
        $donacion = $this->donacionPendiente();

        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(
                9001,
                'approved',
                (string) $donacion->id,
                150.00,
                fechaUltimaActualizacion: '2026-09-27T12:00:00.000-05:00',
            )
        );
        $this->notificar('9001', 'payment', null, 'req-1', time())->assertOk();

        $this->assertSame(EstadoDonacion::APROBADO, $donacion->refresh()->estado);

        // Ahora llega un `pending` del MISMO pago, pero más antiguo.
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(
                9001,
                'pending',
                (string) $donacion->id,
                150.00,
                fechaUltimaActualizacion: '2026-09-27T11:00:00.000-05:00',
            )
        );
        $this->notificar('9001', 'payment', null, 'req-2', time() + 1)->assertOk();

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::APROBADO, $donacion->estado);
        $this->assertSame(150.00, (float) $donacion->monto_real);
    }

    // ── AT-15 ────────────────────────────────────────────────────────────────

    /** Regla 2: el respaldo que el SDK descarta si se lee la propiedad tipada. */
    public function test_at15_encuentra_la_donacion_por_metadata_cuando_falta_external_reference(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(
                9002,
                'approved',
                null,
                200.00,
                ['donation_id' => (string) $donacion->id]
            )
        );

        $this->notificar('9002')->assertOk();

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::APROBADO, $donacion->estado);
        $this->assertSame(200.00, (float) $donacion->monto_real);
    }

    // ── AT-16 ────────────────────────────────────────────────────────────────

    public function test_at16_una_donacion_inexistente_responde_200_y_queda_como_huerfana(): void
    {
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9500, 'approved', '999999999')
        );

        // 200, no 500: reintentar no lo va a arreglar.
        $this->notificar('9500')->assertOk()->assertJson(['success' => true]);

        $bitacora = WebhookLog::query()->sole();
        $this->assertSame('huerfano', $bitacora->status);
        $this->assertTrue($bitacora->procesado);
    }

    // ── AT-17 y AT-18 ────────────────────────────────────────────────────────

    public function test_at17_al_aprobar_se_fija_monto_real_sin_tocar_el_referencial(): void
    {
        $donacion = $this->donacionPendiente(['monto_referencial' => 150.00]);
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'approved', (string) $donacion->id, 137.25)
        );

        $this->notificar('9001')->assertOk();

        $donacion->refresh();

        $this->assertSame(137.25, (float) $donacion->monto_real);
        $this->assertSame(150.00, (float) $donacion->monto_referencial);
    }

    public function test_at18_comision_y_neto_se_pueblan_desde_el_payload_de_mercado_pago(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'approved', (string) $donacion->id, 150.00)
        );

        $this->notificar('9001')->assertOk();

        $donacion->refresh();

        $this->assertSame(5.31, (float) $donacion->mp_fee);
        $this->assertSame(144.69, (float) $donacion->mp_net_received);
        $this->assertSame('visa', $donacion->mp_payment_method_id);
        $this->assertSame('credit_card', $donacion->mp_payment_type_id);
        $this->assertNotNull($donacion->mp_date_approved);
        $this->assertNotNull($donacion->mp_date_last_updated);
        $this->assertFalse($donacion->mp_live_mode);
    }

    /** Las comisiones que paga el donante no restan de lo que recibe la ONG. */
    public function test_solo_cuenta_la_comision_que_soporta_el_cobrador(): void
    {
        $donacion = $this->donacionPendiente();
        $falso = (new ClienteHttpMercadoPagoFalso)->conPago(9001, 'approved', (string) $donacion->id, 150.00);
        $falso->pagos['9001']['fee_details'] = [
            ['type' => 'mercadopago_fee', 'fee_payer' => 'collector', 'amount' => 5.31],
            ['type' => 'financing_fee', 'fee_payer' => 'payer', 'amount' => 12.00],
        ];
        $this->usarMercadoPago($falso);

        $this->notificar('9001')->assertOk();

        $this->assertSame(5.31, (float) $donacion->refresh()->mp_fee);
    }

    // ── AT-20 ────────────────────────────────────────────────────────────────

    /** Nunca se limita a Mercado Pago: un 429 provocaría reintentos. */
    public function test_at20_el_webhook_no_tiene_rate_limit(): void
    {
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        foreach (range(1, 100) as $intento) {
            $respuesta = $this->notificar((string) $intento, 'plan');

            $this->assertNotSame(429, $respuesta->getStatusCode(), "La petición {$intento} fue limitada.");
        }
    }

    // ── Invariantes generales ────────────────────────────────────────────────

    public function test_un_pago_rechazado_no_escribe_monto_real(): void
    {
        $donacion = $this->donacionPendiente();
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9600, 'rejected', (string) $donacion->id)
        );

        $this->notificar('9600')->assertOk();

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->estado);
        $this->assertNull($donacion->monto_real);
        $this->assertFalse($donacion->estado->cuentaParaTotal());
    }

    public function test_un_pago_distinto_sobre_una_donacion_ya_vinculada_es_conflicto(): void
    {
        $donacion = $this->donacionPendiente([
            'estado' => EstadoDonacion::APROBADO,
            'mp_payment_id' => '9001',
            'monto_real' => 150.00,
        ]);

        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(9777, 'approved', (string) $donacion->id, 999.00)
        );

        $this->notificar('9777')->assertOk();

        $donacion->refresh();

        $this->assertSame('9001', $donacion->mp_payment_id);
        $this->assertSame(150.00, (float) $donacion->monto_real);
        $this->assertSame('conflicto', WebhookLog::query()->sole()->status);
    }

    public function test_webhook_logs_nunca_queda_sin_la_fila_cruda(): void
    {
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $this->notificar('123', 'plan')->assertOk();
        $this->notificar('456', 'payment', 'ts=1,v1=basura')->assertStatus(401);

        $this->assertSame(2, WebhookLog::query()->count());

        foreach (WebhookLog::query()->get() as $bitacora) {
            $this->assertIsArray($bitacora->payload);
            $this->assertArrayHasKey('cuerpo', $bitacora->payload);
            $this->assertArrayHasKey('query_string', $bitacora->payload);
        }
    }
}
