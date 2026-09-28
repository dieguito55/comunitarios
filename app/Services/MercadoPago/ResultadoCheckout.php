<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

/**
 * Lo que se le devuelve al navegador tras crear la preferencia.
 *
 * `donacion_id` y `preference_id` son las dos llaves que el front guarda en
 * localStorage ANTES de redirigir: al volver del checkout puede que la URL no
 * traiga payment_id, y esto es lo único que permite reconciliar (red 2).
 */
final readonly class ResultadoCheckout
{
    public function __construct(
        public int $donacionId,
        public string $preferenceId,
        public string $initPoint,
        public bool $modoSandbox,
        public string $puntoDeCheckout,
    ) {}

    /** @return array<string, mixed> */
    public function aRespuesta(): array
    {
        return [
            'donacion_id' => $this->donacionId,
            'preference_id' => $this->preferenceId,
            'init_point' => $this->initPoint,
            'modo_sandbox' => $this->modoSandbox,
            'punto_de_checkout' => $this->puntoDeCheckout,
        ];
    }
}
