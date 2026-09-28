<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida de un fondo.
 *
 * La distinción importante está entre "acepta dinero" y "se ve". Un fondo
 * pausado o cerrado SIGUE siendo público con todo lo que recaudó: es rendición
 * de cuentas, y hacer desaparecer una campaña terminada borraría el rastro del
 * dinero que la gente ya dio.
 */
enum EstadoFondo: string
{
    /** Todavía se está redactando. No se ve ni recibe. */
    case BORRADOR = 'borrador';

    /** Abierto: se ve y recibe donaciones. */
    case ACTIVO = 'activo';

    /** Se ve con lo recaudado, pero no admite aportes nuevos. */
    case PAUSADO = 'pausado';

    /** Terminado. Se ve como rendición de cuentas. */
    case CERRADO = 'cerrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::BORRADOR => 'Borrador',
            self::ACTIVO => 'Activo',
            self::PAUSADO => 'Pausado',
            self::CERRADO => 'Cerrado',
        };
    }

    public function aceptaDonaciones(): bool
    {
        return $this === self::ACTIVO;
    }

    public function visiblePublicamente(): bool
    {
        return $this !== self::BORRADOR;
    }
}
