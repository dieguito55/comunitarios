<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Enums\RolAdmin;
use App\Models\AdminUser;
use App\Models\Donacion;
use App\Services\Donaciones\CalcularPendientes;
use App\Services\Donaciones\VerificarDonacionQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Deshacer una verificación, y enseñar el dinero que todavía no cuenta.
 *
 * Dos asuntos con el mismo hilo: **la cifra pública nunca miente**. Ni sumando
 * un dinero que nadie ha comprobado, ni dejando sumado un dinero que se aprobó
 * por error.
 */
final class ReversionYPendientesTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
        Storage::fake('comprobantes');

        config([
            'donaciones.monto_minimo_qr' => 10,
            'donaciones.monto_maximo' => 10000,
        ]);
    }

    private function admin(RolAdmin $rol = RolAdmin::SUPERADMIN, string $usuario = 'jefa'): AdminUser
    {
        return AdminUser::query()->create([
            'username' => $usuario,
            'password' => 'ClaveDePruebaLarga2026',
            'role' => $rol,
        ]);
    }

    /** Una donación por QR, pendiente, creada por la vía pública. */
    private function porQr(float $monto = 100, array $extra = []): Donacion
    {
        $this->postJson('/api/donaciones/qr', array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'tipo_aportante' => 'persona',
            'fondo_id' => $this->fondoDePrueba()->id,
            'monto' => $monto,
            'moneda' => 'PEN',
            'proveedor_pago' => 'yape_qr',
            'acepta_terminos' => '1',
            'visible_publico' => '1',
            'comprobante' => UploadedFile::fake()->image('yape.jpg', 600, 1000),
        ], $extra))->assertCreated();

        return Donacion::query()->latest('id')->firstOrFail();
    }

    private function pendientes(): CalcularPendientes
    {
        return $this->app->make(CalcularPendientes::class);
    }

    // ── 1. Revertir ──────────────────────────────────────────────────────────

    public function test_revertir_una_aprobada_devuelve_el_fondo_a_su_recaudado_anterior(): void
    {
        $fondo = $this->fondoDePrueba();
        $admin = $this->admin();
        $servicio = $this->app->make(VerificarDonacionQr::class);

        $donacion = $this->porQr(100);
        $servicio->aprobar($donacion, $admin, 80.0);

        $this->assertSame(80.0, (float) $fondo->refresh()->recaudado);
        $this->assertSame(1, $fondo->donaciones_count);

        $servicio->revertir($donacion->fresh(), $admin, 'Aprobada con el comprobante de otra persona.');

        $fondo->refresh();

        $this->assertSame(0.0, (float) $fondo->recaudado, 'El recaudado no volvió a su valor anterior.');
        $this->assertSame(0, $fondo->donaciones_count);
    }

    /**
     * El detalle que hace falta comprobar: se resta lo que se SUMÓ —el monto
     * real— y no lo que el donante declaró. Si se restara lo declarado, el
     * fondo quedaría descuadrado justo al intentar corregirlo.
     */
    public function test_al_revertir_se_resta_el_monto_real_no_el_declarado(): void
    {
        $fondo = $this->fondoDePrueba();
        $admin = $this->admin();
        $servicio = $this->app->make(VerificarDonacionQr::class);

        // El fondo ya tiene algo de otra donación aprobada.
        $otra = $this->porQr(50, ['correo' => 'otra@ejemplo.com']);
        $servicio->aprobar($otra, $admin, 50.0);

        $donacion = $this->porQr(100);
        $servicio->aprobar($donacion, $admin, 80.0);

        $this->assertSame(130.0, (float) $fondo->refresh()->recaudado);

        $resultado = $servicio->revertir($donacion->fresh(), $admin, 'Se aprobó por equivocación.');

        $this->assertSame(-80.0, $resultado->movimiento);
        $this->assertSame(50.0, (float) $fondo->refresh()->recaudado);
    }

    public function test_revertir_una_rechazada_no_mueve_nada(): void
    {
        $fondo = $this->fondoDePrueba();
        $admin = $this->admin();
        $servicio = $this->app->make(VerificarDonacionQr::class);

        $donacion = $this->porQr(100);
        $servicio->rechazar($donacion, $admin, 'La captura no se lee.');

        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);

        $servicio->revertir($donacion->fresh(), $admin, 'Se rechazó sin mirar bien el comprobante.');

        $fondo->refresh();

        $this->assertSame(0.0, (float) $fondo->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->refresh()->estado);
    }

    /**
     * El rastro de la PRIMERA decisión no se borra: es lo que hay que poder
     * consultar después para saber quién se equivocó.
     */
    public function test_al_revertir_se_conserva_quien_habia_verificado(): void
    {
        $this->fondoDePrueba();
        $quienAprobo = $this->admin(RolAdmin::EDITOR, 'redactora');
        $quienRevierte = $this->admin(RolAdmin::SUPERADMIN, 'jefa');
        $servicio = $this->app->make(VerificarDonacionQr::class);

        $donacion = $this->porQr(100);
        $servicio->aprobar($donacion, $quienAprobo, 100.0);
        $servicio->revertir($donacion->fresh(), $quienRevierte, 'Aprobada por error, el comprobante no correspondía.');

        $donacion->refresh();

        // Las dos parejas conviven: quién decidió, y quién lo deshizo.
        $this->assertSame($quienAprobo->id, $donacion->verificado_por, 'Se perdió quién tomó la primera decisión.');
        $this->assertNotNull($donacion->verificado_at);
        $this->assertSame($quienRevierte->id, $donacion->revertido_por);
        $this->assertNotNull($donacion->revertido_at);
        $this->assertSame('Aprobada por error, el comprobante no correspondía.', $donacion->motivo_reversion);

        // Y vuelve a estar en la cola, sin monto real.
        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->estado);
        $this->assertNull($donacion->monto_real);

        // Lo que el donante declaró no se toca jamás.
        $this->assertSame(100.0, (float) $donacion->monto_referencial);
    }

    public function test_un_editor_no_puede_revertir(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->porQr(100);

        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $this->admin(), 100.0);

        $this->actingAs($this->admin(RolAdmin::EDITOR, 'redactora'), 'admin')
            ->post(route('admin.verificacion.revertir', $donacion->fresh()), [
                'motivo_reversion' => 'Quiero deshacer mi propia decisión.',
            ])
            ->assertForbidden();

        $this->assertSame(100.0, (float) $fondo->refresh()->recaudado);
    }

    /**
     * El estado de un pago con tarjeta lo manda Mercado Pago. Tocarlo a mano
     * dejaría la base diciendo una cosa y la pasarela otra.
     */
    public function test_una_donacion_de_mercado_pago_no_se_puede_revertir_ni_forzando_la_peticion(): void
    {
        $fondo = $this->fondoDePrueba();

        $deTarjeta = $this->donacionPendiente(['fondo_id' => $fondo->id]);
        $deTarjeta->forceFill([
            'estado' => EstadoDonacion::APROBADO,
            'monto_real' => 150,
        ])->save();

        $this->assertSame(CanalPago::MERCADOPAGO, $deTarjeta->canal_pago);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.verificacion.revertir', $deTarjeta), [
                'motivo_reversion' => 'Quiero deshacer un pago con tarjeta.',
            ])
            ->assertForbidden();

        $this->assertSame(EstadoDonacion::APROBADO, $deTarjeta->refresh()->estado);
    }

    public function test_revertir_sin_motivo_o_con_uno_demasiado_corto_se_rechaza(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->porQr(100);
        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $this->admin(), 100.0);

        $admin = $this->admin(RolAdmin::SUPERADMIN, 'otra-jefa');

        foreach (['', 'error', 'corto'] as $motivo) {
            $this->actingAs($admin, 'admin')
                ->post(route('admin.verificacion.revertir', $donacion->fresh()), ['motivo_reversion' => $motivo])

                // El error va a la bolsa de ESTE diálogo, no a la común: es lo
                // que permite a la vista volver a abrir ese y solo ese, con el
                // mensaje junto al campo en vez de perdido arriba del todo.
                ->assertSessionHasErrors('motivo_reversion', null, 'reversion_'.$donacion->id);
        }

        // Nada se movió en ninguno de los tres intentos.
        $this->assertSame(EstadoDonacion::APROBADO, $donacion->refresh()->estado);
        $this->assertSame(100.0, (float) $fondo->refresh()->recaudado);
    }

    /**
     * El «+» del final.
     *
     * Se reportó un motivo guardado como «aprobada por error+». Ese `+` es la
     * forma en que `application/x-www-form-urlencoded` codifica un espacio: si
     * un valor llega sin decodificar, un espacio final se convierte en un `+`
     * visible, y `trim()` no lo quita porque un `+` no es espacio en blanco.
     */
    public function test_el_motivo_de_la_reversion_llega_limpio(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->porQr(100);
        $admin = $this->admin();

        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $admin, 100.0);

        $this->actingAs($admin, 'admin')->post(
            route('admin.verificacion.revertir', $donacion->fresh()),
            ['motivo_reversion' => '  aprobada   por  error+  ']
        )->assertRedirect();

        // Sin el «+», sin espacios de sobra y sin rachas internas.
        $this->assertSame('aprobada por error', $donacion->refresh()->motivo_reversion);
    }

    /**
     * El fallo reportado: el diálogo se cerraba y el error quedaba arriba.
     *
     * Una administradora pulsó «Rechazar» sin motivo, el diálogo se cerró, la
     * página recargó y el mensaje apareció fuera de su vista. Pensó que el
     * sistema estaba roto.
     *
     * Ahora el error va a la bolsa de ESE diálogo, la vista lo vuelve a abrir
     * y repone lo que se había escrito.
     */
    public function test_si_el_servidor_rechaza_el_dialogo_se_reabre_con_lo_escrito(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->porQr(100);
        $admin = $this->admin();

        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $admin, 100.0);

        // Motivo demasiado corto: el servidor lo rechaza.
        $this->actingAs($admin, 'admin')
            ->post(route('admin.verificacion.revertir', $donacion->fresh()), ['motivo_reversion' => 'corto'])
            ->assertRedirect();

        $html = (string) $this->actingAs($admin, 'admin')
            ->get(route('admin.verificacion.index', ['estado' => 'todos']))
            ->assertOk()
            ->getContent();

        // El diálogo se vuelve a abrir solo.
        $this->assertStringContainsString('data-abrir-al-cargar', $html);

        // Con lo que había escrito, no en blanco.
        $this->assertStringContainsString('value="corto"', $html);

        // Y el mensaje, junto al campo, dentro del diálogo.
        $this->assertStringContainsString('campo--error', $html);
        $this->assertStringContainsString('mínimo 10 caracteres', $html);
    }

    /** Lo mismo en el diálogo de verificación, que es donde se reportó. */
    public function test_rechazar_sin_motivo_reabre_el_dialogo_de_verificacion(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->porQr(100);
        $admin = $this->admin();

        $html = (string) $this->actingAs($admin, 'admin')
            ->from(route('admin.verificacion.index'))
            ->followingRedirects()
            ->post(route('admin.verificacion.verificar', $donacion), [
                'decision' => 'rechazar',
                'motivo' => '',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-abrir-al-cargar', $html);
        $this->assertStringContainsString('Escribe por qué se rechaza', $html);

        // Y no se decidió nada.
        $this->assertSame(EstadoDonacion::PENDIENTE, $donacion->refresh()->estado);
    }

    /** «Deshacer» y no «revertir»: lo segundo se confunde con «rechazar». */
    public function test_la_cola_llama_deshacer_a_la_accion_de_revertir(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->porQr(100);
        $admin = $this->admin();

        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $admin, 100.0);

        $html = (string) $this->actingAs($admin, 'admin')
            ->get(route('admin.verificacion.index', ['estado' => 'todos']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Deshacer verificación', $html);

        // Y el diálogo aclara que no descarta la donación.
        $this->assertStringContainsString('No descarta la donación', $html);
    }

    /** Tras deshacer, el mensaje dice qué sigue. */
    public function test_tras_deshacer_el_mensaje_dice_que_hay_que_volver_a_decidir(): void
    {
        $this->fondoDePrueba();
        $donacion = $this->porQr(100);
        $admin = $this->admin();

        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $admin, 100.0);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.verificacion.revertir', $donacion->fresh()), [
                'motivo_reversion' => 'Aprobada con el comprobante equivocado.',
            ])
            ->assertSessionHas('exito', fn (string $mensaje): bool => str_contains($mensaje, 'Vuelve a decidir'));
    }

    // ── 2. Pendientes ────────────────────────────────────────────────────────

    public function test_una_pendiente_no_mueve_el_recaudado_del_fondo(): void
    {
        $fondo = $this->fondoDePrueba();

        $this->porQr(100);

        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
    }

    public function test_la_cifra_de_pendientes_incluye_la_donacion_y_desaparece_al_decidirla(): void
    {
        $fondo = $this->fondoDePrueba();
        $admin = $this->admin();
        $servicio = $this->app->make(VerificarDonacionQr::class);

        $this->assertSame(0.0, $this->pendientes()->porFondo($fondo)['monto']);

        $donacion = $this->porQr(100);

        $antes = $this->pendientes()->porFondo($fondo);
        $this->assertSame(100.0, $antes['monto']);
        $this->assertSame(1, $antes['aportes']);

        $servicio->aprobar($donacion, $admin, 80.0);

        $despues = $this->pendientes()->porFondo($fondo);
        $this->assertSame(0.0, $despues['monto'], 'Sigue contando como pendiente después de aprobarla.');
        $this->assertSame(0, $despues['aportes']);
    }

    public function test_al_rechazar_tambien_deja_de_estar_pendiente(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->porQr(100);

        $this->app->make(VerificarDonacionQr::class)
            ->rechazar($donacion, $this->admin(), 'No coincide con ningún movimiento.');

        $this->assertSame(0.0, $this->pendientes()->porFondo($fondo)['monto']);
    }

    /**
     * El caso que más importa: al aprobar, el importe cambia de sitio. No puede
     * quedarse en los dos, ni desaparecer de los dos.
     */
    public function test_al_aprobar_el_importe_pasa_de_por_verificar_a_recaudado_sin_contarse_dos_veces(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->porQr(100);

        $this->assertSame(100.0, $this->pendientes()->porFondo($fondo)['monto']);
        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);

        $this->app->make(VerificarDonacionQr::class)->aprobar($donacion, $this->admin(), 100.0);

        $this->assertSame(0.0, $this->pendientes()->porFondo($fondo)['monto']);
        $this->assertSame(100.0, (float) $fondo->refresh()->recaudado);
    }

    /** Y al revertir vuelve a estar pendiente: el dinero no se evapora. */
    public function test_al_revertir_el_importe_vuelve_a_por_verificar(): void
    {
        $fondo = $this->fondoDePrueba();
        $admin = $this->admin();
        $servicio = $this->app->make(VerificarDonacionQr::class);

        $donacion = $this->porQr(100);
        $servicio->aprobar($donacion, $admin, 100.0);
        $servicio->revertir($donacion->fresh(), $admin, 'Aprobada por error de lectura.');

        $this->assertSame(100.0, $this->pendientes()->porFondo($fondo)['monto']);
        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);
    }

    /** En la API pública viajan separadas, y `recaudado` no la incluye. */
    public function test_el_dashboard_publico_separa_recaudado_de_pendiente(): void
    {
        $fondo = $this->fondoDePrueba();
        $this->porQr(100);

        $respuesta = $this->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0.0, (float) $respuesta->json('totales.recaudado'));
        $this->assertSame(100.0, (float) $respuesta->json('totales.pendiente'));
        $this->assertSame(1, $respuesta->json('totales.pendientes_aportes'));

        $this->assertSame(0.0, (float) $respuesta->json('fondos.0.recaudado'));
        $this->assertSame(100.0, (float) $respuesta->json('fondos.0.pendiente'));

        unset($fondo);
    }

    /** Si no hay nada pendiente, la cifra no se pinta: nada de «S/ 0.00». */
    public function test_sin_pendientes_la_cifra_no_aparece_en_la_pagina(): void
    {
        $this->fondoDePrueba();

        $html = (string) $this->get(route('donar'))->assertOk()->getContent();

        $this->assertStringNotContainsString('por verificar', $html);
    }

    public function test_con_pendientes_la_cifra_aparece_en_la_pagina(): void
    {
        $this->fondoDePrueba();
        $this->porQr(100);

        $html = (string) $this->get(route('donar'))->assertOk()->getContent();

        $this->assertStringContainsString('por verificar', $html);
        $this->assertStringContainsString('100.00', $html);
    }
}
