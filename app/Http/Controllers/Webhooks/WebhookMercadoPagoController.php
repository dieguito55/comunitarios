<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\Donaciones\ResultadoReconciliacion;
use App\Services\MercadoPago\ConsultarOrden;
use App\Services\MercadoPago\ConsultarPago;
use App\Services\MercadoPago\NotificacionMp;
use App\Services\MercadoPago\RegistrarNotificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST|GET /api/webhooks/mercadopago — primera red de seguridad.
 *
 * Tres cosas que no se pueden cambiar sin perder dinero:
 *
 *  1. El payload crudo se registra ANTES de procesar nada. Si el proceso
 *     revienta, la fila de webhook_logs sigue ahí y el pago es rescatable a
 *     mano. Esa tabla no se purga nunca.
 *
 *  2. Ante un error REAL se responde 500 (regla dura 5). Mercado Pago reintenta
 *     ante 5xx; un 200 tras un fallo pierde el pago para siempre. Pero "no
 *     aplicable" NO es un error: eso va con 200, porque reintentarlo no lo
 *     arreglaría y solo generaría ruido.
 *
 *  3. El estado se lee SIEMPRE de GET /v1/payments/{id} (regla dura 6). El
 *     cuerpo de la notificación solo sirve para saber QUÉ consultar.
 *
 * Procesamiento EN LÍNEA, no en cola: con QUEUE_CONNECTION=sync un Job no
 * aportaría nada (se ejecutaría igual dentro de la petición) y sí quitaría la
 * garantía del 500, que es lo que salva pagos. El trabajo real son una o dos
 * llamadas a la API de MP y un UPDATE, muy lejos de los ~22 segundos a los que
 * corta Mercado Pago. Si algún día se añade una cola de verdad, el 500 debe
 * pasar a emitirse cuando falle el ENCOLADO.
 */
final class WebhookMercadoPagoController extends Controller
{
    /** Regla dura 4: lo que se espera antes de reconsultar la orden. */
    private const ESPERA_RECONSULTA_MICROSEGUNDOS = 800000;

    public function __construct(
        private readonly RegistrarNotificacion $registrarNotificacion,
        private readonly ConsultarPago $consultarPago,
        private readonly ConsultarOrden $consultarOrden,
        private readonly ReconciliarDonacion $reconciliarDonacion,
    ) {}

    public function __invoke(Request $peticion): JsonResponse
    {
        $notificacion = NotificacionMp::desdePeticion($peticion);
        $firmaValida = (bool) $peticion->attributes->get('mp_firma_valida', false);

        // 1. La fila cruda, pase lo que pase después.
        $bitacora = ($this->registrarNotificacion)($notificacion, $firmaValida, 'received');

        // 2. Mercado Pago reenvía. El total no puede subir dos veces.
        if ($this->yaProcesada($notificacion, $bitacora)) {
            $this->cerrar($bitacora, 'duplicado', true);

            return $this->ok('Notificación duplicada; ya estaba procesada.');
        }

        if (! $notificacion->esRelevante()) {
            $this->cerrar($bitacora, 'ignored', true);

            return $this->ok("Evento no relevante: «{$notificacion->topic}».");
        }

        try {
            $idsDePago = $this->idsDePago($notificacion);
        } catch (Throwable $excepcion) {
            return $this->fallo($bitacora, $notificacion, $excepcion, 'resolviendo la orden comercial');
        }

        // Regla 4: la orden llegó antes que su pago. Normal, no es un error.
        if ($idsDePago === []) {
            $this->cerrar($bitacora, 'ignored', true);

            return $this->ok('La orden comercial todavía no tiene pagos asociados.');
        }

        $resultados = [];

        foreach ($idsDePago as $idDePago) {
            try {
                // 4. Regla 6: la verdad está en la API, no en el cuerpo.
                $pago = ($this->consultarPago)($idDePago);

                // 5. Toda la lógica de negocio vive en el servicio compartido.
                $resultados[] = ($this->reconciliarDonacion)($pago, 'webhook');
            } catch (Throwable $excepcion) {
                return $this->fallo($bitacora, $notificacion, $excepcion, "procesando el pago {$idDePago}");
            }
        }

        // 6. Cerrar la bitácora con el veredicto.
        $veredicto = $this->veredicto($resultados);
        $this->cerrar($bitacora, $veredicto, true);

        if ($veredicto === ResultadoReconciliacion::HUERFANO) {
            // Reintentar no lo va a arreglar: el pago no es de ninguna donación
            // nuestra. Queda registrado para que lo recoja la auditoría.
            Log::channel('webhooks')->warning('Notificación huérfana registrada para auditoría', [
                'topic' => $notificacion->topic,
                'recurso_id' => $notificacion->recursoId,
                'pagos' => $idsDePago,
            ]);

            return $this->ok('Pago sin donación asociada; registrado para auditoría.');
        }

        return $this->ok('Notificación procesada.');
    }

    /**
     * Deduplicación por (topic, recurso, ts). Mercado Pago reenvía la misma
     * notificación ante un 5xx y también sin que haya habido error.
     *
     * Sin `ts` no se deduplica: dos notificaciones legítimas del mismo recurso
     * (por ejemplo `pending` y luego `approved`) comparten topic y recurso, y
     * confundirlas perdería la segunda. En ese caso la idempotencia la
     * garantiza ReconciliarDonacion.
     */
    private function yaProcesada(NotificacionMp $notificacion, ?WebhookLog $bitacora): bool
    {
        if ($notificacion->ts === '' || $notificacion->recursoId === '') {
            return false;
        }

        return WebhookLog::query()
            ->where('evento', $notificacion->topic)
            ->where('recurso_id', $notificacion->recursoId)
            ->where('ts_notificacion', $notificacion->ts)
            ->where('procesado', true)
            ->when($bitacora !== null, fn ($consulta) => $consulta->whereKeyNot($bitacora->getKey()))
            ->exists();
    }

    /**
     * Ids de pago a procesar. Para `payment` es directo; para `merchant_order`
     * hay que abrir la orden y, si viene sin pagos, reconsultarla una vez tras
     * una espera corta (regla dura 4).
     *
     * @return list<string>
     */
    private function idsDePago(NotificacionMp $notificacion): array
    {
        if ($notificacion->topic === 'payment') {
            return [$notificacion->recursoId];
        }

        $orden = ($this->consultarOrden)($notificacion->recursoId);

        if ($orden['pagos'] !== []) {
            return $orden['pagos'];
        }

        usleep(self::ESPERA_RECONSULTA_MICROSEGUNDOS);

        return ($this->consultarOrden)($notificacion->recursoId)['pagos'];
    }

    /**
     * Un solo veredicto para la bitácora. Si algún pago de la orden se aplicó,
     * eso manda sobre los que no cambiaron nada.
     *
     * @param  list<ResultadoReconciliacion>  $resultados
     */
    private function veredicto(array $resultados): string
    {
        foreach ([
            ResultadoReconciliacion::ACTUALIZADO,
            ResultadoReconciliacion::CONFLICTO,
            ResultadoReconciliacion::OBSOLETO,
            ResultadoReconciliacion::SIN_CAMBIOS,
        ] as $prioritario) {
            foreach ($resultados as $resultado) {
                if ($resultado->resultado === $prioritario) {
                    return $prioritario;
                }
            }
        }

        return ResultadoReconciliacion::HUERFANO;
    }

    /**
     * Regla dura 5: no supimos determinar el resultado. Mercado Pago
     * reintentará, y ese reintento es lo que salva el pago.
     */
    private function fallo(?WebhookLog $bitacora, NotificacionMp $notificacion, Throwable $excepcion, string $momento): JsonResponse
    {
        $this->cerrar($bitacora, 'error', false, $excepcion->getMessage());

        Log::channel('webhooks')->error("Error {$momento}; se responde 500 para que Mercado Pago reintente", [
            'topic' => $notificacion->topic,
            'recurso_id' => $notificacion->recursoId,
            'x_request_id' => $notificacion->requestId,
            'excepcion' => $excepcion::class,
            'mensaje' => $excepcion->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'error' => 'No se pudo procesar la notificación.',
        ], 500);
    }

    private function cerrar(?WebhookLog $bitacora, string $estado, bool $procesado, ?string $error = null): void
    {
        if ($bitacora === null) {
            return;
        }

        try {
            $bitacora->update([
                'status' => mb_substr($estado, 0, 50),
                'procesado' => $procesado,
                'error_mensaje' => $error !== null ? mb_substr($error, 0, 500) : null,
            ]);
        } catch (Throwable $excepcion) {
            Log::channel('webhooks')->warning('No se pudo cerrar la fila de webhook_logs', [
                'webhook_log_id' => $bitacora->getKey(),
                'error' => $excepcion->getMessage(),
            ]);
        }
    }

    private function ok(string $mensaje): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $mensaje], 200);
    }
}
