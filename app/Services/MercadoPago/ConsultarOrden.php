<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Exceptions\MercadoPagoNoConfigurado;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;

/**
 * GET /merchant_orders/{id}.
 *
 * Una consulta y nada más: la espera y la reconsulta de la regla dura 4 viven
 * en el controlador, porque son una decisión del flujo del webhook y no de la
 * consulta en sí.
 */
final class ConsultarOrden
{
    public function __construct(private readonly MerchantOrderClient $clienteOrdenes) {}

    /**
     * @return array{external_reference: string, pagos: list<string>}
     */
    public function __invoke(string $idDeOrden): array
    {
        if (trim((string) config('mercadopago.access_token')) === '') {
            throw MercadoPagoNoConfigurado::faltaAccessToken();
        }

        $orden = $this->clienteOrdenes->get((int) $idDeOrden);
        $pagos = is_array($orden->payments ?? null) ? $orden->payments : [];

        $ids = [];

        foreach ($pagos as $pago) {
            $id = trim((string) $this->propiedad($pago, 'id'));

            if ($id !== '' && ctype_digit($id)) {
                $ids[] = $id;
            }
        }

        return [
            'external_reference' => trim((string) ($orden->external_reference ?? '')),
            'pagos' => $ids,
        ];
    }

    /** La orden puede venir como objeto tipado o como array según la ruta. */
    private function propiedad(mixed $origen, string $nombre): mixed
    {
        if (is_array($origen)) {
            return $origen[$nombre] ?? null;
        }

        return is_object($origen) ? ($origen->{$nombre} ?? null) : null;
    }
}
