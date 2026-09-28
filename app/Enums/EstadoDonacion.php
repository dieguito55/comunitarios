<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado interno de una donación. Fuente única: el webhook, la reconciliación y
 * la auditoría escriben aquí a través de MapearEstadoMp, nunca cada uno por su
 * cuenta.
 *
 * Máquina de estados:
 *
 *   pendiente  → aprobado | en_proceso | rechazado
 *   en_proceso → aprobado | rechazado
 *   aprobado   → terminal (salvo corrección manual de un superadmin)
 *   rechazado  → terminal (salvo corrección manual de un superadmin)
 */
enum EstadoDonacion: string
{
    /** Registrada, sin confirmación de dinero. Estado inicial de MP y de QR. */
    case PENDIENTE = 'pendiente';

    /** Dinero confirmado. Lo único que se rinde en cuentas. */
    case APROBADO = 'aprobado';

    /** Rechazado, cancelado o con contracargo. */
    case RECHAZADO = 'rechazado';

    /** MP lo tiene en revisión (pending, in_process, authorized, in_mediation). */
    case EN_PROCESO = 'en_proceso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente',
            self::APROBADO => 'Aprobado',
            self::RECHAZADO => 'Rechazado',
            self::EN_PROCESO => 'En proceso',
        };
    }

    /**
     * Si este estado representa dinero efectivamente recibido.
     *
     * Solo APROBADO. Es rendición de cuentas de una fundación: un total que baja
     * porque un pago se rechazó genera desconfianza.
     */
    public function cuentaParaTotal(): bool
    {
        return $this === self::APROBADO;
    }

    /**
     * Clase de estado para la interfaz. El JS y el Blade emiten esta clase; el
     * color lo decide resources/css/app.css (deuda técnica 3: cero hex en JS).
     */
    public function claseCss(): string
    {
        return 'estado--'.$this->value;
    }

    /** Estados que ya no cambian solos; solo un superadmin puede corregirlos. */
    public function esTerminal(): bool
    {
        return $this === self::APROBADO || $this === self::RECHAZADO;
    }

    /** Valida una transición antes de escribirla. Ver la máquina de estados. */
    public function puedeTransicionarA(self $destino): bool
    {
        if ($destino === $this) {
            return false;
        }

        return match ($this) {
            self::PENDIENTE => true,
            self::EN_PROCESO => $destino === self::APROBADO || $destino === self::RECHAZADO,
            self::APROBADO, self::RECHAZADO => false,
        };
    }

    /**
     * Estados que suman en el dashboard público.
     *
     * Deuda técnica 6: DASHBOARD_INCLUDE_PENDING existía en el sistema original
     * pero no se usaba. Aquí manda de verdad. Con `false` (el valor por defecto)
     * solo cuenta el dinero confirmado.
     *
     * @return array<int, self>
     */
    public static function contablesPublicos(bool $incluirPendientes): array
    {
        if (! $incluirPendientes) {
            return [self::APROBADO];
        }

        return [self::APROBADO, self::PENDIENTE, self::EN_PROCESO];
    }
}
