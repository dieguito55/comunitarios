<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Quién movió el dinero, dentro del canal. Un mismo canal puede tener varios
 * proveedores: `qr_manual` es Yape o Plin; `efectivo_manual` es caja o banco.
 */
enum ProveedorPago: string
{
    case MERCADOPAGO = 'mercadopago';
    case YAPE_QR = 'yape_qr';
    case PLIN_QR = 'plin_qr';
    case EFECTIVO = 'efectivo';
    case TRANSFERENCIA = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MERCADOPAGO => 'Mercado Pago',
            self::YAPE_QR => 'Yape',
            self::PLIN_QR => 'Plin',
            self::EFECTIVO => 'Efectivo',
            self::TRANSFERENCIA => 'Transferencia bancaria',
        };
    }

    /** Canal al que pertenece este proveedor. */
    public function canal(): CanalPago
    {
        return match ($this) {
            self::MERCADOPAGO => CanalPago::MERCADOPAGO,
            self::YAPE_QR, self::PLIN_QR => CanalPago::QR_MANUAL,
            self::EFECTIVO, self::TRANSFERENCIA => CanalPago::EFECTIVO_MANUAL,
        };
    }
}
