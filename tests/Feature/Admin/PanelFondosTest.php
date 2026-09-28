<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\EstadoFondo;
use App\Enums\RolAdmin;
use App\Models\AdminUser;
use App\Models\Fondo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Panel de fondos: permisos, subida de imágenes y reglas de negocio.
 *
 * El hilo conductor es el mismo de todo el módulo: quien redacta no decide
 * dónde va el dinero, y nada que tenga historial se borra.
 */
final class PanelFondosTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
        Storage::fake('fondos');
    }

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
            'nombre' => 'Fondo de agua',
            'slug' => 'fondo-de-agua',
            'resumen' => 'Agua potable para comunidades altoandinas.',
            'descripcion' => null,
            'meta' => null,
            'moneda' => 'PEN',
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'color_token' => 'teal',
            'orden' => 1,
            'video' => null,
        ], $extra);
    }

    /** Imagen válida del tamaño mínimo exigido a una portada. */
    private function portadaValida(): UploadedFile
    {
        return UploadedFile::fake()->image('la-foto-del-usuario.jpg', 1200, 630);
    }

    // ── AT-55, AT-56, AT-57: roles ───────────────────────────────────────────

    public function test_at55_un_editor_no_puede_crear_un_fondo(): void
    {
        $editor = $this->admin(RolAdmin::EDITOR, 'redactora');

        $this->actingAs($editor, 'admin')->get(route('admin.fondos.crear'))->assertForbidden();
        $this->actingAs($editor, 'admin')->post(route('admin.fondos.guardar'), $this->formulario())->assertForbidden();

        $this->assertSame(0, Fondo::query()->count());
    }

    public function test_at56_un_editor_no_puede_cambiar_el_estado(): void
    {
        $editor = $this->admin(RolAdmin::EDITOR, 'redactora');
        $fondo = $this->fondoDePrueba(['estado' => EstadoFondo::BORRADOR]);

        $this->actingAs($editor, 'admin')
            ->post(route('admin.fondos.estado', $fondo), [
                'estado' => EstadoFondo::ACTIVO->value,
                'confirmacion' => $fondo->nombre,
            ])
            ->assertForbidden();

        $this->assertSame(EstadoFondo::BORRADOR, $fondo->refresh()->estado);
    }

    public function test_un_editor_no_puede_borrar_ni_marcar_predeterminado(): void
    {
        $editor = $this->admin(RolAdmin::EDITOR, 'redactora');
        $fondo = $this->fondoDePrueba(['estado' => EstadoFondo::BORRADOR, 'es_predeterminado' => false]);

        $this->actingAs($editor, 'admin')
            ->post(route('admin.fondos.predeterminado', $fondo))
            ->assertForbidden();

        // El borrado responde con una redirección explicativa, no con un 403 seco.
        $this->actingAs($editor, 'admin')
            ->delete(route('admin.fondos.eliminar', $fondo))
            ->assertRedirect(route('admin.fondos.index'))
            ->assertSessionHasErrors('fondo');

        $this->assertSame(1, Fondo::query()->whereKey($fondo->id)->count());
        $this->assertFalse($fondo->refresh()->es_predeterminado);
    }

    public function test_at57_un_editor_si_puede_editar_los_textos_de_un_fondo(): void
    {
        $editor = $this->admin(RolAdmin::EDITOR, 'redactora');
        $fondo = $this->fondoDePrueba(['slug' => 'fondo-editable']);

        $this->actingAs($editor, 'admin')->get(route('admin.fondos.editar', $fondo))->assertOk();

        $this->actingAs($editor, 'admin')
            ->put(route('admin.fondos.actualizar', $fondo), $this->formulario([
                'nombre' => 'Nombre mejorado por la editora',
                'slug' => 'fondo-editable',
                'resumen' => 'Un resumen mucho mejor redactado que el anterior.',
            ]))
            ->assertRedirect(route('admin.fondos.editar', $fondo));

        $this->assertSame('Nombre mejorado por la editora', $fondo->refresh()->nombre);
    }

    // ── AT-60 a AT-63: subidas ───────────────────────────────────────────────

    /** El caso clásico: se renombra la extensión y se confía en ella. */
    public function test_at60_un_ejecutable_renombrado_a_jpg_es_rechazado(): void
    {
        $admin = $this->admin();

        // Contenido de un PE de Windows con extensión .jpg.
        $falso = UploadedFile::fake()->createWithContent(
            'inofensiva.jpg',
            "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 200)
        );

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario(['imagen_portada' => $falso]))
            ->assertSessionHasErrors('imagen_portada');

        $this->assertSame(0, Fondo::query()->count());
    }

    public function test_at61_una_imagen_de_seis_megas_es_rechazada(): void
    {
        $admin = $this->admin();

        $pesada = UploadedFile::fake()->image('enorme.jpg', 1200, 630)->size(6 * 1024);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario(['imagen_portada' => $pesada]))
            ->assertSessionHasErrors('imagen_portada');
    }

    public function test_at62_una_portada_de_400x300_es_rechazada_por_dimensiones(): void
    {
        $admin = $this->admin();

        $pequena = UploadedFile::fake()->image('pequena.jpg', 400, 300);

        $respuesta = $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario(['imagen_portada' => $pequena]));

        $respuesta->assertSessionHasErrors('imagen_portada');

        // El mensaje dice exactamente por que, y para que sirve esa medida.
        $respuesta->assertSessionHasErrors([
            'imagen_portada' => 'La imagen de portada debe medir al menos 1200×630 píxeles: es la que se ve al compartir el fondo en redes.',
        ]);
    }

    /**
     * El nombre que manda el navegador puede traer rutas o una segunda
     * extensión. Nunca se usa: lo ponemos nosotros.
     */
    public function test_at63_el_archivo_guardado_no_conserva_el_nombre_original(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario(['imagen_portada' => $this->portadaValida()]))
            ->assertRedirect();

        $fondo = Fondo::query()->sole();
        $ruta = (string) $fondo->imagen_portada;

        $this->assertNotEmpty($ruta);
        $this->assertStringNotContainsString('la-foto-del-usuario', $ruta);
        $this->assertStringStartsWith($fondo->id.'/', $ruta);
        $this->assertStringEndsWith('.jpg', $ruta);
        Storage::disk('fondos')->assertExists($ruta);
    }

    public function test_al_reemplazar_la_portada_se_borra_la_anterior(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario(['imagen_portada' => $this->portadaValida()]));

        $fondo = Fondo::query()->sole();
        $primera = (string) $fondo->imagen_portada;

        $this->actingAs($admin, 'admin')
            ->put(route('admin.fondos.actualizar', $fondo), $this->formulario([
                'imagen_portada' => UploadedFile::fake()->image('otra.png', 1300, 700),
            ]));

        $segunda = (string) $fondo->refresh()->imagen_portada;

        $this->assertNotSame($primera, $segunda);
        Storage::disk('fondos')->assertMissing($primera);
        Storage::disk('fondos')->assertExists($segunda);
    }

    // ── AT-64 y AT-65: el slug ───────────────────────────────────────────────

    public function test_at64_no_se_puede_cambiar_el_slug_de_un_fondo_con_donaciones(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba(['slug' => 'fondo-con-historia']);
        $this->donacionPendiente(['fondo_id' => $fondo->id]);

        $respuesta = $this->actingAs($admin, 'admin')
            ->put(route('admin.fondos.actualizar', $fondo), $this->formulario(['slug' => 'slug-nuevo']));

        $respuesta->assertSessionHasErrors([
            'slug' => 'Este fondo ya tiene donaciones, así que su dirección web no se puede cambiar: '
                .'rompería los enlaces compartidos y los códigos QR ya impresos.',
        ]);
        $this->assertSame('fondo-con-historia', $fondo->refresh()->slug);
    }

    public function test_at65_si_no_tiene_donaciones_el_slug_si_se_puede_cambiar(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba(['slug' => 'fondo-sin-historia']);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.fondos.actualizar', $fondo), $this->formulario(['slug' => 'slug-nuevo']))
            ->assertSessionHasNoErrors();

        $this->assertSame('slug-nuevo', $fondo->refresh()->slug);
    }

    // ── AT-66: publicación ───────────────────────────────────────────────────

    public function test_at66_no_se_puede_activar_un_fondo_con_el_resumen_pendiente(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba([
            'estado' => EstadoFondo::BORRADOR,
            'resumen' => 'PENDIENTE: redactar resumen del fondo',
        ]);

        $respuesta = $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.estado', $fondo), [
                'estado' => EstadoFondo::ACTIVO->value,
                'confirmacion' => $fondo->nombre,
            ]);

        $respuesta->assertSessionHasErrors('estado');
        $this->assertSame(EstadoFondo::BORRADOR, $fondo->refresh()->estado);
    }

    /** Publicar exige escribir el nombre: es el momento en que entra dinero. */
    public function test_publicar_exige_confirmacion_consciente(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba(['estado' => EstadoFondo::BORRADOR]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.estado', $fondo), ['estado' => EstadoFondo::ACTIVO->value])
            ->assertSessionHasErrors('confirmacion');

        $this->assertSame(EstadoFondo::BORRADOR, $fondo->refresh()->estado);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.estado', $fondo), [
                'estado' => EstadoFondo::ACTIVO->value,
                'confirmacion' => $fondo->nombre,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(EstadoFondo::ACTIVO, $fondo->refresh()->estado);
    }

    /** Pausar o cerrar no exige confirmación: no empieza a entrar dinero. */
    public function test_pausar_no_exige_escribir_el_nombre(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba(['estado' => EstadoFondo::ACTIVO]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.estado', $fondo), ['estado' => EstadoFondo::PAUSADO->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(EstadoFondo::PAUSADO, $fondo->refresh()->estado);
    }

    // ── AT-67 y AT-68: borrado ───────────────────────────────────────────────

    public function test_at67_no_se_puede_borrar_un_fondo_con_donaciones(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba(['estado' => EstadoFondo::BORRADOR]);
        $this->donacionPendiente(['fondo_id' => $fondo->id]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.fondos.eliminar', $fondo))
            ->assertSessionHasErrors('fondo');

        $this->assertSame(1, Fondo::query()->whereKey($fondo->id)->count());
    }

    public function test_at68_un_borrador_sin_donaciones_se_borra_con_su_carpeta(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario(['imagen_portada' => $this->portadaValida()]));

        $fondo = Fondo::query()->sole();
        $ruta = (string) $fondo->imagen_portada;
        Storage::disk('fondos')->assertExists($ruta);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.fondos.eliminar', $fondo))
            ->assertRedirect(route('admin.fondos.index'));

        $this->assertSame(0, Fondo::query()->whereKey($fondo->id)->count());
        Storage::disk('fondos')->assertMissing($ruta);
        $this->assertFalse(Storage::disk('fondos')->directoryExists((string) $fondo->id));
    }

    // ── AT-69: predeterminado ────────────────────────────────────────────────

    public function test_at69_marcar_predeterminado_desmarca_el_anterior(): void
    {
        $admin = $this->admin();
        $primero = $this->fondoDePrueba(['slug' => 'primero', 'es_predeterminado' => true]);
        $segundo = $this->fondoDePrueba(['slug' => 'segundo', 'es_predeterminado' => false]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.predeterminado', $segundo))
            ->assertRedirect(route('admin.fondos.index'));

        $this->assertFalse($primero->refresh()->es_predeterminado);
        $this->assertTrue($segundo->refresh()->es_predeterminado);
        $this->assertSame(1, Fondo::query()->where('es_predeterminado', true)->count());
    }

    // ── AT-70: panel de salud ────────────────────────────────────────────────

    public function test_at70_el_panel_de_salud_detecta_un_descuadre(): void
    {
        $admin = $this->admin();
        $fondo = $this->fondoDePrueba(['slug' => 'fondo-descuadrado']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.resumen'))
            ->assertOk()
            ->assertSee('Las cifras cuadran');

        // Se desvía el contador a mano, como haría un bug.
        DB::table('fondos')->where('id', $fondo->id)->update(['recaudado' => 480.00]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.resumen'))
            ->assertOk()
            ->assertSee('Las cifras no cuadran')
            ->assertSee('fondo-descuadrado');
    }

    public function test_el_resumen_avisa_de_un_fondo_activo_sin_redactar(): void
    {
        $admin = $this->admin();
        $this->fondoDePrueba([
            'estado' => EstadoFondo::ACTIVO,
            'resumen' => 'PENDIENTE: redactar resumen del fondo',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.resumen'))
            ->assertOk()
            ->assertSee('Hay texto sin redactar publicado');
    }

    // ── AT-71: fechas ────────────────────────────────────────────────────────

    public function test_at71_la_fecha_de_cierre_no_puede_ser_anterior_a_la_de_inicio(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.fondos.guardar'), $this->formulario([
                'fecha_inicio' => '2026-10-01',
                'fecha_fin' => '2026-09-01',
            ]))
            ->assertSessionHasErrors('fecha_fin');

        $this->assertSame(0, Fondo::query()->count());
    }

    // ── AT-73: mensajes en español ───────────────────────────────────────────

    /**
     * Con APP_FALLBACK_LOCALE=es, cualquier regla sin traducción saldría como
     * la clave cruda. Este caso barre el formulario entero buscándolas.
     */
    public function test_at73_ningun_mensaje_de_validacion_contiene_la_cadena_validation(): void
    {
        $admin = $this->admin();

        // Se desactiva el manejador para poder leer los mensajes directamente
        // de la excepcion, sin pasar por el bag de sesion.
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin, 'admin')->post(route('admin.fondos.guardar'), [
                'nombre' => '',
                'slug' => 'MAYÚSCULAS Y ESPACIOS',
                'resumen' => 'corto',
                'meta' => 'no-es-un-numero',
                'moneda' => 'XXX',
                'fecha_inicio' => 'no-es-fecha',
                'fecha_fin' => 'tampoco',
                'color_token' => 'fucsia',
                'orden' => 'abc',
                'imagen_portada' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            ]);

            $this->fail('Se aceptó un formulario inválido.');
        } catch (ValidationException $excepcion) {
            $mensajes = Arr::flatten($excepcion->errors());

            $this->assertNotEmpty($mensajes);

            foreach ($mensajes as $mensaje) {
                $this->assertStringNotContainsString(
                    'validation.',
                    (string) $mensaje,
                    "Falta la traducción de esta regla en lang/es/validation.php: {$mensaje}"
                );
            }
        }
    }

    public function test_un_fondo_se_crea_siempre_como_borrador(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.fondos.guardar'), $this->formulario());

        $fondo = Fondo::query()->sole();

        $this->assertSame(EstadoFondo::BORRADOR, $fondo->estado);
        $this->assertFalse($fondo->aceptaDonaciones());
        $this->assertSame($admin->id, $fondo->creado_por);
    }
}
