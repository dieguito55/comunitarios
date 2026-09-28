<?php

declare(strict_types=1);

namespace App\Enums;

/** Rol de un usuario del panel administrativo. */
enum RolAdmin: string
{
    /** Puede todo, incluida la corrección manual de estados terminales. */
    case SUPERADMIN = 'superadmin';

    /** Verifica comprobantes QR y registra efectivo; no administra usuarios. */
    case EDITOR = 'editor';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'Superadministrador',
            self::EDITOR => 'Editor',
        };
    }
}
