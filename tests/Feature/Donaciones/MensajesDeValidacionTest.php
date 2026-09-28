<?php

declare(strict_types=1);

namespace Tests\Feature\Donaciones;

use App\Models\Donacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Que el formulario diga qué falta.
 *
 * ── EL FALLO QUE ORIGINA ESTOS CASOS ────────────────────────────────────────
 *
 * Una persona rellenó el formulario entero, pulsó el botón y no pasó nada.
 * Había olvidado marcar «Acepto los términos» y el formulario no se lo dijo:
 * se quedó atascada sin saber por qué.
 *
 * Lo que se vigila aquí es el contrato del servidor, que es la mitad del
 * arreglo: cada campo que falla tiene que venir identificado POR SU NOMBRE en
 * `campos`, con un mensaje en español que diga qué hacer. La otra mitad —
 * marcar el campo, listar el resumen y llevar el foco— vive en
 * `donacion.js` y se apoya en este contrato.
 */
final class MensajesDeValidacionTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
        Storage::fake('comprobantes');

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
            'mercadopago.currency' => 'PEN',
            'donaciones.monto_minimo_mp' => 5,
            'donaciones.monto_minimo_qr' => 10,
            'donaciones.monto_maximo' => 10000,
        ]);
    }

    /** @return array<string, mixed> */
    private function tarjeta(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'tipo_aportante' => 'persona',
            'fondo_id' => $this->fondoDePrueba()->id,
            'monto' => 100,
            'moneda' => 'PEN',
            'acepta_terminos' => '1',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function qr(array $extra = []): array
    {
        return array_merge($this->tarjeta(), [
            'proveedor_pago' => 'yape_qr',
            'comprobante' => UploadedFile::fake()->image('yape.jpg', 600, 1000),
        ], $extra);
    }

    // ── El caso que originó todo ─────────────────────────────────────────────

    /**
     * Sin marcar los términos, el servidor lo dice, con el nombre del campo y
     * un mensaje que explica qué hacer.
     */
    public function test_olvidar_los_terminos_devuelve_un_error_identificado_y_legible(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/mercadopago', $this->tarjeta([
            'acepta_terminos' => null,
        ]))->assertStatus(422);

        // El campo viene identificado: es lo que permite marcarlo en pantalla
        // en vez de enseñar un aviso genérico arriba.
        $this->assertArrayHasKey('acepta_terminos', $respuesta->json('campos'));

        $mensaje = $respuesta->json('campos.acepta_terminos');

        $this->assertStringContainsString('términos', $mensaje);
        $this->assertStringNotContainsString('validation.', $mensaje);

        // Y no se creó nada.
        $this->assertSame(0, Donacion::query()->count());
    }

    /** Lo mismo en el canal QR: el mismo contrato para los dos. */
    public function test_el_canal_qr_tambien_identifica_los_terminos(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/qr', $this->qr([
            'acepta_terminos' => null,
        ]))->assertStatus(422);

        $this->assertArrayHasKey('acepta_terminos', $respuesta->json('campos'));
    }

    // ── Todos los campos, no solo el primero ─────────────────────────────────

    /**
     * Con varios campos mal, vienen TODOS.
     *
     * Devolver solo el primero obliga a enviar, corregir, enviar, corregir —y
     * cada viaje es una oportunidad de abandonar.
     */
    public function test_con_varios_campos_mal_se_devuelven_todos(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/mercadopago', $this->tarjeta([
            'nombre' => 'Ma',
            'correo' => 'esto-no-es-un-correo',
            'documento' => '@@',
            'monto' => 2,
            'acepta_terminos' => null,
        ]))->assertStatus(422);

        $campos = $respuesta->json('campos');

        foreach (['nombre', 'correo', 'documento', 'monto', 'acepta_terminos'] as $campo) {
            $this->assertArrayHasKey($campo, $campos, "Falta el error de «{$campo}».");
        }
    }

    // ── Los mensajes dicen qué hacer ─────────────────────────────────────────

    /**
     * Ninguna regla se escapa sin mensaje en español.
     *
     * Si una regla no tiene el suyo, Laravel devuelve la clave cruda
     * (`validation.required`) porque el proyecto corre con APP_LOCALE=es. El
     * donante nunca puede ver eso.
     */
    public function test_ningun_mensaje_sale_como_clave_de_traduccion(): void
    {
        $this->fondoDePrueba();

        $peticiones = [
            '/api/donaciones/mercadopago' => $this->tarjeta([
                'nombre' => '', 'documento' => '', 'correo' => '', 'telefono' => str_repeat('9', 40),
                'tipo_aportante' => 'marciano', 'monto' => '', 'moneda' => 'XXX',
                'acepta_terminos' => null, 'fondo_id' => 999999,
            ]),
            '/api/donaciones/qr' => $this->qr([
                'nombre' => '', 'documento' => '', 'correo' => '', 'monto' => '',
                'proveedor_pago' => 'bitcoin', 'referencia_pago' => str_repeat('x', 200),
                'acepta_terminos' => null, 'comprobante' => null,
            ]),
        ];

        foreach ($peticiones as $ruta => $datos) {
            $respuesta = $this->postJson($ruta, $datos)->assertStatus(422);

            foreach ((array) $respuesta->json('errores') as $mensaje) {
                $this->assertStringNotContainsString('validation.', (string) $mensaje, "En {$ruta}");
                $this->assertNotSame('', trim((string) $mensaje), "Mensaje vacío en {$ruta}");
            }
        }
    }

    /** Los mensajes dicen QUÉ HACER, no que algo «es inválido». */
    public function test_los_mensajes_explican_en_vez_de_solo_rechazar(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/mercadopago', $this->tarjeta([
            'correo' => 'sin-arroba',
            'monto' => 2,
        ]))->assertStatus(422);

        $campos = $respuesta->json('campos');

        // El correo dice qué mirar.
        $this->assertStringContainsString('@', $campos['correo']);

        // El monto dice los dos límites, no solo el que falló.
        $this->assertStringContainsString('5', $campos['monto']);
        $this->assertStringContainsString('10,000', $campos['monto']);
    }

    // ── El archivo del canal QR ──────────────────────────────────────────────

    /** Sin comprobante, el mensaje lo dice y no habla de «el campo». */
    public function test_sin_comprobante_el_mensaje_es_concreto(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/qr', $this->qr(['comprobante' => null]))
            ->assertStatus(422);

        $this->assertArrayHasKey('comprobante', $respuesta->json('campos'));
        $this->assertStringContainsString('captura', $respuesta->json('campos.comprobante'));
    }

    /** Si pesa de más, el mensaje trae el límite en MB. */
    public function test_un_comprobante_demasiado_grande_dice_el_limite_en_megas(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/qr', $this->qr([
            'comprobante' => UploadedFile::fake()->image('enorme.jpg')->size(12 * 1024),
        ]))->assertStatus(422);

        $this->assertStringContainsString(
            (string) config('donaciones.comprobante.max_mb'),
            $respuesta->json('campos.comprobante')
        );
        $this->assertStringContainsString('MB', $respuesta->json('campos.comprobante'));
    }

    /** El mínimo del canal manual es el suyo, y el mensaje lo refleja. */
    public function test_el_minimo_del_canal_qr_aparece_en_su_mensaje(): void
    {
        $this->fondoDePrueba();

        $respuesta = $this->postJson('/api/donaciones/qr', $this->qr(['monto' => 6]))
            ->assertStatus(422);

        $this->assertStringContainsString('10', $respuesta->json('campos.monto'));
    }
}
