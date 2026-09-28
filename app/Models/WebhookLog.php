<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila por notificación recibida de Mercado Pago, con el payload crudo.
 *
 * Nunca se purga y nunca se edita salvo para marcar el resultado del proceso:
 * es la única prueba disponible cuando hay que reconstruir un pago perdido.
 *
 * @property array<string, mixed> $payload
 */
class WebhookLog extends Model
{
    protected $table = 'webhook_logs';

    /** La tabla solo tiene created_at: una notificación es un hecho, no se edita. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'payload',
        'evento',
        'recurso_id',
        'ts_notificacion',
        'status',
        'firma_valida',
        'x_request_id',
        'procesado',
        'intentos',
        'error_mensaje',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'firma_valida' => 'boolean',
            'procesado' => 'boolean',
            'intentos' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
