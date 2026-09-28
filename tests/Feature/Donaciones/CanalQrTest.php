<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Enums\EstadoFondo;
use App\Enums\ProveedorPago;
use App\Enums\RolAdmin;
use App\Models\AdminUser;
use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\Donaciones\VerificarDonacionQr;
use App\Services\MercadoPago\ConsultarPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Canal QR de Yape y Plin: envío del comprobante y verificación manual.
 *
 * Este canal es donde `monto_referencial` y `monto_real` se ganan el sueldo:
 * alguien declara S/ 100 y transfiere S/ 80, y las dos cifras tienen que
 * convivir para siempre. Media docena de estos casos existen solo para vigilar
 * que nadie las confunda.
 *
 * El otro hilo es el comprobante: es una captura bancaria con el nombre y a
 * menudo el saldo de una persona. No puede alcanzarse sin sesión de admin, ni
 * por URL directa, ni conservando el nombre del archivo original.
 */
final class CanalQrTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const RUTA = '/api/donaciones/qr';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
        Storage::fake('comprobantes');

        config([
            'donaciones.monto_minimo_qr' => 10,
            'donaciones.monto_maximo' => 10000,
            'donaciones.comprobante.max_mb' => 10,
        ]);
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    private function admin(RolAdmin $rol = RolAdmin::SUPERADMIN, string $usuario = 'jefa'): AdminUser
    {
        return AdminUser::query()->create([
            'username' => $usuario,
            'password' => 'ClaveDePruebaLarga2026',
            'role' => $rol,
        ]);
    }

    /** @return array<string, mixed> */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'telefono' => '+51 999 888 777',
            'tipo_aportante' => 'persona',
            'fondo_id' => $this->fondoDePrueba()->id,
            'monto' => 100,
            'moneda' => 'PEN',
            'proveedor_pago' => 'yape_qr',
            'referencia_pago' => '000123456',
            'acepta_terminos' => '1',
            'visible_publico' => '1',
        ], $extra);
    }

    private function captura(string $nombre = 'yape.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, 800, 1400);
    }

    /** Un PDF de verdad: la firma importa, la regla la comprueba. */
    private function pdf(string $nombre = 'constancia.pdf', int $kilobytes = 40): UploadedFile
    {
        $cuerpo = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"
            .str_repeat("% relleno para dar cuerpo al archivo de prueba\n", 40);

        return UploadedFile::fake()->createWithContent($nombre, str_pad($cuerpo, $kilobytes * 1024, ' '));
    }

    /** Envía el formulario completo con su archivo. */
    private function enviar(?UploadedFile $comprobante = null, array $extra = [])
    {
        return $this->postJson(self::RUTA, $this->formulario($extra) + [
            'comprobante' => $comprobante ?? $this->captura(),
        ]);
    }

    private function donacionPendienteQr(array $extra = []): Donacion
    {
        $this->enviar(null, $extra)->assertCreated();

        return Donacion::query()->latest('id')->firstOrFail();
    }

    // ── AT-100 a AT-103: el archivo ──────────────────────────────────────────

    /**
     * El tipo se lee del CONTENIDO. `mimes:jpg` de Laravel mira la extensión y
     * lo que declara el navegador, y las dos cosas las controla quien sube.
     */
    public function test_at100_un_ejecutable_renombrado_a_jpg_es_rechazado(): void
    {
        $this->fondoDePrueba();

        $falso = UploadedFile::fake()->createWithContent(
            'captura.jpg',
            "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 512)   // cabecera de un .exe
        );

        $this->enviar($falso)
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Donacion::query()->count());
        $this->assertSame([], Storage::disk('comprobantes')->allFiles());
    }

    public function test_at101_un_archivo_de_doce_megas_es_rechazado(): void
    {
        $this->fondoDePrueba();

        $pesado = UploadedFile::fake()->image('enorme.jpg')->size(12 * 1024);

        $this->enviar($pesado)->assertStatus(422);

        $this->assertSame(0, Donacion::query()->count());
    }

    /** Los bancos peruanos entregan la constancia en PDF. */
    public function test_at102_un_pdf_valido_es_aceptado(): void
    {
        $this->fondoDePrueba();

        $this->enviar($this->pdf())->assertCreated();

        $donacion = Donacion::query()->latest('id')->firstOrFail();

        $this->assertSame('application/pdf', $donacion->comprobante_mime);
        $this->assertStringEndsWith('.pdf', (string) $donacion->comprobante_path);
    }

    /** Un SVG es una imagen, pero puede llevar JavaScript dentro. */
    public function test_at103_un_svg_es_rechazado_aunque_sea_una_imagen(): void
    {
        $this->fondoDePrueba();

        $svg = UploadedFile::fake()->createWithContent(
            'comprobante.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->enviar($svg)->assertStatus(422);

        $this->assertSame(0, Donacion::query()->count());
    }

    // ── AT-104 a AT-106: cómo nace la donación ───────────────────────────────

    public function test_at104_la_donacion_nace_pendiente_por_qr_y_sin_monto_real(): void
    {
        $fondo = $this->fondoDePrueba();

        $this->enviar()->assertCreated()->assertJsonPath('success', true);

        $donacion = Donacion::query()->latest('id')->firstOrFail();

        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->estado);
        $this->assertSame(CanalPago::QR_MANUAL, $donacion->canal_pago);
        $this->assertSame(ProveedorPago::YAPE_QR, $donacion->proveedor_pago);
        $this->assertNull($donacion->monto_real);
        $this->assertSame(100.0, (float) $donacion->monto_referencial);
        $this->assertSame('000123456', $donacion->referencia_pago);

        // Nada entra en las cifras públicas hasta que una persona lo confirme.
        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
    }

    /** El nombre lo ponemos nosotros: el del usuario puede traer cualquier cosa. */
    public function test_at105_el_nombre_original_del_archivo_no_se_conserva(): void
    {
        $this->fondoDePrueba();

        $this->enviar($this->captura('mi-recibo-personal-2026.jpg'))->assertCreated();

        $ruta = (string) Donacion::query()->latest('id')->firstOrFail()->comprobante_path;

        $this->assertStringNotContainsString('mi-recibo-personal', $ruta);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.jpg$#', $ruta);
    }

    /**
     * El disco es PRIVADO: storage/app/private/comprobantes. Nada de lo que
     * suba un donante puede terminar bajo public/, donde Apache lo serviría a
     * quien adivinara la URL.
     */
    public function test_at106_el_comprobante_no_queda_bajo_public(): void
    {
        $this->fondoDePrueba();
        $this->enviar()->assertCreated();

        $ruta = (string) Donacion::query()->latest('id')->firstOrFail()->comprobante_path;

        $this->assertTrue(Storage::disk('comprobantes')->exists($ruta));
        $this->assertFileDoesNotExist(public_path($ruta));
        $this->assertFileDoesNotExist(public_path('storage/'.$ruta));

        // Y el disco configurado no apunta a public/ por descuido.
        $raiz = str_replace('\\', '/', (string) config('filesystems.disks.comprobantes.root'));
        $this->assertStringNotContainsString('/public/', $raiz.'/');
    }

    public function test_at107_pedir_el_comprobante_sin_sesion_de_admin_no_lo_sirve(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $respuesta = $this->get(route('admin.verificacion.comprobante', $donacion));

        // Sin sesión el middleware redirige al acceso. Lo que NO puede pasar
        // es que devuelva 200 con el archivo dentro.
        $this->assertContains($respuesta->status(), [302, 401, 403, 404]);
        $this->assertNotSame(200, $respuesta->status());
    }

    public function test_un_admin_con_sesion_si_recibe_el_comprobante(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.verificacion.comprobante', $donacion))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    // ── AT-108: quién puede verificar ────────────────────────────────────────

    /** Revisar comprobantes es trabajo operativo, no una decisión de gobierno. */
    public function test_at108_un_editor_si_puede_verificar(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $this->actingAs($this->admin(RolAdmin::EDITOR, 'redactora'), 'admin')
            ->post(route('admin.verificacion.verificar', $donacion), [
                'decision' => 'aprobar',
                'monto_real' => 100,
            ])
            ->assertRedirect();

        $this->assertSame(EstadoDonacion::APROBADO, $donacion->refresh()->estado);
        $this->assertSame(100.0, (float) $fondo->refresh()->recaudado);
    }

    public function test_la_cola_es_visible_para_los_dos_roles(): void
    {
        $this->fondoDePrueba();
        $this->donacionPendienteQr();

        $this->actingAs($this->admin(RolAdmin::EDITOR, 'redactora'), 'admin')
            ->get(route('admin.verificacion.index'))
            ->assertOk()
            ->assertSee('María Pérez');
    }

    // ── AT-109 a AT-113: la decisión ─────────────────────────────────────────

    /**
     * El caso que justifica que existan dos columnas de monto: declaró 100,
     * transfirió 80. La segunda cifra no pisa a la primera.
     */
    public function test_at109_aprobar_con_monto_distinto_guarda_el_real_sin_tocar_el_declarado(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.verificacion.verificar', $donacion), [
                'decision' => 'aprobar',
                'monto_real' => 80,
                'confirmo_monto' => '1',
            ])
            ->assertRedirect();

        $donacion->refresh();

        $this->assertSame(80.0, (float) $donacion->monto_real);
        $this->assertSame(100.0, (float) $donacion->monto_referencial);
    }

    public function test_at110_al_aprobar_el_contador_sube_el_monto_real(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.verificacion.verificar', $donacion), [
                'decision' => 'aprobar',
                'monto_real' => 80,
                'confirmo_monto' => '1',
            ]);

        $fondo->refresh();

        $this->assertSame(80.0, (float) $fondo->recaudado, 'Subió lo declarado en vez de lo real.');
        $this->assertSame(1, $fondo->donaciones_count);
    }

    public function test_at111_al_rechazar_no_se_mueve_nada_y_se_guarda_el_motivo(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.verificacion.verificar', $donacion), [
                'decision' => 'rechazar',
                'motivo' => 'La captura no se lee.',
            ])
            ->assertRedirect();

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->estado);
        $this->assertSame('La captura no se lee.', $donacion->motivo_rechazo);
        $this->assertNull($donacion->monto_real);

        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
    }

    public function test_at112_verificado_por_y_verificado_at_se_llenan_en_ambos_casos(): void
    {
        $this->fondoDePrueba();
        $admin = $this->admin();

        $aprobada = $this->donacionPendienteQr();
        $this->actingAs($admin, 'admin')->post(route('admin.verificacion.verificar', $aprobada), [
            'decision' => 'aprobar',
            'monto_real' => 100,
        ]);

        $rechazada = $this->donacionPendienteQr(['correo' => 'otra@ejemplo.com']);
        $this->actingAs($admin, 'admin')->post(route('admin.verificacion.verificar', $rechazada), [
            'decision' => 'rechazar',
            'motivo' => 'No coincide con ningún movimiento.',
        ]);

        foreach ([$aprobada, $rechazada] as $donacion) {
            $donacion->refresh();

            $this->assertSame($admin->id, $donacion->verificado_por);
            $this->assertNotNull($donacion->verificado_at);
        }
    }

    /**
     * Dos administradores abriendo el mismo comprobante es un escenario real:
     * la cola es compartida. El contador sube UNA vez.
     */
    public function test_at113_aprobar_dos_veces_sube_el_contador_una_sola_vez(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();

        $datos = ['decision' => 'aprobar', 'monto_real' => 100];

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.verificacion.verificar', $donacion), $datos);

        // El segundo llega tarde: la Policy ya no lo autoriza porque la
        // donación dejó de estar pendiente.
        $this->actingAs($this->admin(RolAdmin::SUPERADMIN, 'segundo'), 'admin')
            ->post(route('admin.verificacion.verificar', $donacion->fresh()), $datos)
            ->assertForbidden();

        $fondo->refresh();

        $this->assertSame(100.0, (float) $fondo->recaudado);
        $this->assertSame(1, $fondo->donaciones_count);
    }

    /**
     * El servicio, llamado directamente, tampoco aprueba dos veces: la Policy
     * es la primera puerta, pero el bloqueo dentro de la transacción es la que
     * aguanta cuando dos peticiones entran a la vez.
     */
    public function test_el_servicio_rechaza_una_segunda_decision_sobre_la_misma_donacion(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendienteQr();
        $admin = $this->admin();

        $servicio = $this->app->make(VerificarDonacionQr::class);

        $primera = $servicio->aprobar($donacion, $admin, 100.0);
        $segunda = $servicio->aprobar($donacion->fresh(), $admin, 100.0);

        $this->assertTrue($primera->fueDecidida());
        $this->assertTrue($segunda->yaEstabaVerificada());
        $this->assertSame(100.0, (float) $fondo->refresh()->recaudado);
    }

    // ── AT-114 a AT-116: validación y privacidad ─────────────────────────────

    public function test_at114_por_debajo_del_minimo_del_canal_qr_devuelve_422(): void
    {
        $this->fondoDePrueba();

        // 6 pasa el mínimo de tarjeta (5) pero no el del canal manual (10).
        $this->enviar(null, ['monto' => 6])->assertStatus(422);

        $this->assertSame(0, Donacion::query()->count());
    }

    public function test_at115_un_fondo_pausado_devuelve_422(): void
    {
        $pausado = $this->fondoDePrueba(['slug' => 'pausado', 'estado' => EstadoFondo::PAUSADO]);

        $this->enviar(null, ['fondo_id' => $pausado->id])->assertStatus(422);

        $this->assertSame(0, Donacion::query()->count());
    }

    /** Revelaría la estructura del disco privado. */
    public function test_at116_la_respuesta_publica_no_contiene_la_ruta_del_comprobante(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->enviar()->assertCreated();
        $cuerpo = $respuesta->getContent();

        $ruta = (string) Donacion::query()->latest('id')->firstOrFail()->comprobante_path;

        $this->assertStringNotContainsString('comprobante_path', (string) $cuerpo);
        $this->assertStringNotContainsString($ruta, (string) $cuerpo);
        $this->assertSame(
            ['success', 'donacion_id', 'mensaje'],
            array_keys($respuesta->json())
        );
    }

    // ── AT-117 a AT-119: una sola implementación de los contadores ───────────

    /**
     * El mismo servicio, llamado desde los dos caminos, deja el mismo número.
     * Si algún día alguien duplica la lógica, este caso se pone rojo.
     */
    public function test_at117_los_contadores_se_mueven_igual_por_los_dos_canales(): void
    {
        $fondo = $this->fondoDePrueba();

        // Camino 1: verificación manual del canal QR.
        $porQr = $this->donacionPendienteQr();
        $this->app->make(VerificarDonacionQr::class)->aprobar($porQr, $this->admin(), 150.0);

        $trasQr = (float) $fondo->refresh()->recaudado;
        $cuentaTrasQr = (int) $fondo->donaciones_count;

        // Camino 2: reconciliación de Mercado Pago, sobre el mismo fondo.
        $this->aprobarPorMercadoPago($this->donacionPendiente(['fondo_id' => $fondo->id]), 150.0);

        $fondo->refresh();

        $this->assertSame(150.0, $trasQr);
        $this->assertSame(1, $cuentaTrasQr);
        $this->assertSame(300.0, (float) $fondo->recaudado, 'Los dos canales no suman igual.');
        $this->assertSame(2, $fondo->donaciones_count);
    }

    /**
     * AT-119. La vista de conciliación compara el contador denormalizado con la
     * suma real de las donaciones. Si la extracción hubiera cambiado algo, la
     * diferencia dejaría de ser cero justo al mezclar canales.
     */
    public function test_at119_la_vista_de_conciliacion_cuadra_mezclando_los_dos_canales(): void
    {
        $fondo = $this->fondoDePrueba();
        $admin = $this->admin();
        $servicio = $this->app->make(VerificarDonacionQr::class);

        // Dos por QR: una aprobada con monto distinto al declarado, una rechazada.
        $aprobada = $this->donacionPendienteQr();
        $servicio->aprobar($aprobada, $admin, 80.0);

        $rechazada = $this->donacionPendienteQr(['correo' => 'otra@ejemplo.com']);
        $servicio->rechazar($rechazada, $admin, 'El monto no coincide.');

        // Y una por Mercado Pago.
        $this->aprobarPorMercadoPago($this->donacionPendiente(['fondo_id' => $fondo->id]), 200.0);

        $fila = DB::table('v_fondos_conciliacion')->where('fondo_id', $fondo->id)->first();

        $this->assertNotNull($fila);
        $this->assertSame(0.0, round((float) $fila->diferencia, 2), 'El contador no cuadra con las donaciones reales.');
        $this->assertSame(280.0, round((float) $fila->total_real, 2));
        $this->assertSame(2, (int) $fila->donaciones_aprobadas);
    }

    /** Aprueba una donación por el camino de Mercado Pago, como el webhook. */
    private function aprobarPorMercadoPago(Donacion $donacion, float $monto): void
    {
        MercadoPagoConfig::setHttpClient(
            (new ClienteHttpMercadoPagoFalso)
                ->conPago(8800 + (int) $donacion->id, 'approved', (string) $donacion->id, $monto)
        );
        $this->app->forgetInstance(PaymentClient::class);

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
        ]);

        $pago = $this->app->make(ConsultarPago::class)(
            (string) (8800 + (int) $donacion->id)
        );

        $this->app->make(ReconciliarDonacion::class)($pago, 'webhook');
    }

    protected function tearDown(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);

        parent::tearDown();
    }
}
