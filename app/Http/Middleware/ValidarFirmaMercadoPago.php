<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MercadoPago\NotificacionMp;
use App\Services\MercadoPago\RegistrarNotificacion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Webhook\WebhookSignatureValidator;
use Symfony\Component\HttpFoundation\Response as RespuestaBase;

/**
 * Valida la cabecera `x-signature` de Mercado Pago. Deuda técnica 9.
 *
 * El sistema de referencia no validaba nada: confiaba en que siempre se
 * reconsulta el pago con el access token propio. Eso impide falsificar un
 * estado, pero deja el endpoint abierto a que cualquiera nos haga gastar
 * llamadas a la API y llenar la bitácora.
 *
 * ── Sobre el manifiesto ──────────────────────────────────────────────────────
 * Se usa el validador del PROPIO SDK (MercadoPago\Webhook\WebhookSignatureValidator),
 * que construye el manifiesto así:
 *
 *     id:<data.id>;request-id:<x-request-id>;ts:<ts>;
 *
 * y calcula HMAC-SHA256 con la clave secreta del webhook, comparando con
 * hash_equals().
 *
 * ⚠️ La documentación web de Mercado Pago dice que `data.id` va en MINÚSCULAS.
 * El docblock del propio SDK también lo afirma… pero su código NO lo hace: su
 * `normalize()` solo recorta espacios. Ante esa contradicción se prueban las
 * dos variantes (tal cual y en minúsculas): aceptar una notificación legítima
 * importa más que la elegancia, y ninguna de las dos permite aceptar una
 * falsificada. ESTO DEBE CONFIRMARSE CONTRA UNA NOTIFICACIÓN REAL DE SANDBOX
 * antes de darlo por bueno (ver docs/runbook-webhook.md).
 */
final class ValidarFirmaMercadoPago
{
    /**
     * Ventana de validez del `ts`. Más allá, la notificación se considera un
     * intento de repetición: alguien reenviando una firma capturada.
     */
    private const TOLERANCIA_SEGUNDOS = 300;

    public function __construct(private readonly RegistrarNotificacion $registrarNotificacion) {}

    public function handle(Request $peticion, Closure $siguiente): RespuestaBase
    {
        $notificacion = NotificacionMp::desdePeticion($peticion);
        $secreto = trim((string) config('mercadopago.webhook_secret'));

        if ($secreto === '') {
            return $this->sinSecretoConfigurado($peticion, $notificacion, $siguiente);
        }

        if (! $this->firmaCorrecta($notificacion, $secreto)) {
            // Prueba forense: queda registrado incluso lo que se rechaza.
            ($this->registrarNotificacion)($notificacion, false, 'firma_invalida');

            // El motivo NO se devuelve en el cuerpo: le diría a quien lo intente
            // en qué parte de la firma se equivocó.
            return response()->json([
                'success' => false,
                'error' => 'Firma inválida.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $peticion->attributes->set('mp_firma_valida', true);

        return $siguiente($peticion);
    }

    /**
     * Sin secreto no hay forma de validar nada.
     *
     * En producción eso es inaceptable: se rechaza todo. Fuera de producción se
     * deja pasar con un aviso ruidoso, para poder desarrollar contra el
     * simulador del panel de MP antes de tener la clave. Esta excepción no
     * puede activarse jamás con APP_ENV=production.
     */
    private function sinSecretoConfigurado(Request $peticion, NotificacionMp $notificacion, Closure $siguiente): RespuestaBase
    {
        if (app()->environment('production')) {
            Log::channel('webhooks')->critical(
                'MP_WEBHOOK_SECRET está vacío EN PRODUCCIÓN: se rechazan todas las notificaciones. '
                .'Configúralo en el panel de Mercado Pago y en el .env del servidor.',
                ['topic' => $notificacion->topic, 'recurso_id' => $notificacion->recursoId]
            );

            ($this->registrarNotificacion)($notificacion, false, 'sin_secreto');

            return response()->json([
                'success' => false,
                'error' => 'Firma inválida.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        Log::channel('webhooks')->warning(
            'MP_WEBHOOK_SECRET vacío: la firma del webhook NO se está validando. '
            .'Solo es aceptable en desarrollo; en producción esto rechazaría todo.',
            ['entorno' => app()->environment(), 'topic' => $notificacion->topic]
        );

        $peticion->attributes->set('mp_firma_valida', false);

        return $siguiente($peticion);
    }

    /**
     * Delega en el validador del SDK, que compara en tiempo constante y aplica
     * la tolerancia de tiempo. Ver la nota del encabezado sobre minúsculas.
     */
    private function firmaCorrecta(NotificacionMp $notificacion, string $secreto): bool
    {
        $variantes = [$notificacion->recursoId];
        $enMinusculas = mb_strtolower($notificacion->recursoId);

        if ($enMinusculas !== $notificacion->recursoId) {
            $variantes[] = $enMinusculas;
        }

        foreach ($variantes as $recursoId) {
            try {
                WebhookSignatureValidator::validate(
                    $notificacion->firma,
                    $notificacion->requestId,
                    $recursoId !== '' ? $recursoId : null,
                    $secreto,
                    self::TOLERANCIA_SEGUNDOS,
                );

                return true;
            } catch (InvalidWebhookSignatureException $excepcion) {
                $ultimoMotivo = $excepcion->getMessage();
            }
        }

        Log::channel('webhooks')->warning('Firma de webhook inválida; notificación descartada', [
            'motivo' => $ultimoMotivo ?? 'desconocido',
            'x_request_id' => $notificacion->requestId,
            'topic' => $notificacion->topic,
            'recurso_id' => $notificacion->recursoId,
        ]);

        return false;
    }
}
