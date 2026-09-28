<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una imagen o un vídeo de la galería de un fondo.
 *
 * `ruta` es relativa dentro de public/ (por ejemplo `media/hero.webp`), para
 * que el mismo registro sirva en local y en producción sin reescribir dominios.
 */
class FondoMedio extends Model
{
    protected $table = 'fondo_medios';

    protected $fillable = [
        'fondo_id',
        'tipo',
        'ruta',
        'alt',
        'orden',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    public function fondo(): BelongsTo
    {
        return $this->belongsTo(Fondo::class);
    }

    public function esVideo(): bool
    {
        return $this->tipo === 'video';
    }
}
