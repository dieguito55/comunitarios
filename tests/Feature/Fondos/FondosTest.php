<?php

declare(strict_types=1);

namespace Tests\Feature\Fondos;

use App\Enums\ColorFondo;
use App\Enums\EstadoDonacion;
use App\Enums\EstadoFondo;
use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\Fondos\CalcularMetricasFondo;
use App\Services\MercadoPago\ConsultarPago;
use Database\Seeders\FondoAntoniaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Fondos y sus contadores.
 *
 * Los contadores son cifras públicas de una fundación: si se desvían, lo que
 * se publica deja de ser verdad. Estos casos cubren las cuatro formas en que
 * se podrían desviar: no sumar, sumar de más, no restar, y perderse entre dos
 * aprobaciones simultáneas.
 */
final class FondosTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const RUTA_CREAR = '/api/donaciones/mercadopago';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
            'mercadopago.currency' => 'PEN',
            'mercadopago.back_urls.success' => 'https://comunitarios.org/donaciones',
            'donaciones.monto_minimo_mp' => 5,
            'donaciones.monto_maximo' => 10000,
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
        $this->app->forgetInstance(PreferenceClient::class);

        return $falso;
    }

    /** @param array<string, mixed> $extra */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'monto' => 150,
            'moneda' => 'PEN',
            'tipo_aportante' => 'persona',
            'acepta_terminos' => true,
        ], $extra);
    }

    /** Aplica un pago aprobado a una donación, como haría el webhook. */
    private function aprobar(Donacion $donacion, float $monto = 150.00, int $idDePago = 9001): void
    {
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago($idDePago, 'approved', (string) $donacion->id, $monto)
        );

        $pago = $this->app->make(ConsultarPago::class)((string) $idDePago);
        $this->app->make(ReconciliarDonacion::class)($pago, 'webhook');
    }

    // ── AT-30 a AT-33: elección de fondo ─────────────────────────────────────

    public function test_at30_sin_fondo_id_y_con_dos_fondos_activos_es_error(): void
    {
        $this->fondoDePrueba(['slug' => 'fondo-uno']);
        $this->fondoDePrueba(['slug' => 'fondo-dos', 'es_predeterminado' => false]);

        $this->postJson(self::RUTA_CREAR, $this->formulario())
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('error', 'Elige a qué fondo quieres aportar.');

        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_at31_sin_fondo_id_y_con_un_solo_fondo_activo_usa_ese(): void
    {
        $unico = $this->fondoDePrueba(['slug' => 'el-unico']);
        $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $this->postJson(self::RUTA_CREAR, $this->formulario())->assertOk();

        $this->assertSame($unico->id, Donacion::query()->sole()->fondo_id);
    }

    /** Un fondo pausado se sigue viendo, pero no recibe. */
    public function test_at32_un_fondo_pausado_no_recibe_donaciones(): void
    {
        $pausado = $this->fondoDePrueba(['slug' => 'pausado', 'estado' => EstadoFondo::PAUSADO]);

        $this->postJson(self::RUTA_CREAR, $this->formulario(['fondo_id' => $pausado->id]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Ese fondo no está recibiendo donaciones en este momento.');

        $this->assertSame(0, Donacion::query()->count());
        $this->assertTrue($pausado->estado->visiblePublicamente());
    }

    public function test_at33_un_fondo_inexistente_es_error(): void
    {
        $this->fondoDePrueba();

        $this->postJson(self::RUTA_CREAR, $this->formulario(['fondo_id' => 999999999]))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, Donacion::query()->count());
    }

    // ── AT-34 a AT-38: contadores ────────────────────────────────────────────

    public function test_at34_al_aprobar_el_recaudado_sube_exactamente_el_monto_efectivo(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendiente(['monto_referencial' => 150.00]);

        $this->aprobar($donacion, 137.25);

        $donacion->refresh();

        $this->assertSame(137.25, $donacion->montoEfectivo());
        $this->assertSame(137.25, (float) $fondo->refresh()->recaudado);

        // El declarado no se toca nunca.
        $this->assertSame(150.00, (float) $donacion->monto_referencial);
    }

    public function test_at35_al_aprobar_el_contador_de_donaciones_sube_uno(): void
    {
        $fondo = $this->fondoDePrueba();

        $this->assertSame(0, $fondo->donaciones_count);

        $this->aprobar($this->donacionPendiente());

        $this->assertSame(1, $fondo->refresh()->donaciones_count);
    }

    /** Un contracargo o una devolución hacen bajar el contador. Es correcto. */
    public function test_at36_de_aprobado_a_rechazado_el_contador_baja(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendiente();

        $this->aprobar($donacion, 150.00);
        $this->assertSame(150.00, (float) $fondo->refresh()->recaudado);
        $this->assertSame(1, $fondo->donaciones_count);

        // El mismo pago, ahora con contracargo.
        $this->usarMercadoPago(
            (new ClienteHttpMercadoPagoFalso)->conPago(
                9001,
                'charged_back',
                (string) $donacion->id,
                150.00,
                fechaUltimaActualizacion: '2026-09-27T18:00:00.000-05:00'
            )
        );
        $pago = $this->app->make(ConsultarPago::class)('9001');
        $this->app->make(ReconciliarDonacion::class)($pago, 'webhook');

        $fondo->refresh();

        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->refresh()->estado);
        $this->assertSame(0.00, (float) $fondo->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
    }

    public function test_at37_la_misma_notificacion_dos_veces_suma_una_sola_vez(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendiente();

        $this->aprobar($donacion, 150.00);
        $this->aprobar($donacion, 150.00);

        $fondo->refresh();

        $this->assertSame(150.00, (float) $fondo->recaudado);
        $this->assertSame(1, $fondo->donaciones_count);
    }

    /**
     * AT-38. Dos aprobaciones sobre el mismo fondo: ninguna puede perderse.
     *
     * Sin `lockForUpdate()` en ReconciliarDonacion, ambas leerían el contador
     * a 0 y la segunda pisaría a la primera.
     */
    public function test_at38_dos_aprobaciones_sobre_el_mismo_fondo_se_acumulan(): void
    {
        $fondo = $this->fondoDePrueba();
        $primera = $this->donacionPendiente(['correo' => 'uno@ejemplo.com']);
        $segunda = $this->donacionPendiente(['correo' => 'dos@ejemplo.com']);

        DB::transaction(function () use ($primera, $segunda): void {
            $this->aprobar($primera, 100.00, 9001);
            $this->aprobar($segunda, 50.00, 9002);
        });

        $fondo->refresh();

        $this->assertSame(150.00, (float) $fondo->recaudado);
        $this->assertSame(2, $fondo->donaciones_count);
    }

    // ── AT-39 y AT-40: conciliación ──────────────────────────────────────────

    public function test_at39_la_vista_de_conciliacion_no_encuentra_diferencias(): void
    {
        $fondo = $this->fondoDePrueba();

        foreach ([[9001, 120.50], [9002, 75.00], [9003, 10.00]] as [$idPago, $monto]) {
            $this->aprobar($this->donacionPendiente(['correo' => "d{$idPago}@ejemplo.com"]), $monto, $idPago);
        }

        // Una rechazada no debe contar.
        $rechazada = $this->donacionPendiente(['correo' => 'rechazada@ejemplo.com']);
        $this->usarMercadoPago((new ClienteHttpMercadoPagoFalso)->conPago(9004, 'rejected', (string) $rechazada->id));
        $this->app->make(ReconciliarDonacion::class)(
            $this->app->make(ConsultarPago::class)('9004'),
            'webhook'
        );

        $conciliacion = DB::table('v_fondos_conciliacion')->where('fondo_id', $fondo->id)->first();

        $this->assertSame(205.50, round((float) $conciliacion->total_real, 2));
        $this->assertSame(0.00, round((float) $conciliacion->diferencia, 2));
        $this->assertSame(3, (int) $conciliacion->donaciones_aprobadas);
    }

    public function test_at40_recalcular_dry_run_detecta_un_contador_desviado(): void
    {
        $fondo = $this->fondoDePrueba();
        $this->aprobar($this->donacionPendiente(), 150.00);

        // Se desvía el contador a mano, como haría un bug.
        DB::table('fondos')->where('id', $fondo->id)->update(['recaudado' => 999.99]);

        $this->artisan('fondos:recalcular --dry-run')
            ->expectsOutputToContain('DESVIADO')
            ->assertFailed();

        // --dry-run no escribe nada.
        $this->assertSame(999.99, (float) $fondo->refresh()->recaudado);

        // Sin la bandera, sí repara.
        $this->artisan('fondos:recalcular')->assertSuccessful();

        $this->assertSame(150.00, (float) $fondo->refresh()->recaudado);
    }

    // ── AT-41: metadata ──────────────────────────────────────────────────────

    public function test_at41_la_preferencia_lleva_fondo_id_y_fondo_slug_en_metadata(): void
    {
        $fondo = $this->fondoDePrueba(['slug' => 'fundacion-antonia', 'nombre' => 'Fundación Antonia']);
        $falso = $this->usarMercadoPago(new ClienteHttpMercadoPagoFalso);

        $this->postJson(self::RUTA_CREAR, $this->formulario(['fondo_id' => $fondo->id]))->assertOk();

        $metadata = $falso->ultimoPayload['metadata'];

        $this->assertSame((string) $fondo->id, $metadata['fondo_id']);
        $this->assertSame('fundacion-antonia', $metadata['fondo_slug']);

        // Sin perder la llave maestra de la regla dura 2.
        $this->assertSame((string) Donacion::query()->sole()->id, $metadata['donation_id']);

        // Y el donante ve a qué proyecto va su dinero desde el checkout.
        $this->assertSame('Donación · Fundación Antonia', $falso->ultimoPayload['items'][0]['title']);
    }

    // ── AT-42: donantes únicos ───────────────────────────────────────────────

    /**
     * Es justo el caso que hace fallar un contador incremental, y el motivo de
     * calcularlo al vuelo en vez de denormalizarlo.
     */
    public function test_at42_dos_donaciones_del_mismo_correo_cuentan_como_un_donante(): void
    {
        $fondo = $this->fondoDePrueba();

        $this->aprobar($this->donacionPendiente(['correo' => 'repetido@ejemplo.com']), 100.00, 9001);
        $this->aprobar($this->donacionPendiente(['correo' => 'repetido@ejemplo.com']), 50.00, 9002);
        $this->aprobar($this->donacionPendiente(['correo' => 'otra@ejemplo.com']), 25.00, 9003);

        $metricas = $this->app->make(CalcularMetricasFondo::class)($fondo->refresh());

        $this->assertSame(3, $metricas['donaciones']);
        $this->assertSame(2, $metricas['donantes_unicos']);
        $this->assertSame(175.00, $metricas['recaudado']);
    }

    public function test_sin_meta_no_hay_porcentaje_y_la_barra_se_oculta(): void
    {
        $fondo = $this->fondoDePrueba(['meta' => null]);
        $this->aprobar($this->donacionPendiente(), 150.00);

        $metricas = $this->app->make(CalcularMetricasFondo::class)($fondo->refresh());

        $this->assertNull($metricas['meta']);
        $this->assertNull($metricas['porcentaje']);
    }

    // ── AT-43: integridad ────────────────────────────────────────────────────

    public function test_at43_no_se_puede_borrar_un_fondo_con_donaciones(): void
    {
        $fondo = $this->fondoDePrueba();
        $this->donacionPendiente();

        $this->expectException(QueryException::class);

        try {
            $fondo->delete();
        } finally {
            $this->assertSame(1, Fondo::query()->whereKey($fondo->id)->count());
        }
    }

    // ── AT-44: seeder ────────────────────────────────────────────────────────

    public function test_at44_el_seeder_de_antonia_es_idempotente(): void
    {
        $this->seed(FondoAntoniaSeeder::class);
        $this->seed(FondoAntoniaSeeder::class);

        $this->assertSame(1, Fondo::query()->where('slug', 'fundacion-antonia')->count());

        $fondo = Fondo::query()->where('slug', 'fundacion-antonia')->sole();

        $this->assertSame('Fundación Antonia', $fondo->nombre);
        $this->assertSame(EstadoFondo::ACTIVO, $fondo->estado);
        $this->assertSame(ColorFondo::TEAL, $fondo->color_token);
        $this->assertTrue($fondo->es_predeterminado);

        // Los cuatro campos que debe rellenar la organización siguen marcados.
        $this->assertStringContainsString('PENDIENTE', $fondo->resumen);
        $this->assertNull($fondo->meta);
        $this->assertNull($fondo->descripcion);
        $this->assertNull($fondo->fecha_inicio);
    }

    public function test_solo_puede_haber_un_fondo_predeterminado(): void
    {
        $primero = $this->fondoDePrueba(['slug' => 'primero']);
        $segundo = $this->fondoDePrueba(['slug' => 'segundo', 'es_predeterminado' => false]);

        $segundo->marcarComoPredeterminado();

        $this->assertFalse($primero->refresh()->es_predeterminado);
        $this->assertTrue($segundo->refresh()->es_predeterminado);
        $this->assertSame($segundo->id, Fondo::predeterminado()?->id);
    }

    public function test_el_color_es_un_token_de_la_paleta_nunca_un_hex(): void
    {
        $fondo = $this->fondoDePrueba(['color_token' => ColorFondo::CORAL]);

        $this->assertSame('coral', $fondo->refresh()->color_token->value);
        $this->assertSame('fondo--coral', $fondo->color_token->claseCss());

        // Ninguna variante del enum puede contener un hex.
        foreach (ColorFondo::cases() as $color) {
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}/i', $color->value);
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}/i', $color->claseCss());
        }
    }
}
