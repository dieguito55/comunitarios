<?php

declare(strict_types=1);

namespace Tests\Feature\Publico;

use App\Enums\EstadoDonacion;
use App\Enums\EstadoFondo;
use App\Models\Donacion;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\MercadoPago\ConsultarPago;
use App\Services\Publico\ConstruirDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use Tests\Support\ClienteHttpMercadoPagoFalso;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * Sitio público: API de datos, páginas y la segunda red de seguridad.
 *
 * El bloque más importante es el de privacidad. La API la puede leer
 * cualquiera con un navegador: si se filtra un correo, se filtra para todo
 * internet y no hay forma de recogerlo.
 */
final class SitioPublicoTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    private const RUTA_API = '/api/dashboard';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        config([
            'mercadopago.env' => 'sandbox',
            'mercadopago.access_token' => 'TEST-0000000000000000-abcdef-123456',
            'donaciones.montos_sugeridos' => [20, 50, 100, 200],
            'donaciones.monto_minimo_mp' => 5,
            'donaciones.monto_maximo' => 10000,
        ]);
    }

    protected function tearDown(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);

        parent::tearDown();
    }

    /** Aprueba una donación como haría el webhook, para mover los contadores. */
    private function aprobar(Donacion $donacion, float $monto = 150.00, int $idDePago = 9001): void
    {
        MercadoPagoConfig::setHttpClient(
            (new ClienteHttpMercadoPagoFalso)->conPago($idDePago, 'approved', (string) $donacion->id, $monto)
        );
        $this->app->forgetInstance(PaymentClient::class);

        $pago = $this->app->make(ConsultarPago::class)((string) $idDePago);
        $this->app->make(ReconciliarDonacion::class)($pago, 'webhook');
    }

    /**
     * HTML con los espacios colapsados. Blade reparte los atributos en varias
     * líneas, así que sin esto `value="3" checked` nunca coincidiría.
     */
    private function html(string $url): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $this->get($url)->assertOk()->getContent());
    }

    /**
     * Cuántos radios de FONDO vienen marcados.
     *
     * Antes se contaba la palabra «checked» en todo el HTML, y funcionó
     * mientras el único radio marcado del formulario fue el del fondo. Con el
     * canal QR apareció un segundo grupo —Yape/Plin, con Yape marcado por
     * defecto— y la cuenta dejó de significar lo que el test creía medir.
     */
    private function radiosDeFondoMarcados(string $html): int
    {
        return preg_match_all('/name="fondo_id"[^>]*checked/', $html);
    }

    /** Aplana el JSON entero a una lista de valores escalares. */
    private function todosLosValores(mixed $dato): array
    {
        if (! is_array($dato)) {
            return [(string) $dato];
        }

        $valores = [];

        foreach ($dato as $clave => $valor) {
            $valores[] = (string) $clave;
            $valores = array_merge($valores, $this->todosLosValores($valor));
        }

        return $valores;
    }

    // ── AT-80 y AT-81: privacidad ────────────────────────────────────────────

    /**
     * Recorre el JSON ENTERO —claves y valores, a cualquier profundidad—
     * buscando los datos personales que guardamos. Si alguna vez alguien añade
     * un campo al feed sin pensarlo, este caso se pone rojo.
     */
    public function test_at80_la_api_no_devuelve_correo_documento_telefono_ni_ip(): void
    {
        $fondo = $this->fondoDePrueba();

        $this->aprobar($this->donacionPendiente([
            'nombre' => 'María Pérez Quispe',
            'correo' => 'maria.secreta@ejemplo.com',
            'documento' => '44556677',
            'telefono' => '+51 999 888 777',
            'ip_origen' => '203.0.113.42',
        ]));

        $respuesta = $this->getJson(self::RUTA_API)->assertOk();
        $plano = implode('|', $this->todosLosValores($respuesta->json()));

        foreach ([
            'maria.secreta@ejemplo.com',
            '44556677',
            '999 888 777',
            '999888777',
            '203.0.113.42',
        ] as $datoPersonal) {
            $this->assertStringNotContainsString($datoPersonal, $plano, "Se filtró: {$datoPersonal}");
        }

        // Tampoco las claves: ni siquiera vacías.
        foreach (['correo', 'documento', 'telefono', 'ip_origen', 'email'] as $clave) {
            $this->assertStringNotContainsString($clave, $plano, "Viaja la clave «{$clave}».");
        }

        $this->assertSame($fondo->slug, $respuesta->json('fondos.0.slug'));
    }

    /**
     * El anonimato se resuelve en el SERVIDOR. El nombre real no viaja al
     * navegador ni siquiera para que el front lo esconda: lo que no sale del
     * servidor no se puede leer abriendo las herramientas de desarrollo.
     */
    public function test_at81_una_donacion_anonima_sale_como_anonima_y_su_nombre_no_viaja(): void
    {
        $this->fondoDePrueba();

        $this->aprobar($this->donacionPendiente([
            'nombre' => 'Roberto Secreto Oculto',
            'correo' => 'roberto@ejemplo.com',
            'visible_publico' => false,
        ]));

        $respuesta = $this->getJson(self::RUTA_API)->assertOk();

        $this->assertSame('Donante anónimo', $respuesta->json('recientes.0.nombre'));
        $this->assertTrue($respuesta->json('recientes.0.anonimo'));

        $plano = implode('|', $this->todosLosValores($respuesta->json()));
        $this->assertStringNotContainsString('Roberto', $plano);
        $this->assertStringNotContainsString('Secreto', $plano);
    }

    /**
     * Quien SÍ quiere aparecer, aparece abreviado: primera palabra completa e
     * inicial de la segunda.
     *
     * No se intenta localizar el apellido: con "María Elena Pérez Quispe" sale
     * "María E.", no "María P.". Es deliberado — de una sola cadena no se puede
     * saber cuántos nombres de pila trae, y equivocarse hacia el otro lado
     * significaría publicar un apellido entero.
     */
    public function test_un_donante_visible_aparece_con_el_nombre_abreviado(): void
    {
        $this->fondoDePrueba();

        $this->aprobar($this->donacionPendiente([
            'nombre' => 'María Pérez Quispe',
            'visible_publico' => true,
        ]));

        $respuesta = $this->getJson(self::RUTA_API)->assertOk();

        $this->assertSame('María P.', $respuesta->json('recientes.0.nombre'));
        $this->assertFalse($respuesta->json('recientes.0.anonimo'));

        // Ningún apellido completo sale.
        $plano = implode('|', $this->todosLosValores($respuesta->json()));
        $this->assertStringNotContainsString('Pérez', $plano);
        $this->assertStringNotContainsString('Quispe', $plano);
    }

    /** Con dos nombres de pila se queda con la inicial del segundo. */
    public function test_con_dos_nombres_de_pila_la_inicial_es_la_del_segundo_nombre(): void
    {
        $this->fondoDePrueba();

        $this->aprobar($this->donacionPendiente([
            'nombre' => 'María Elena Pérez Quispe',
            'visible_publico' => true,
        ]));

        $this->getJson(self::RUTA_API)
            ->assertOk()
            ->assertJsonPath('recientes.0.nombre', 'María E.');
    }

    // ── AT-82 a AT-84: qué fondos salen y cómo ───────────────────────────────

    public function test_at82_un_fondo_en_borrador_no_aparece(): void
    {
        $this->fondoDePrueba(['slug' => 'publicado', 'estado' => EstadoFondo::ACTIVO]);
        $this->fondoDePrueba(['slug' => 'en-borrador', 'estado' => EstadoFondo::BORRADOR, 'es_predeterminado' => false]);

        $slugs = collect($this->getJson(self::RUTA_API)->assertOk()->json('fondos'))->pluck('slug');

        $this->assertContains('publicado', $slugs);
        $this->assertNotContains('en-borrador', $slugs);
    }

    /** Un fondo cerrado se sigue enseñando: es rendición de cuentas. */
    public function test_at83_un_fondo_cerrado_si_aparece_con_su_recaudado(): void
    {
        $cerrado = $this->fondoDePrueba(['slug' => 'campana-terminada', 'estado' => EstadoFondo::ACTIVO]);
        $this->aprobar($this->donacionPendiente(['fondo_id' => $cerrado->id]), 320.00);

        $cerrado->forceFill(['estado' => EstadoFondo::CERRADO])->save();
        $this->app->make(ConstruirDashboard::class)->olvidarCache();

        $fondos = collect($this->getJson(self::RUTA_API)->assertOk()->json('fondos'))->keyBy('slug');

        $this->assertArrayHasKey('campana-terminada', $fondos->all());
        // json_decode devuelve 320 (int) para un 320.0: se compara el número.
        $this->assertSame(320.0, (float) $fondos['campana-terminada']['recaudado']);
        $this->assertSame('cerrado', $fondos['campana-terminada']['estado']);
        $this->assertFalse($fondos['campana-terminada']['acepta_donaciones']);
    }

    /** Sin meta no hay porcentaje: la barra se oculta, no enseña un 0 %. */
    public function test_at84_sin_meta_el_porcentaje_es_nulo(): void
    {
        $this->fondoDePrueba(['slug' => 'sin-meta', 'meta' => null]);
        $this->fondoDePrueba(['slug' => 'con-meta', 'meta' => 1000, 'es_predeterminado' => false]);

        $fondos = collect($this->getJson(self::RUTA_API)->assertOk()->json('fondos'))->keyBy('slug');

        $this->assertNull($fondos['sin-meta']['meta']);
        $this->assertNull($fondos['sin-meta']['porcentaje']);

        $this->assertSame(1000.0, (float) $fondos['con-meta']['meta']);
        $this->assertNotNull($fondos['con-meta']['porcentaje']);
        $this->assertSame(0.0, (float) $fondos['con-meta']['porcentaje']);
    }

    // ── AT-85: caché ─────────────────────────────────────────────────────────

    public function test_at85_la_segunda_llamada_no_vuelve_a_consultar_la_base(): void
    {
        $this->fondoDePrueba();
        $this->aprobar($this->donacionPendiente());

        $this->getJson(self::RUTA_API)->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson(self::RUTA_API)->assertOk();

        $agregaciones = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(static fn (string $sql): bool => str_contains($sql, 'donaciones') || str_contains($sql, 'fondos'));

        DB::disableQueryLog();

        $this->assertCount(0, $agregaciones, 'La segunda llamada volvió a agregar en vez de leer la caché.');
    }

    // ── AT-86 a AT-89: páginas ───────────────────────────────────────────────

    /** 404 y no 403: la existencia de un borrador no es pública. */
    public function test_at86_la_pagina_de_un_fondo_en_borrador_da_404(): void
    {
        $borrador = $this->fondoDePrueba(['slug' => 'aun-no-publicado', 'estado' => EstadoFondo::BORRADOR]);

        $this->get(route('fondos.mostrar', $borrador))->assertNotFound();
    }

    public function test_la_pagina_de_un_fondo_publicado_carga(): void
    {
        $fondo = $this->fondoDePrueba(['slug' => 'visible', 'estado' => EstadoFondo::ACTIVO]);

        $this->get(route('fondos.mostrar', $fondo))
            ->assertOk()
            ->assertSee($fondo->nombre)
            ->assertSee('fondo--teal', false);
    }

    public function test_at87_con_dos_fondos_activos_se_muestra_el_selector(): void
    {
        $uno = $this->fondoDePrueba(['slug' => 'agua', 'nombre' => 'Agua limpia']);
        $dos = $this->fondoDePrueba(['slug' => 'escuela', 'nombre' => 'Escuela rural', 'es_predeterminado' => false]);

        $html = $this->html(route('donar'));

        $this->assertStringContainsString('Agua limpia', $html);
        $this->assertStringContainsString('Escuela rural', $html);
        $this->assertStringContainsString('data-donacion-continuar', $html);

        // Ninguno viene marcado: hay que elegir.
        $this->assertSame(0, $this->radiosDeFondoMarcados($html));
        $this->assertStringContainsString('value="'.$uno->id.'"', $html);
        $this->assertStringContainsString('value="'.$dos->id.'"', $html);
    }

    public function test_at88_con_un_solo_fondo_activo_se_preselecciona(): void
    {
        $unico = $this->fondoDePrueba(['slug' => 'el-unico', 'nombre' => 'Proyecto único']);

        $html = $this->html(route('donar'));

        $this->assertStringContainsString('value="'.$unico->id.'" checked', $html);

        // Con un solo fondo no hace falta el botón de continuar.
        $this->assertStringNotContainsString('data-donacion-continuar', $html);
    }

    public function test_at89_donar_con_slug_preselecciona_ese_fondo(): void
    {
        $this->fondoDePrueba(['slug' => 'agua', 'nombre' => 'Agua limpia']);
        $elegido = $this->fondoDePrueba(['slug' => 'escuela', 'nombre' => 'Escuela rural', 'es_predeterminado' => false]);

        $html = $this->html(route('donar.fondo', $elegido));

        $this->assertStringContainsString('value="'.$elegido->id.'" checked', $html);
        $this->assertSame(
            1,
            $this->radiosDeFondoMarcados($html),
            'Debería haber exactamente un fondo preseleccionado.'
        );
    }

    /** Un fondo cerrado en la URL avisa, en vez de bloquear el formulario. */
    public function test_donar_con_un_fondo_cerrado_avisa_y_no_lo_preselecciona(): void
    {
        $this->fondoDePrueba(['slug' => 'abierto']);
        $cerrado = $this->fondoDePrueba([
            'slug' => 'cerrado',
            'nombre' => 'Campaña terminada',
            'estado' => EstadoFondo::CERRADO,
            'es_predeterminado' => false,
        ]);

        $this->get(route('donar.fondo', $cerrado))
            ->assertOk()
            ->assertSee('ya no está recibiendo donaciones', false);
    }

    // ── Portada ──────────────────────────────────────────────────────────────

    /**
     * La tarjeta de «Programa destacado» llevaba las cifras escritas a mano en
     * la plantilla: S/ 26.000 recaudados sobre una meta de S/ 257.000, y un
     * 10,1 % de avance que ademas vivia en el CSS. Ninguna correspondia a una
     * donacion real.
     */
    public function test_la_portada_no_contiene_ninguna_de_las_cifras_inventadas(): void
    {
        $this->fondoDePrueba(['slug' => 'fundacion-antonia', 'nombre' => 'Fondo Antonia']);

        $html = $this->html('/');

        foreach (['26,000', '257,000', '10.1%', '24 beneficiarios'] as $inventada) {
            $this->assertStringNotContainsString($inventada, $html, "Sigue incrustada la cifra «{$inventada}».");
        }
    }

    /** El boton de donar lleva a la pasarela, no a WhatsApp. */
    public function test_el_boton_de_la_portada_lleva_al_formulario_de_donacion(): void
    {
        $fondo = $this->fondoDePrueba(['slug' => 'fundacion-antonia']);

        $html = $this->html('/');

        $this->assertStringContainsString('href="'.route('donar.fondo', $fondo).'"', $html);

        // El texto del boton ya no puede colgar de un enlace de WhatsApp.
        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]*wa\.me[^>]*>\s*Quiero donar/',
            $html,
            'El boton «Quiero donar» sigue apuntando a WhatsApp.'
        );
    }

    /** Lo recaudado que se publica es lo que hay en la base. */
    public function test_la_portada_muestra_el_recaudado_real_del_fondo_destacado(): void
    {
        $fondo = $this->fondoDePrueba(['slug' => 'fundacion-antonia']);

        $this->assertStringContainsString('S/ 0.00', $this->html('/'));

        $this->aprobar($this->donacionPendiente(['fondo_id' => $fondo->id]), 250.00);

        $this->assertStringContainsString('S/ 250.00', $this->html('/'));
    }

    /** Sin meta no hay barra: un 0 % en portada se lee como un fracaso. */
    public function test_sin_meta_la_portada_no_pinta_barra_de_progreso(): void
    {
        $this->fondoDePrueba(['slug' => 'fundacion-antonia', 'meta' => null]);

        $html = $this->html('/');

        $this->assertStringNotContainsString('data-fondo-progreso', $html);
        $this->assertStringNotContainsString('de avance', $html);
    }

    public function test_con_meta_la_portada_pinta_el_avance_real(): void
    {
        $fondo = $this->fondoDePrueba(['slug' => 'fundacion-antonia', 'meta' => 1000]);
        $this->aprobar($this->donacionPendiente(['fondo_id' => $fondo->id]), 250.00);

        $html = $this->html('/');

        $this->assertStringContainsString('data-fondo-progreso', $html);
        $this->assertStringContainsString('width: 25%', $html);
        $this->assertStringContainsString('<b>25%</b> de avance', $html);
    }

    /** Un fondo en borrador no puede salir anunciado en la portada. */
    public function test_la_portada_no_destaca_un_fondo_en_borrador(): void
    {
        $this->fondoDePrueba([
            'slug' => 'aun-no-publicado',
            'nombre' => 'Fondo sin publicar',
            'estado' => EstadoFondo::BORRADOR,
        ]);

        $html = $this->html('/');

        $this->assertStringNotContainsString('Fondo sin publicar', $html);
        $this->assertStringNotContainsString('PROGRAMA DESTACADO', $html);
    }

    /**
     * Los tres «Súmate» de la portada llevan a donar, no a WhatsApp.
     *
     * Y el destino se resuelve por el fondo marcado como predeterminado: un
     * slug escrito a mano en la plantilla se queda apuntando a una campaña
     * cerrada en cuanto cambie el destacado.
     */
    public function test_los_botones_sumate_llevan_al_fondo_destacado(): void
    {
        $destacado = $this->fondoDePrueba(['slug' => 'el-destacado', 'nombre' => 'El destacado']);

        $html = $this->html('/');

        $this->assertSame(
            3,
            substr_count($html, 'href="'.route('donar.fondo', $destacado).'"') - 1,
            'Los tres «Súmate» deberían apuntar al fondo destacado (el cuarto enlace es el botón de la tarjeta).'
        );

        // «Conversemos» es otra intención y se queda donde estaba.
        $this->assertStringContainsString('wa.me', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*wa\.me[^>]*>\s*Súmate/u', $html);
    }

    /** Si el fondo destacado deja de aceptar donaciones, se cae al selector. */
    public function test_sumate_cae_al_selector_si_el_destacado_esta_cerrado(): void
    {
        $this->fondoDePrueba([
            'slug' => 'ya-cerrado',
            'nombre' => 'Ya cerrado',
            'estado' => EstadoFondo::CERRADO,
        ]);

        $html = $this->html('/');

        $this->assertStringContainsString('href="'.route('donar').'"', $html);
        $this->assertStringNotContainsString('href="'.route('donar').'/ya-cerrado"', $html);
    }

    // ── AT-90: el feed ───────────────────────────────────────────────────────

    public function test_at90_el_feed_solo_trae_donaciones_que_cuentan(): void
    {
        $fondo = $this->fondoDePrueba();

        $aprobada = $this->donacionPendiente(['nombre' => 'Aprobada Visible', 'correo' => 'a@ejemplo.com']);
        $this->aprobar($aprobada, 100.00, 9001);

        // Pendiente y rechazada: ninguna debe salir.
        $this->donacionPendiente(['nombre' => 'Pendiente Invisible', 'correo' => 'b@ejemplo.com']);

        $rechazada = $this->donacionPendiente(['nombre' => 'Rechazada Invisible', 'correo' => 'c@ejemplo.com']);
        MercadoPagoConfig::setHttpClient(
            (new ClienteHttpMercadoPagoFalso)->conPago(9002, 'rejected', (string) $rechazada->id)
        );
        $this->app->forgetInstance(PaymentClient::class);
        $this->app->make(ReconciliarDonacion::class)(
            $this->app->make(ConsultarPago::class)('9002'),
            'webhook'
        );

        $this->assertSame(EstadoDonacion::RECHAZADO, $rechazada->refresh()->estado);

        $recientes = $this->getJson(self::RUTA_API)->assertOk()->json('recientes');

        $this->assertCount(1, $recientes);
        $this->assertSame('Aprobada V.', $recientes[0]['nombre']);
        $this->assertSame($fondo->slug, $recientes[0]['fondo']);
        $this->assertNotEmpty($recientes[0]['hace']);
    }

    // ── AT-91: resultado sin JavaScript ──────────────────────────────────────

    public function test_at91_la_pantalla_de_resultado_dice_algo_util_sin_javascript(): void
    {
        $this->fondoDePrueba();

        $this->get(route('donacion.resultado', ['donacion' => 'exitosa']))
            ->assertOk()
            ->assertSee('Estamos confirmando tu donación');

        $this->get(route('donacion.resultado', ['donacion' => 'fallida']))
            ->assertOk()
            ->assertSee('El pago no se completó');

        $this->get(route('donacion.resultado', ['donacion' => 'pendiente']))
            ->assertOk()
            ->assertSee('Estamos confirmando tu pago');

        // Sin parámetro tampoco se rompe.
        $this->get(route('donacion.resultado'))
            ->assertOk()
            ->assertSee('Comprobando tu donación');
    }

    /**
     * Ni siquiera con `?donacion=exitosa` el HTML afirma que el pago se
     * completó: dice que se está confirmando. Quien decide es el servidor.
     */
    public function test_el_html_provisional_nunca_afirma_que_el_pago_se_completo(): void
    {
        $html = $this->html(route('donacion.resultado', ['donacion' => 'exitosa', 'status' => 'approved']));

        $this->assertStringNotContainsString('¡Gracias por tu aporte!', $html);
        $this->assertStringContainsString('Estamos confirmando', $html);
    }

    // ── AT-92 y AT-93: forma ─────────────────────────────────────────────────

    public function test_at92_los_montos_sugeridos_salen_de_la_configuracion(): void
    {
        $this->fondoDePrueba();

        config(['donaciones.montos_sugeridos' => [7, 33, 111]]);

        $html = $this->html(route('donar'));

        foreach ([7, 33, 111] as $sugerido) {
            $this->assertStringContainsString('data-monto="'.$sugerido.'"', $html);
        }

        // Y los de por defecto ya no están: no estaban incrustados en el Blade.
        $this->assertStringNotContainsString('data-monto="50"', $html);
    }

    /** El color lo decide el CSS. Ni un hex en lo que se envía al navegador. */
    public function test_at93_el_html_renderizado_no_contiene_ningun_color_hex(): void
    {
        $fondo = $this->fondoDePrueba();
        $this->aprobar($this->donacionPendiente());

        foreach ([
            route('donar'),
            route('donar.fondo', $fondo),
            route('fondos.mostrar', $fondo),
            route('donacion.resultado', ['donacion' => 'exitosa']),
        ] as $url) {
            // Las entidades numéricas (&#039;) llevan almohadilla y dígitos:
            // fuera antes de buscar, o darían un falso positivo.
            $html = (string) preg_replace('/&#\d+;/', '', $this->html($url));

            // Única excepción: el <meta name="theme-color">, que pinta la barra
            // del navegador en móvil y no puede referirse a una variable CSS.
            // Se comprueba aparte, en el test siguiente.
            $html = (string) preg_replace('/<meta name="theme-color"[^>]*>/', '', $html);

            $this->assertSame(
                0,
                preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $html),
                "Hay un color hex en el HTML de {$url}."
            );
        }
    }

    /**
     * El único color literal del proyecto tiene que seguir siendo el token.
     *
     * Un `<meta>` no puede usar var(--navy-deep), así que ese valor se escribe
     * a mano. Este caso existe para que, si alguien cambia la paleta, se entere
     * de que también hay que cambiarlo aquí — que es justo lo que no había
     * pasado: el meta llevaba un navy que ya no existía en tokens.css.
     */
    public function test_el_theme_color_coincide_con_el_token_de_marca(): void
    {
        $this->fondoDePrueba();

        $tokens = (string) file_get_contents(resource_path('css/tokens.css'));

        $this->assertSame(
            1,
            preg_match('/--navy-deep:\s*(#[0-9a-fA-F]{3,8})\s*;/', $tokens, $coincidencias),
            'No se encontró --navy-deep en tokens.css.'
        );

        $this->assertStringContainsString(
            '<meta name="theme-color" content="'.$coincidencias[1].'">',
            $this->html(route('donar')),
            'El theme-color se desvió de --navy-deep.'
        );
    }

    // ── AT-94: el navegador no puede afirmar un estado ───────────────────────

    /**
     * Es LA regla de la segunda red: la URL de retorno la puede escribir
     * cualquiera. Aunque el cliente grite que el pago fue aprobado, manda lo
     * que diga Mercado Pago.
     */
    public function test_at94_un_status_approved_falso_en_la_peticion_no_aprueba_nada(): void
    {
        $fondo = $this->fondoDePrueba();
        $donacion = $this->donacionPendiente();

        // Mercado Pago dice que el pago fue RECHAZADO.
        MercadoPagoConfig::setHttpClient(
            (new ClienteHttpMercadoPagoFalso)->conPago(9700, 'rejected', (string) $donacion->id, 500.00)
        );
        $this->app->forgetInstance(PaymentClient::class);

        // El cliente insiste en lo contrario, por todos los medios.
        $this->postJson('/api/donaciones/reconciliar', [
            'payment_id' => 9700,
            'donacion_id' => $donacion->id,
            'status' => 'approved',
            'estado' => 'aprobado',
            'collection_status' => 'approved',
            'monto_real' => 999999,
        ])
            ->assertOk()
            ->assertJson(['estado' => 'rechazado']);

        $donacion->refresh();

        $this->assertSame(EstadoDonacion::RECHAZADO, $donacion->estado);
        $this->assertNull($donacion->monto_real);
        $this->assertSame(0.0, (float) $fondo->refresh()->recaudado);
        $this->assertSame(0, $fondo->donaciones_count);
    }
}
