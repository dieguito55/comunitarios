<?php

declare(strict_types=1);

namespace Tests\Feature\Publico;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las páginas de error.
 *
 * Hasta ahora un 404 o un 500 mostraban la pantalla gris que trae Laravel, que
 * no se parece en nada al sitio. Quien se topa con una ya está teniendo un mal
 * momento —un enlace roto, una sesión caducada a mitad de una donación— y una
 * pantalla ajena lo empeora: parece que se equivocó de web, o que algo se
 * perdió.
 *
 * Lo que estos casos vigilan: que las cinco existan, que se vean como el sitio,
 * que digan qué hacer, y que NUNCA enseñen un detalle técnico.
 */
final class PaginasDeErrorTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string}> */
    public static function codigos(): array
    {
        return [['404'], ['419'], ['429'], ['500'], ['503']];
    }

    /**
     * @dataProvider codigos
     */
    public function test_cada_pagina_de_error_se_renderiza_con_el_diseno_del_sitio(string $codigo): void
    {
        $html = view('errors.'.$codigo)->render();

        // Es el sitio, no una pantalla ajena: cabecera, pie y su tipografía.
        $this->assertStringContainsString('site-header', $html);
        $this->assertStringContainsString('site-footer', $html);
        $this->assertStringContainsString('don-resultado', $html);

        // Un titular y un icono.
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('data-lucide', $html);

        // Y no se indexa: una página de error en el buscador no ayuda a nadie.
        $this->assertStringContainsString('noindex', $html);
    }

    /**
     * @dataProvider codigos
     */
    public function test_ninguna_pagina_de_error_filtra_detalles_tecnicos(string $codigo): void
    {
        $html = mb_strtolower(view('errors.'.$codigo)->render());

        foreach ([
            'stack trace',
            'exception',
            'vendor\\laravel',
            'c:\\xampp',
            '.php:',
            'sqlstate',
        ] as $tecnico) {
            $this->assertStringNotContainsString($tecnico, $html, "La página {$codigo} filtra «{$tecnico}».");
        }
    }

    /** La de sesión caducada es la más delicada: nadie puede creer que perdió dinero. */
    public function test_la_pagina_de_sesion_caducada_tranquiliza_sobre_el_dinero(): void
    {
        $html = view('errors.419')->render();

        $this->assertStringContainsString('No se envió nada y no se cobró nada', $html);
        $this->assertStringContainsString('Tu información sigue en el navegador', $html);
        $this->assertStringContainsString(route('donar'), $html);
    }

    public function test_la_de_404_ofrece_salidas_utiles(): void
    {
        $html = view('errors.404')->render();

        $this->assertStringContainsString(route('donar'), $html);
        $this->assertStringContainsString(route('home'), $html);
    }

    /** Una URL que no existe devuelve NUESTRA página, no la de Laravel. */
    public function test_una_url_inexistente_devuelve_la_pagina_del_sitio(): void
    {
        $respuesta = $this->get('/esta-url-no-existe-en-ninguna-parte');

        $respuesta->assertNotFound();
        $respuesta->assertSee('No encontramos esta página');
        $respuesta->assertSee('site-footer', false);
    }

    /**
     * Las rutas de API siguen respondiendo JSON.
     *
     * Devolverles HTML rompería a cualquier cliente que las consuma, empezando
     * por el propio formulario de donación.
     */
    public function test_las_rutas_de_api_siguen_devolviendo_json(): void
    {
        $this->getJson('/api/esta-ruta-no-existe')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
    }
}
