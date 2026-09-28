<?php

declare(strict_types=1);

namespace App\Services\Donaciones;

use App\Models\Donacion;

/**
 * Qué pasó al verificar un comprobante.
 *
 * Tres desenlaces, y los tres importan para lo que se le dice a quien pulsó el
 * botón. Se devuelven como objeto y no como excepción porque «otra persona ya
 * la revisó» no es un error: es información, y quien lo lee necesita saber
 * quién fue y cuándo para no volver a intentarlo.
 */
final readonly class ResultadoVerificacion
{
    private function __construct(
        public string $desenlace,
        public ?Donacion $donacion,
        public float $movimiento,
    ) {}

    /** La decisión se registró: se aprobó o se rechazó ahora mismo. */
    public static function decidida(Donacion $donacion, float $movimiento): self
    {
        return new self('decidida', $donacion, $movimiento);
    }

    /** Alguien llegó antes. La donación ya no estaba pendiente. */
    public static function yaVerificada(Donacion $donacion): self
    {
        return new self('ya_verificada', $donacion, 0.0);
    }

    /** Desapareció entre que se cargó la cola y se pulsó el botón. */
    public static function noEncontrada(): self
    {
        return new self('no_encontrada', null, 0.0);
    }

    public function fueDecidida(): bool
    {
        return $this->desenlace === 'decidida';
    }

    public function yaEstabaVerificada(): bool
    {
        return $this->desenlace === 'ya_verificada';
    }
}
