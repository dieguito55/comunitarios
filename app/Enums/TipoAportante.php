<?php

declare(strict_types=1);

namespace App\Enums;

/** Naturaleza del donante. Determina si el documento es DNI/pasaporte o RUC. */
enum TipoAportante: string
{
    case PERSONA = 'persona';
    case EMPRESA = 'empresa';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PERSONA => 'Persona natural',
            self::EMPRESA => 'Empresa',
        };
    }
}
