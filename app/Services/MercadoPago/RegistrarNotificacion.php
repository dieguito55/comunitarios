<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escribe la fila cruda de `webhook_logs`. Un solo sitio, para que toda
 * notificación —aceptada o rechazada por firma— quede registrada igual.
 *
 * Esa tabla NO SE PURGA NUNCA: es la única prueba disponible cuando hay que
 * reconstruir a mano un pago que se perdió, meses después.
 *
 * Nunca lanza. Un fallo al registrar no puede impedir que la notificación se
 * procese: es preferible procesar sin bitácora que perder el pago.
 */
final class RegistrarNotificacion
{
    public function __invoke(NotificacionMp $notificacion, bool $firmaValida, string $estado): ?WebhookLog
    {
        try {
            return WebhookLog::query()->create([
                'payload' => $notificacion->paraAuditoria(),
                'evento' => $notificacion->topic !== '' ? mb_substr($notificacion->topic, 0, 100) : null,
                'recurso_id' => $notificacion->recursoId !== '' ? mb_substr($notificacion->recursoId, 0, 80) : null,
                'ts_notificacion' => $notificacion->ts !== '' ? mb_substr($notificacion->ts, 0, 40) : null,
                'status' => mb_substr($estado, 0, 50),
                'firma_valida' => $firmaValida,
                'x_request_id' => $notificacion->requestId !== null ? mb_substr($notificacion->requestId, 0, 120) : null,
                'procesado' => false,
                'intentos' => 1,
            ]);
        } catch (Throwable $excepcion) {
            Log::channel('webhooks')->warning('No se pudo registrar la notificación en webhook_logs', [
                'topic' => $notificacion->topic,
                'recurso_id' => $notificacion->recursoId,
                'error' => $excepcion->getMessage(),
            ]);

            return null;
        }
    }
}
