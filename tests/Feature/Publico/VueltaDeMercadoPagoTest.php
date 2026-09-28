<?php

declare(strict_types=1);

namespace Tests\Feature\Publico;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDonaciones;
use Tests\TestCase;

/**
 * El viaje de ida y vuelta a Mercado Pago.
 *
 * Son los dos momentos en que más gente abandona: el salto a un dominio
 * desconocido, y la pantalla a la que se vuelve sin saber si se pagó.
 *
 * Lo que se puede comprobar desde el servidor es el ANDAMIO: que la capa de
 * tránsito exista y nazca oculta, que la pantalla de resultado tenga sus
 * huecos, y —lo más importante— que el HTML que renderiza el servidor NUNCA
 * afirme que un pago se completó.
 */
final class VueltaDeMercadoPagoTest extends TestCase
{
    use CreaDonaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
        $this->fondoDePrueba();
    }

    // ── La ida ───────────────────────────────────────────────────────────────

    public function test_la_capa_de_transito_existe_y_nace_oculta(): void
    {
        $html = (string) $this->get(route('donar'))->assertOk()->getContent();

        $this->assertStringContainsString('data-transito', $html);
        $this->assertStringContainsString('data-transito-monto', $html);

        // Nace oculta: sin JavaScript nunca se llega a saltar, así que
        // enseñarla sería mentir sobre lo que está pasando.
        $this->assertMatchesRegularExpression('/<div class="don-transito"[^>]*\bhidden\b/', $html);

        // Y el enlace manual, para cuando el salto no ocurre.
        $this->assertStringContainsString('data-transito-enlace', $html);
    }

    /** Lo que quita el miedo es saber que se vuelve. */
    public function test_la_capa_de_transito_dice_que_va_a_volver(): void
    {
        $html = (string) $this->get(route('donar'))->assertOk()->getContent();

        $this->assertStringContainsString('Te estamos llevando a Mercado Pago', $html);
        $this->assertStringContainsString('volverás aquí', $html);
        $this->assertStringContainsString('No guardamos tu tarjeta', $html);
    }

    /** No tiene botón de cerrar: no hay nada que decidir. */
    public function test_la_capa_de_transito_no_ofrece_cancelar(): void
    {
        $html = (string) $this->get(route('donar'))->assertOk()->getContent();

        preg_match('/<div class="don-transito".*?<\/div>\s*<\/div>/s', $html, $capa);

        $this->assertNotEmpty($capa);
        $this->assertStringNotContainsString('Cancelar', $capa[0]);
    }

    // ── La vuelta ────────────────────────────────────────────────────────────

    public function test_la_pantalla_de_resultado_tiene_sus_huecos(): void
    {
        $html = (string) $this->get(route('donacion.resultado', ['donacion' => 'exitosa']))
            ->assertOk()
            ->getContent();

        foreach ([
            'data-resultado-icono',
            'data-resultado-estado',
            'data-resultado-titulo',
            'data-resultado-cuerpo',
            'data-resultado-detalle',
            'data-resultado-cargando',
            'data-resultado-acciones',
        ] as $hueco) {
            $this->assertStringContainsString($hueco, $html, "Falta el hueco «{$hueco}».");
        }
    }

    /**
     * LA REGLA QUE NO SE PUEDE ROMPER.
     *
     * El `?donacion=` de la URL lo puede escribir cualquiera. El servidor pinta
     * un mensaje provisional con él, y ese mensaje jamás puede afirmar que un
     * pago se completó — ni siquiera un instante, ni siquiera cuando la URL
     * viene además con `status=approved`.
     *
     * @dataProvider urlesFalsificadas
     */
    public function test_el_servidor_nunca_afirma_que_el_pago_se_completo(array $parametros): void
    {
        $html = (string) $this->get(route('donacion.resultado', $parametros))->assertOk()->getContent();

        foreach ([
            '¡Gracias! Tu aporte está confirmado',
            'Tu aporte está confirmado',
            'ya suma en el contador',
            'El banco no autorizó',
        ] as $afirmacion) {
            $this->assertStringNotContainsString($afirmacion, $html, 'El servidor afirmó un estado.');
        }

        // Lo que sí dice es que está comprobando o confirmando: un estado en
        // curso, nunca un veredicto.
        $this->assertMatchesRegularExpression(
            '/Comprobando tu donación|confirmando tu (donación|pago)/u',
            $html,
            'El mensaje provisional no dice que la comprobación está en curso.'
        );
    }

    /** @return list<array{0: array<string, string>}> */
    public static function urlesFalsificadas(): array
    {
        return [
            [['donacion' => 'exitosa']],
            [['donacion' => 'exitosa', 'status' => 'approved']],
            [['donacion' => 'exitosa', 'collection_status' => 'approved', 'payment_id' => '999']],
            [['status' => 'approved']],
        ];
    }

    /** Sin JavaScript, las dos salidas de siempre siguen ahí. */
    public function test_sin_javascript_quedan_dos_salidas(): void
    {
        $html = (string) $this->get(route('donacion.resultado'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('donar').'"', $html);
        $this->assertStringContainsString('href="'.route('home').'"', $html);
    }
}
