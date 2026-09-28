<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Enums\ProveedorPago;
use App\Enums\TipoAportante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una donación, de cualquiera de los tres canales.
 *
 *  - `id` ES el `external_reference` que viaja a Mercado Pago. No se cambia.
 *  - `monto_referencial` es lo que el donante declaró; `monto_real` es lo que
 *    entró de verdad. No se sobrescriben: la contabilidad usa montoEfectivo().
 *
 * @property int $id
 * @property EstadoDonacion $estado
 * @property CanalPago $canal_pago
 * @property ProveedorPago $proveedor_pago
 * @property TipoAportante $tipo_aportante
 */
class Donacion extends Model
{
    protected $table = 'donaciones';

    protected $fillable = [
        'nombre',
        'documento',
        'correo',
        'telefono',
        'tipo_aportante',
        'fondo_id',
        'monto_referencial',
        'monto_real',
        'moneda',
        'canal_pago',
        'proveedor_pago',
        'referencia_pago',
        'comprobante_path',
        'comprobante_mime',
        'estado',
        'mp_preference_id',
        'mp_payment_id',
        'mp_status_detail',
        'mp_payment_method_id',
        'mp_payment_type_id',
        'mp_fee',
        'mp_net_received',
        'mp_date_approved',
        'mp_date_last_updated',
        'mp_live_mode',
        'visible_publico',
        'acepta_terminos',
        'es_lote_anonimo',
        'registrado_por',
        'verificado_por',
        'verificado_at',
        'ip_origen',
    ];

    /**
     * Datos que nunca deben salir en una respuesta JSON pública: la IP es dato
     * personal y la ruta del comprobante revelaría la estructura del disco
     * privado (deuda técnica 5).
     *
     * @var list<string>
     */
    protected $hidden = [
        'ip_origen',
        'comprobante_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tipo_aportante' => TipoAportante::class,
            'canal_pago' => CanalPago::class,
            'proveedor_pago' => ProveedorPago::class,
            'estado' => EstadoDonacion::class,
            'monto_referencial' => 'decimal:2',
            'monto_real' => 'decimal:2',
            'mp_fee' => 'decimal:2',
            'mp_net_received' => 'decimal:2',
            'mp_date_approved' => 'datetime',
            'mp_date_last_updated' => 'datetime',
            'mp_live_mode' => 'boolean',
            'visible_publico' => 'boolean',
            'acepta_terminos' => 'boolean',
            'es_lote_anonimo' => 'boolean',
            'verificado_at' => 'datetime',
        ];
    }

    /**
     * El monto con el que se hace contabilidad: lo que entró de verdad y, si
     * todavía no se ha confirmado, lo que el donante declaró.
     *
     * Equivale a COALESCE(monto_real, monto_referencial) en SQL.
     */
    public function montoEfectivo(): float
    {
        return (float) ($this->monto_real ?? $this->monto_referencial);
    }

    /** Dinero confirmado. */
    public function scopeAprobadas(Builder $query): Builder
    {
        return $query->where('estado', EstadoDonacion::APROBADO);
    }

    /** A la espera de confirmación: cola de verificación QR y pagos sin cerrar. */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', EstadoDonacion::PENDIENTE);
    }

    public function scopePorCanal(Builder $query, CanalPago $canal): Builder
    {
        return $query->where('canal_pago', $canal);
    }

    /**
     * Estados que suman en el dashboard público.
     *
     * Deuda técnica 6: DASHBOARD_INCLUDE_PENDING manda de verdad. Por defecto es
     * false, así que solo el dinero confirmado aparece en el total público; un
     * total que baja porque un pago se rechazó genera desconfianza.
     */
    public function scopeCuentanParaTotal(Builder $query): Builder
    {
        $estados = EstadoDonacion::contablesPublicos(
            (bool) config('donaciones.dashboard_include_pending', false)
        );

        return $query->whereIn('estado', array_map(
            static fn (EstadoDonacion $estado): string => $estado->value,
            $estados
        ));
    }

    /** El proyecto al que va este dinero. Obligatorio desde el primer día. */
    public function fondo(): BelongsTo
    {
        return $this->belongsTo(Fondo::class);
    }

    /** Admin que registró el aporte en efectivo desde tesorería. */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'registrado_por');
    }

    /** Admin que revisó el comprobante del canal QR y fijó el monto real. */
    public function verificadoPor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verificado_por');
    }
}
