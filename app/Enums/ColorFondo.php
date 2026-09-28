<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Color de un fondo, como NOMBRE de token de la paleta, nunca como hex.
 *
 * Deuda técnica 3: el color lo decide el CSS, no los datos. La base de datos
 * guarda `teal`; `resources/css/app.css` traduce `.fondo--teal` a `var(--teal)`.
 * Así nadie puede meter desde la base un color que rompa la identidad de marca,
 * y cambiar la paleta no obliga a tocar ninguna fila.
 */
enum ColorFondo: string
{
    case TEAL = 'teal';
    case CORAL = 'coral';
    case YELLOW = 'yellow';
    case BLUE = 'blue';
    case NAVY = 'navy';

    public function etiqueta(): string
    {
        return match ($this) {
            self::TEAL => 'Verde azulado',
            self::CORAL => 'Coral',
            self::YELLOW => 'Amarillo',
            self::BLUE => 'Azul',
            self::NAVY => 'Azul marino',
        };
    }

    /** La clase que consume el CSS. El hex vive solo en app.css. */
    public function claseCss(): string
    {
        return 'fondo--'.$this->value;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
