<?php

declare(strict_types=1);

namespace App\Services\Donaciones;

use App\Enums\EstadoDonacion;
use App\Models\Donacion;

/**
 * Qué pasó al reconciliar un pago con su donación.
 *
 * Lo consumen el webhook (red 1), el endpoint público (red 2) y el comando de
 * auditoría (red 3). Cada uno decide qué hacer con el mismo veredicto: el
 * webhook elige un código HTTP, el endpoint público enseña un estado y la
 * auditoría cuenta los rescates.
 */
final readonly class ResultadoReconciliacion
{
    public const ACTUALIZADO = 'actualizado';

    public const SIN_CAMBIOS = 'sin_cambios';

    public const OBSOLETO = 'obsoleto';

    public const CONFLICTO = 'conflicto';

    public const HUERFANO = 'huerfano';

    private function __construct(
        public string $resultado,
        public ?Donacion $donacion,
        public ?EstadoDonacion $estadoAnterior,
        public ?EstadoDonacion $estadoNuevo,
        public string $motivo,
    ) {}

    public static function actualizado(Donacion $donacion, EstadoDonacion $anterior, EstadoDonacion $nuevo): self
    {
        return new self(self::ACTUALIZADO, $donacion, $anterior, $nuevo, 'Donación actualizada desde Mercado Pago.');
    }

    public static function sinCambios(Donacion $donacion, EstadoDonacion $estado): self
    {
        return new self(self::SIN_CAMBIOS, $donacion, $estado, $estado, 'La donación ya estaba al día.');
    }

    /** Notificación anterior a la última que ya aplicamos: se descarta. */
    public static function obsoleto(Donacion $donacion, EstadoDonacion $estado): self
    {
        return new self(self::OBSOLETO, $donacion, $estado, $estado, 'La notificación es más antigua que el estado guardado.');
    }

    /** Llegó un pago distinto al que ya tenía la donación. Lo mira una persona. */
    public static function conflicto(Donacion $donacion, EstadoDonacion $estado, string $motivo): self
    {
        return new self(self::CONFLICTO, $donacion, $estado, $estado, $motivo);
    }

    /** Un pago que no corresponde a ninguna donación nuestra. */
    public static function huerfano(string $motivo): self
    {
        return new self(self::HUERFANO, null, null, null, $motivo);
    }

    /** Si la donación cambió de verdad, para no registrar ruido. */
    public function huboCambio(): bool
    {
        return $this->resultado === self::ACTUALIZADO;
    }
}
