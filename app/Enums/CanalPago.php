<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por dónde entró el dinero. Los tres canales conviven en la tabla `donaciones`.
 *
 * Se persiste como VARCHAR, nunca como ENUM de MySQL: añadir un caso nuevo a un
 * ENUM exige un ALTER TABLE bloqueante sobre la tabla de donaciones.
 */
enum CanalPago: string
{
    /** Checkout Pro: tarjeta, Yape o PagoEfectivo dentro de la pasarela de MP. */
    case MERCADOPAGO = 'mercadopago';

    /** El donante transfiere al QR estático de la organización y sube la captura. */
    case QR_MANUAL = 'qr_manual';

    /** Un admin registra el aporte desde tesorería. */
    case EFECTIVO_MANUAL = 'efectivo_manual';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MERCADOPAGO => 'Mercado Pago',
            self::QR_MANUAL => 'Yape / Plin (QR)',
            self::EFECTIVO_MANUAL => 'Efectivo / Transferencia',
        };
    }

    /** true si el estado lo decide Mercado Pago y no una persona. */
    public function esAutomatico(): bool
    {
        return $this === self::MERCADOPAGO;
    }
}
