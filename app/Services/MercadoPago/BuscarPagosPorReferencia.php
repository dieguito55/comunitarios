<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Exceptions\MercadoPagoNoConfigurado;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Net\MPSearchRequest;

/**
 * Busca los pagos de una donación por su `external_reference`.
 *
 * Es el plan B cuando la notificación no trae el id del pago —el caso normal
 * cuando el donante vuelve del checkout sin `payment_id` en la URL— y es la
 * semilla de la red de seguridad 3: el rescate de la auditoría no es más que
 * esta búsqueda aplicada a todas las donaciones pendientes.
 *
 * Devuelve los pagos ya normalizados y ORDENADOS: primero el aprobado, si lo
 * hay, porque es el único que mueve dinero.
 *
 * @see ConsultarPago::normalizar()
 */
final class BuscarPagosPorReferencia
{
    /** Una donación normal genera un pago, o muy pocos. */
    private const LIMITE = 20;

    public function __construct(private readonly PaymentClient $clientePagos) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(string $referenciaExterna): array
    {
        $referencia = trim($referenciaExterna);

        if ($referencia === '') {
            return [];
        }

        if (trim((string) config('mercadopago.access_token')) === '') {
            throw MercadoPagoNoConfigurado::faltaAccessToken();
        }

        $busqueda = $this->clientePagos->search(new MPSearchRequest(self::LIMITE, 0, [
            'external_reference' => $referencia,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]));

        $resultados = is_array($busqueda->results ?? null) ? $busqueda->results : [];
        $pagos = [];

        foreach ($resultados as $resultado) {
            $pago = $this->normalizarResultado($resultado);

            if ($pago !== null) {
                $pagos[] = $pago;
            }
        }

        // El aprobado primero: es el que decide si entró dinero.
        usort($pagos, static fn (array $a, array $b): int => (int) ($b['status'] === 'approved') <=> (int) ($a['status'] === 'approved'));

        return $pagos;
    }

    /**
     * La búsqueda devuelve arrays, no recursos tipados, así que se normaliza
     * aquí con la misma forma que produce ConsultarPago.
     *
     * @return array<string, mixed>|null
     */
    private function normalizarResultado(mixed $resultado): ?array
    {
        $datos = is_array($resultado) ? $resultado : (is_object($resultado) ? get_object_vars($resultado) : null);

        if ($datos === null || ! isset($datos['id'])) {
            return null;
        }

        $detallesTransaccion = is_array($datos['transaction_details'] ?? null) ? $datos['transaction_details'] : [];
        $neto = $detallesTransaccion['net_received_amount'] ?? null;

        return [
            'id' => (string) $datos['id'],
            'status' => mb_strtolower(trim((string) ($datos['status'] ?? ''))),
            'status_detail' => $this->textoONulo($datos['status_detail'] ?? null),
            'external_reference' => trim((string) ($datos['external_reference'] ?? '')),
            'metadata' => is_array($datos['metadata'] ?? null) ? $datos['metadata'] : [],
            'transaction_amount' => isset($datos['transaction_amount']) ? (float) $datos['transaction_amount'] : null,
            'net_received_amount' => is_numeric($neto) ? round((float) $neto, 2) : null,
            'fee_amount' => $this->comisionDelCobrador($datos['fee_details'] ?? null),
            'payment_method_id' => $this->textoONulo($datos['payment_method_id'] ?? null),
            'payment_type_id' => $this->textoONulo($datos['payment_type_id'] ?? null),
            'date_approved' => $this->textoONulo($datos['date_approved'] ?? null),
            'date_last_updated' => $this->textoONulo($datos['date_last_updated'] ?? null),
            'live_mode' => isset($datos['live_mode']) ? (bool) $datos['live_mode'] : null,
            'currency_id' => $this->textoONulo($datos['currency_id'] ?? null),
        ];
    }

    private function comisionDelCobrador(mixed $detalles): ?float
    {
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

    private function textoONulo(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }
}
