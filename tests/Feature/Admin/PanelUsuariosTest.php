<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\EstadoDonacion;
use App\Enums\RolAdmin;
use App\Models\AdminUser;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\MercadoPago\ConsultarPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Gestión de administradores y la alarma del contador.
 *
 * Las dos salvaguardas de esta pantalla existen para lo mismo: que el sistema
 * no pueda quedarse sin nadie capaz de publicar un fondo.
 */
final class PanelUsuariosTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const CLAVE = 'ClaveDePruebaLarga2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        // El servicio de reconciliacion exige credenciales antes de consultar.
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

    private function admin(RolAdmin $rol = RolAdmin::SUPERADMIN, string $usuario = 'jefa'): AdminUser
    {
        return AdminUser::query()->create([
            'username' => $usuario,
            'password' => self::CLAVE,
            'role' => $rol,
        ]);
    }

    // ── AT-58 ────────────────────────────────────────────────────────────────

    /**
     * Si un superadmin pudiera degradarse, bastaría un despiste para dejar el
     * panel sin nadie capaz de publicar un fondo ni de crear otro admin.
     */
    public function test_at58_un_superadmin_no_puede_quitarse_su_propio_rol(): void
    {
        $yo = $this->admin();

        $this->actingAs($yo, 'admin')
            ->put(route('admin.usuarios.actualizar', $yo), [
                'username' => $yo->username,
                'role' => RolAdmin::EDITOR->value,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(RolAdmin::SUPERADMIN, $yo->refresh()->role);
    }

    public function test_un_superadmin_no_puede_borrarse_a_si_mismo(): void
    {
        $yo = $this->admin();

        $this->actingAs($yo, 'admin')
            ->delete(route('admin.usuarios.eliminar', $yo))
            ->assertSessionHasErrors('usuario');

        $this->assertSame(1, AdminUser::query()->whereKey($yo->id)->count());
    }

    public function test_si_puede_cambiar_el_rol_de_otro(): void
    {
        $yo = $this->admin();
        $otro = $this->admin(RolAdmin::EDITOR, 'redactora');

        $this->actingAs($yo, 'admin')
            ->put(route('admin.usuarios.actualizar', $otro), [
                'username' => $otro->username,
                'role' => RolAdmin::SUPERADMIN->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RolAdmin::SUPERADMIN, $otro->refresh()->role);
    }

    public function test_un_editor_no_entra_en_la_gestion_de_administradores(): void
    {
        $editor = $this->admin(RolAdmin::EDITOR, 'redactora');

        $this->actingAs($editor, 'admin')->get(route('admin.usuarios.index'))->assertForbidden();
        $this->actingAs($editor, 'admin')->get(route('admin.usuarios.crear'))->assertForbidden();
    }

    /** Misma cerradura que `make:superadmin`: 12 caracteres. */
    public function test_la_contrasena_minima_son_doce_caracteres(): void
    {
        $yo = $this->admin();

        $this->actingAs($yo, 'admin')
            ->post(route('admin.usuarios.guardar'), [
                'username' => 'nueva',
                'password' => 'corta123',
                'password_confirmation' => 'corta123',
                'role' => RolAdmin::EDITOR->value,
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(1, AdminUser::query()->count());
    }

    public function test_crea_un_administrador_con_la_contrasena_hasheada(): void
    {
        $yo = $this->admin();

        $this->actingAs($yo, 'admin')
            ->post(route('admin.usuarios.guardar'), [
                'username' => 'redactora',
                'password' => self::CLAVE,
                'password_confirmation' => self::CLAVE,
                'role' => RolAdmin::EDITOR->value,
            ])
            ->assertRedirect(route('admin.usuarios.index'));

        $nueva = AdminUser::query()->where('username', 'redactora')->sole();

        $this->assertSame(RolAdmin::EDITOR, $nueva->role);
        $this->assertNotSame(self::CLAVE, $nueva->getAuthPassword());
        $this->assertTrue(Hash::check(self::CLAVE, $nueva->getAuthPassword()));
    }

    public function test_dejar_la_contrasena_vacia_al_editar_no_la_cambia(): void
    {
        $yo = $this->admin();
        $otro = $this->admin(RolAdmin::EDITOR, 'redactora');
        $hashOriginal = $otro->getAuthPassword();

        $this->actingAs($yo, 'admin')
            ->put(route('admin.usuarios.actualizar', $otro), [
                'username' => 'redactora-nueva',
                'password' => '',
                'role' => RolAdmin::EDITOR->value,
            ])
            ->assertSessionHasNoErrors();

        $otro->refresh();

        $this->assertSame('redactora-nueva', $otro->username);
        $this->assertSame($hashOriginal, $otro->getAuthPassword());
    }

    // ── AT-72 ────────────────────────────────────────────────────────────────

    /**
     * `max(0, ...)` impide guardar un negativo, pero un cálculo negativo
     * SIGNIFICA que se está restando una donación que nunca se sumó. Sin este
     * registro, el desajuste quedaría tapado para siempre.
     */
    public function test_at72_un_contador_que_intentaria_bajar_de_cero_deja_un_error_en_el_log(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendiente(['fondo_id' => $fondo->id]);

        // Se aprueba: el contador sube a 1 y a 150.00.
        $this->aplicarPago($donacion->id, 'approved', 9001, 150.00);

        // Y ahora se falsea el contador a cero, como haría un bug previo.
        DB::table('fondos')->where('id', $fondo->id)->update([
            'recaudado' => 0,
            'donaciones_count' => 0,
        ]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $mensaje, array $contexto) use ($fondo, $donacion): bool {
                return str_contains($mensaje, 'bajar de cero')
                    && $contexto['fondo_id'] === $fondo->id
                    && $contexto['donacion_id'] === $donacion->id
                    && $contexto['donaciones_count_calculado'] < 0;
            })
            ->andReturnNull();

        // El contracargo intenta restar lo que ya no está.
        $this->aplicarPago($donacion->id, 'charged_back', 9001, 150.00, '2026-09-28T12:00:00.000-05:00');

        $fondo->refresh();

        // Nunca se guarda un negativo.
        $this->assertSame(0.0, (float) $fondo->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->refresh()->estado);
    }

    private function aplicarPago(
        int $donacionId,
        string $estado,
        int $idDePago,
        float $monto,
        ?string $fechaUltimaActualizacion = null,
    ): void {
        $falso = (new ClienteHttpMercadoPagoFalso)->conPago(
            $idDePago,
            $estado,
            (string) $donacionId,
            $monto,
            fechaUltimaActualizacion: $fechaUltimaActualizacion,
        );

        MercadoPagoConfig::setHttpClient($falso);
        $this->app->forgetInstance(PaymentClient::class);

        $pago = $this->app->make(ConsultarPago::class)((string) $idDePago);
        $this->app->make(ReconciliarDonacion::class)($pago, 'webhook');
    }
}
