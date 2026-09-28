<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoDonacion;
use App\Services\MercadoPago\MapearEstadoMp;
use PHPUnit\Framework\TestCase;

/**
 * El mapeo de estados es la única fuente que comparten el webhook, la
 * reconciliación y la auditoría. Si se desvía, una misma donación puede quedar
 * aprobada para el dashboard y rechazada para la rendición de cuentas.
 */
final class MapearEstadoMpTest extends TestCase
{
    public function test_approved_es_el_unico_que_aprueba(): void
    {
        $mapear = new MapearEstadoMp;

        $this->assertSame(EstadoDonacion::APROBADO, $mapear('approved'));
        $this->assertTrue($mapear('approved')->cuentaParaTotal());
    }

    public function test_rechazos_cancelaciones_y_contracargos(): void
    {
        $mapear = new MapearEstadoMp;

        foreach (['rejected', 'cancelled', 'charged_back'] as $estadoMp) {
            $this->assertSame(EstadoDonacion::RECHAZADO, $mapear($estadoMp), $estadoMp);
            $this->assertFalse($mapear($estadoMp)->cuentaParaTotal(), $estadoMp);
        }
    }

    public function test_todo_lo_demas_queda_en_proceso(): void
    {
        $mapear = new MapearEstadoMp;

        foreach (['pending', 'in_process', 'authorized', 'in_mediation', 'refunded'] as $estadoMp) {
            $this->assertSame(EstadoDonacion::EN_PROCESO, $mapear($estadoMp), $estadoMp);
        }
    }

    /**
     * Ante un estado desconocido, nulo o vacío no damos el dinero por perdido
     * ni por cobrado: queda en proceso para que alguien lo mire.
     */
    public function test_lo_desconocido_no_se_da_por_perdido_ni_por_cobrado(): void
    {
        $mapear = new MapearEstadoMp;

        $this->assertSame(EstadoDonacion::EN_PROCESO, $mapear(null));
        $this->assertSame(EstadoDonacion::EN_PROCESO, $mapear(''));
        $this->assertSame(EstadoDonacion::EN_PROCESO, $mapear('un_estado_que_mp_invente_manana'));
    }

    public function test_no_distingue_mayusculas_ni_espacios(): void
    {
        $mapear = new MapearEstadoMp;

        $this->assertSame(EstadoDonacion::APROBADO, $mapear('  APPROVED '));
        $this->assertSame(EstadoDonacion::RECHAZADO, $mapear('Charged_Back'));
    }
}
