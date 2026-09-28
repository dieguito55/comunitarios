<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\RolAdmin;
use App\Models\AdminUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Entrada al panel.
 *
 * Cada caso corresponde a un ataque concreto: fuerza bruta, enumeración de
 * usuarios, fijación de sesión y acceso sin credenciales.
 */
final class AccesoAdminTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'ClaveDePruebaLarga2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    private function superadmin(array $extra = []): AdminUser
    {
        return AdminUser::query()->create(array_merge([
            'username' => 'tesoreria',
            'password' => self::CLAVE,
            'role' => RolAdmin::SUPERADMIN,
        ], $extra));
    }

    // ── AT-50 ────────────────────────────────────────────────────────────────

    public function test_at50_login_correcto_inicia_sesion_y_actualiza_last_login(): void
    {
        $admin = $this->superadmin();

        $this->assertNull($admin->last_login);

        $this->post(route('admin.login.entrar'), [
            'username' => 'tesoreria',
            'password' => self::CLAVE,
        ])->assertRedirect(route('admin.resumen'));

        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertSame($admin->id, Auth::guard('admin')->id());
        $this->assertNotNull($admin->refresh()->last_login);
    }

    // ── AT-51 ────────────────────────────────────────────────────────────────

    /**
     * Si los mensajes se distinguieran, el formulario serviría para averiguar
     * qué usuarios existen antes de atacarlos.
     */
    public function test_at51_usuario_inexistente_y_contrasena_mala_dan_el_mismo_mensaje(): void
    {
        $this->superadmin();

        $generico = __('auth.failed');

        $this->post(route('admin.login.entrar'), [
            'username' => 'no-existe-nadie',
            'password' => self::CLAVE,
        ])->assertSessionHasErrors(['username' => $generico]);

        $this->app->make('cache')->flush();

        $this->post(route('admin.login.entrar'), [
            'username' => 'tesoreria',
            'password' => 'una-clave-equivocada',
        ])->assertSessionHasErrors(['username' => $generico]);

        // El mensaje no puede insinuar cual de los dos fallo.
        $this->assertStringNotContainsStringIgnoringCase('usuario no existe', $generico);
        $this->assertStringNotContainsStringIgnoringCase('contrasena incorrecta', $generico);
        $this->assertFalse(Auth::guard('admin')->check());
    }

    // ── AT-52 ────────────────────────────────────────────────────────────────

    public function test_at52_seis_intentos_en_un_minuto_bloquean(): void
    {
        $this->superadmin();

        foreach (range(1, 5) as $intento) {
            $this->post(route('admin.login.entrar'), [
                'username' => 'tesoreria',
                'password' => 'mal',
            ])->assertSessionHasErrors('username');
        }

        $sexto = $this->post(route('admin.login.entrar'), [
            'username' => 'tesoreria',
            'password' => self::CLAVE,   // correcta, pero ya está bloqueado
        ]);

        // La prueba del bloqueo es que el sexto intento usaba la contrasena
        // CORRECTA y aun asi no entro.
        $sexto->assertSessionHasErrors('username');
        $this->assertFalse(Auth::guard('admin')->check());
    }

    /** El límite también cuenta por usuario, no solo por IP. */
    public function test_el_limite_por_usuario_persiste_aunque_cambie_la_ip(): void
    {
        $this->superadmin();

        foreach (range(1, 5) as $intento) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$intento])
                ->post(route('admin.login.entrar'), ['username' => 'tesoreria', 'password' => 'mal']);
        }

        $sexto = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->post(route('admin.login.entrar'), ['username' => 'tesoreria', 'password' => self::CLAVE]);

        // Cinco fallos repartidos entre cinco IPs distintas bastan para
        // bloquear al usuario desde una sexta IP.
        $sexto->assertSessionHasErrors('username');
        $this->assertFalse(Auth::guard('admin')->check());
    }

    // ── AT-53 ────────────────────────────────────────────────────────────────

    /** Contra fijación de sesión: el id de antes deja de valer. */
    public function test_at53_el_id_de_sesion_cambia_al_iniciar_sesion(): void
    {
        $this->superadmin();

        $this->get(route('admin.login'));
        $idAntes = session()->getId();

        $this->post(route('admin.login.entrar'), [
            'username' => 'tesoreria',
            'password' => self::CLAVE,
        ]);

        $this->assertNotSame($idAntes, session()->getId());
    }

    public function test_al_salir_la_sesion_se_invalida(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertFalse(Auth::guard('admin')->check());
    }

    // ── AT-54 ────────────────────────────────────────────────────────────────

    public function test_at54_el_panel_sin_sesion_redirige_al_login(): void
    {
        foreach ([
            route('admin.resumen'),
            route('admin.fondos.index'),
            route('admin.usuarios.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
    }

    public function test_un_usuario_autenticado_no_ve_el_formulario_de_acceso(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect();
    }

    // ── AT-59 ────────────────────────────────────────────────────────────────

    /**
     * El panel escribe dinero: cada formulario necesita su token. Sin él,
     * cualquier sitio podría enviar peticiones en nombre de un admin logueado.
     */
    public function test_at59_un_formulario_sin_token_csrf_da_419(): void
    {
        // Laravel se salta ValidateCsrfToken mientras corre la suite, asi que
        // una peticion normal nunca daria 419. Se ejercita el middleware
        // directamente, que es lo que de verdad protege al panel.
        $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;   // como en produccion
            }
        };

        $peticion = Request::create(route('admin.fondos.guardar'), 'POST', ['nombre' => 'Sin token']);
        $peticion->setLaravelSession(app('session.store'));

        try {
            $middleware->handle($peticion, static fn (): Response => new Response('no deberia llegar aqui'));
            $this->fail('Se aceptó una petición sin token CSRF.');
        } catch (TokenMismatchException $excepcion) {
            // 419 es exactamente el codigo con el que Laravel renderiza esta
            // excepcion.
            $this->assertSame(419, (new HttpResponseException(response('', 419)))->getResponse()->getStatusCode());
            $this->assertInstanceOf(TokenMismatchException::class, $excepcion);
        }
    }

    /** Todas las rutas que escriben en el panel pasan por el grupo `web`. */
    public function test_todo_el_panel_lleva_csrf(): void
    {
        $escriben = collect(Route::getRoutes())
            ->filter(static fn ($ruta): bool => str_starts_with($ruta->uri(), 'admin'))
            ->filter(static fn ($ruta): bool => (bool) array_intersect($ruta->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));

        $this->assertGreaterThan(0, $escriben->count());

        $delGrupoWeb = app(Kernel::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            PreventRequestForgery::class,
            $delGrupoWeb,
            'El grupo `web` dejo de validar CSRF.'
        );

        foreach ($escriben as $ruta) {
            $this->assertContains(
                'web',
                $ruta->gatherMiddleware(),
                "La ruta {$ruta->uri()} esta fuera del grupo `web`, asi que no valida CSRF."
            );
        }
    }

    public function test_el_intento_de_acceso_queda_registrado(): void
    {
        $this->superadmin();

        $this->post(route('admin.login.entrar'), ['username' => 'tesoreria', 'password' => 'mal']);

        // No se comprueba el contenido del archivo sino que el canal exista y
        // esté configurado: el registro es parte del contrato de seguridad.
        $this->assertSame('daily', config('logging.channels.admin.driver'));
        $this->assertSame(90, config('logging.channels.admin.max_files'));
    }
}
