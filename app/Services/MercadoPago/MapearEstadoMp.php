<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Enums\EstadoDonacion;

/**
 * ÚNICA fuente de verdad del mapeo estado de Mercado Pago → estado interno.
 *
 * La usan idéntica el webhook, la reconciliación y la auditoría. Si cada una
 * tuviera su propia traducción, una donación podría quedar aprobada para el
 * dashboard y rechazada para la rendición de cuentas.
 *
 *   approved                            → aprobado
 *   rejected, cancelled, charged_back   → rechazado
 *   todo lo demás                       → en_proceso
 *
 * Lo desconocido cae en en_proceso a propósito: ante un estado que no
 * reconocemos, no damos por perdido ni por cobrado el dinero.
 */
final class MapearEstadoMp
{
    public function __invoke(?string $estadoMp): EstadoDonacion
    {
        return match (mb_strtolower(trim((string) $estadoMp))) {
            'approved' => EstadoDonacion::APROBADO,
            'rejected', 'cancelled', 'charged_back' => EstadoDonacion::RECHAZADO,
            default => EstadoDonacion::EN_PROCESO,
        };
    }
}
