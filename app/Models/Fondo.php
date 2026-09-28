<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ColorFondo;
use App\Enums\EstadoDonacion;
use App\Enums\EstadoFondo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Un fondo: el proyecto al que va el dinero.
 *
 * `recaudado` y `donaciones_count` son contadores denormalizados que mueve
 * ReconciliarDonacion dentro de su transacción. NO se tocan desde aquí a mano;
 * si alguna vez dejan de cuadrar, la vista `v_fondos_conciliacion` lo delata y
 * `php artisan fondos:recalcular` los repara.
 *
 * @property int $id
 * @property EstadoFondo $estado
 * @property ColorFondo $color_token
 */
class Fondo extends Model
{
    protected $table = 'fondos';

    protected $fillable = [
        'slug',
        'nombre',
        'resumen',
        'descripcion',
        'imagen_portada',
        'video',
        'meta',
        'moneda',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'color_token',
        'orden',
        'es_predeterminado',
        'creado_por',
    ];

    /**
     * Valores por defecto en memoria, iguales a los de la base.
     *
     * Sin esto, un fondo recién creado devuelve null en sus contadores hasta
     * que alguien lo relee, y cualquier cálculo sobre él arrancaría de un valor
     * que no es el que tiene la fila.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'recaudado' => 0,
        'donaciones_count' => 0,
        'moneda' => 'PEN',
        'orden' => 0,
        'es_predeterminado' => false,
    ];

    /**
     * Los contadores quedan fuera de $fillable a propósito: solo los mueve el
     * servicio de reconciliación y el comando de recálculo, nunca una
     * asignación masiva desde un formulario.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoFondo::class,
            'color_token' => ColorFondo::class,
            'meta' => 'decimal:2',
            'recaudado' => 'decimal:2',
            'donaciones_count' => 'integer',
            'orden' => 'integer',
            'es_predeterminado' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function donaciones(): HasMany
    {
        return $this->hasMany(Donacion::class);
    }

    public function medios(): HasMany
    {
        return $this->hasMany(FondoMedio::class)->orderBy('orden');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'creado_por');
    }

    /** Los que admiten aportes nuevos. */
    public function scopeAbiertos(Builder $query): Builder
    {
        return $query->where('estado', EstadoFondo::ACTIVO);
    }

    /**
     * Los que se muestran al público: también los pausados y cerrados, con lo
     * que recaudaron. Es rendición de cuentas, no escaparate.
     */
    public function scopeVisibles(Builder $query): Builder
    {
        return $query->whereIn('estado', array_map(
            static fn (EstadoFondo $estado): string => $estado->value,
            array_filter(EstadoFondo::cases(), static fn (EstadoFondo $e): bool => $e->visiblePublicamente())
        ));
    }

    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('id');
    }

    public function aceptaDonaciones(): bool
    {
        return $this->estado->aceptaDonaciones();
    }

    /**
     * Porcentaje de la meta, o null si el fondo no tiene meta pública. Devolver
     * null en vez de 0 es lo que permite a la vista ocultar la barra en lugar
     * de enseñar un progreso inventado.
     */
    public function porcentajeDeMeta(): ?float
    {
        $meta = $this->meta !== null ? (float) $this->meta : null;

        if ($meta === null || $meta <= 0) {
            return null;
        }

        return min(100.0, round(((float) $this->recaudado / $meta) * 100, 2));
    }

    /**
     * Deja este fondo como el único predeterminado.
     *
     * La unicidad se garantiza aquí y no con un índice: un índice único sobre
     * `es_predeterminado` prohibiría que dos fondos valieran `false`.
     */
    public function marcarComoPredeterminado(): void
    {
        DB::transaction(function (): void {
            static::query()->whereKeyNot($this->getKey())->update(['es_predeterminado' => false]);
            $this->forceFill(['es_predeterminado' => true])->save();
        });
    }

    /** El que sale preseleccionado en el formulario de donación. */
    public static function predeterminado(): ?self
    {
        return static::query()->where('es_predeterminado', true)->first()
            ?? static::query()->abiertos()->ordenados()->first();
    }

    /**
     * Estados de donación que suman en este fondo. Delega en el enum para que
     * DASHBOARD_INCLUDE_PENDING signifique lo mismo en todo el proyecto.
     *
     * @return list<string>
     */
    public static function estadosQueSuman(): array
    {
        return array_map(
            static fn (EstadoDonacion $estado): string => $estado->value,
            EstadoDonacion::contablesPublicos((bool) config('donaciones.dashboard_include_pending', false))
        );
    }
}
