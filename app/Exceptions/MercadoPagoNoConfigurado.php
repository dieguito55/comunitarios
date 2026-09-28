<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Falta el access token de Mercado Pago.
 *
 * Es un error de despliegue, no del donante: se distingue del resto para que el
 * log diga exactamente qué falta en lugar de dejar un 500 genérico o, peor, un
 * error de MP indescifrable.
 */
final class MercadoPagoNoConfigurado extends RuntimeException
{
    public static function faltaAccessToken(): self
    {
        return new self('MP_ACCESS_TOKEN no está configurado: no se puede crear la preferencia.');
    }
}
