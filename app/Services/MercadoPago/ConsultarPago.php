<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Exceptions\MercadoPagoNoConfigurado;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Resources\Payment;
use Throwable;

/**
 * GET /v1/payments/{id}. Regla dura 6.
 *
 * NUNCA se confía en el `status` que llega en el cuerpo del webhook: cualquiera
 * puede enviarnos un POST diciendo que un pago fue aprobado. El cuerpo solo
 * sirve para saber QUÉ consultar; la verdad es esta llamada, autenticada con
 * nuestro access token.
 *
 * Devuelve un array normalizado en lugar del recurso del SDK por una razón muy
 * concreta: el SDK deserializa `metadata` en MercadoPago\Resources\Payment\Metadata,
 * que SOLO declara `order_number`, y su serializador descarta en silencio
 * cualquier otra clave. Es decir, `$payment->metadata->donation_id` NO EXISTE, y
 * el respaldo de la regla 2 sería papel mojado justo en el caso para el que se
 * inventó. El valor se recupera del JSON crudo, que el propio SDK expone en
 * `getResponse()->getContent()` precisamente para esto.
 *
 * Deja subir MPApiException: quien llame decide si eso significa reintentar
 * (webhook, regla 5) o responderle al donante.
 */
final class ConsultarPago
{
    public function __construct(private readonly PaymentClient $clientePagos) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $idDePago): array
    {
        if (trim((string) config('mercadopago.access_token')) === '') {
            throw MercadoPagoNoConfigurado::faltaAccessToken();
        }

        return self::normalizar($this->clientePagos->get((int) $idDePago));
    }

    /**
     * Convierte el recurso del SDK en el array que consume el resto del módulo.
     *
     * @return array<string, mixed>
     */
    public static function normalizar(Payment $pago): array
    {
        $crudo = self::cuerpoCrudo($pago);
        $metadata = is_array($crudo['metadata'] ?? null) ? $crudo['metadata'] : [];

        return [
            'id' => (string) ($pago->id ?? ''),
            'status' => mb_strtolower(trim((string) ($pago->status ?? ''))),
            'status_detail' => self::textoONulo($pago->status_detail ?? null),
            'external_reference' => trim((string) ($pago->external_reference ?? '')),
            'metadata' => $metadata,
            'transaction_amount' => isset($pago->transaction_amount) ? (float) $pago->transaction_amount : null,
            'net_received_amount' => self::netoRecibido($crudo),
            'fee_amount' => self::comisionDelCobrador($crudo),
            'payment_method_id' => self::textoONulo($pago->payment_method_id ?? null),
            'payment_type_id' => self::textoONulo($pago->payment_type_id ?? null),
            'date_approved' => self::textoONulo($pago->date_approved ?? null),
            'date_last_updated' => self::textoONulo($pago->date_last_updated ?? null),
            'live_mode' => isset($pago->live_mode) ? (bool) $pago->live_mode : null,
            'currency_id' => self::textoONulo($pago->currency_id ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function cuerpoCrudo(Payment $pago): array
    {
        try {
            return $pago->getResponse()->getContent();
        } catch (Throwable) {
            // El recurso puede venir de una ruta que no adjuntó la respuesta.
            return [];
        }
    }

    /**
     * Comisión que soporta la organización: solo los conceptos cuyo pagador es
     * el cobrador. Las comisiones que paga el donante no nos restan nada.
     *
     * @param  array<string, mixed>  $crudo
     */
    private static function comisionDelCobrador(array $crudo): ?float
    {
        $detalles = $crudo['fee_details'] ?? null;

        if (! is_array($detalles) || $detalles === []) {
            return null;
        }

        $total = 0.0;
        $huboAlguna = false;

        foreach ($detalles as $detalle) {
            if (! is_array($detalle) || ! isset($detalle['amount']) || ! is_numeric($detalle['amount'])) {
                continue;
            }

            $pagador = mb_strtolower(trim((string) ($detalle['fee_payer'] ?? '')));

            if ($pagador !== '' && $pagador !== 'collector') {
                continue;
            }

            $total += (float) $detalle['amount'];
            $huboAlguna = true;
        }

        return $huboAlguna ? round($total, 2) : null;
    }

    /** @param  array<string, mixed>  $crudo */
    private static function netoRecibido(array $crudo): ?float
    {
        $detalles = $crudo['transaction_details'] ?? null;

        if (! is_array($detalles) || ! isset($detalles['net_received_amount'])) {
            return null;
        }

        return is_numeric($detalles['net_received_amount'])
            ? round((float) $detalles['net_received_amount'], 2)
            : null;
    }

    private static function textoONulo(?string $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }
}
