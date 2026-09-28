<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Enums\EstadoDonacion;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\Donaciones\ResultadoReconciliacion;
use App\Services\MercadoPago\ConsultarPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * ReconciliarDonacion es el corazón compartido por las tres redes de seguridad.
 *
 * Si el webhook y la reconciliación pudieran llegar a resultados distintos con
 * el mismo pago, el sistema tendría dos verdades sobre el mismo dinero. Estos
 * casos existen para que eso no pueda pasar desapercibido.
 */
final class ReconciliarDonacionCompartidaTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** @return array<string, mixed> */
    private function pagoDe(string $referencia, string $estado = 'approved', int $idDePago = 9001, float $monto = 150.00): array
    {
        $falso = (new ClienteHttpMercadoPagoFalso)->conPago($idDePago, $estado, $referencia, $monto);
        MercadoPagoConfig::setHttpClient($falso);
        $this->app->forgetInstance(PaymentClient::class);

        return $this->app->make(ConsultarPago::class)((string) $idDePago);
    }

    /**
     * Mismo pago, mismos dos orígenes, mismo resultado en la base de datos.
     */
    public function test_el_resultado_es_identico_venga_del_webhook_o_de_la_reconciliacion(): void
    {
        $porWebhook = $this->donacionPendiente();
        $porReconciliacion = $this->donacionPendiente();

        $servicio = $this->app->make(ReconciliarDonacion::class);

        $servicio($this->pagoDe((string) $porWebhook->id, idDePago: 9001), 'webhook');
        $servicio($this->pagoDe((string) $porReconciliacion->id, idDePago: 9002), 'reconciliacion');

        // mp_payment_id queda fuera a proposito: cada donacion tiene el suyo,
        // porque el indice unico impide acreditar el mismo pago dos veces.
        $columnas = [
            'estado', 'mp_status_detail', 'mp_payment_method_id',
            'mp_payment_type_id', 'monto_real', 'monto_referencial', 'mp_fee',
            'mp_net_received', 'mp_live_mode', 'mp_date_approved', 'mp_date_last_updated',
        ];

        $porWebhook->refresh();
        $porReconciliacion->refresh();

        foreach ($columnas as $columna) {
            $this->assertEquals(
                $porWebhook->getAttribute($columna),
                $porReconciliacion->getAttribute($columna),
                "La columna {$columna} difiere según el origen."
            );
        }

        $this->assertSame(EstadoDonacion::APROBADO, $porWebhook->estado);
        $this->assertSame('9001', $porWebhook->mp_payment_id);
        $this->assertSame('9002', $porReconciliacion->mp_payment_id);
    }

    public function test_el_segundo_origen_sobre_la_misma_donacion_no_duplica_nada(): void
    {
        $donacion = $this->donacionPendiente();
        $servicio = $this->app->make(ReconciliarDonacion::class);

        $primero = $servicio($this->pagoDe((string) $donacion->id), 'webhook');
        $segundo = $servicio($this->pagoDe((string) $donacion->id), 'reconciliacion');

        $this->assertSame(ResultadoReconciliacion::ACTUALIZADO, $primero->resultado);
        $this->assertSame(ResultadoReconciliacion::SIN_CAMBIOS, $segundo->resultado);

        $this->assertSame(150.00, (float) $donacion->refresh()->monto_real);
    }

    public function test_un_pago_sin_donacion_es_huerfano_y_no_lanza(): void
    {
        $resultado = $this->app->make(ReconciliarDonacion::class)(
            $this->pagoDe('999999999'),
            'auditoria'
        );

        $this->assertSame(ResultadoReconciliacion::HUERFANO, $resultado->resultado);
        $this->assertNull($resultado->donacion);
    }

    public function test_una_notificacion_mas_antigua_se_marca_obsoleta(): void
    {
        $donacion = $this->donacionPendiente();
        $servicio = $this->app->make(ReconciliarDonacion::class);

        $nuevo = $this->pagoDe((string) $donacion->id);
        $nuevo['date_last_updated'] = '2026-09-27T12:00:00.000-05:00';
        $servicio($nuevo, 'webhook');

        $viejo = $this->pagoDe((string) $donacion->id, 'pending');
        $viejo['date_last_updated'] = '2026-09-27T11:00:00.000-05:00';
        $resultado = $servicio($viejo, 'reconciliacion');

        $this->assertSame(ResultadoReconciliacion::OBSOLETO, $resultado->resultado);
        $this->assertSame(EstadoDonacion::APROBADO, $donacion->refresh()->estado);
    }
}
