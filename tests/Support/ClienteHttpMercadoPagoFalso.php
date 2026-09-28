<?php

declare(strict_types=1);

namespace Tests\Support;

use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

/**
 * Sustituto del transporte HTTP del SDK de Mercado Pago, con enrutado por URI.
 *
 * Se inyecta por el propio punto de extensión del SDK
 * (MercadoPagoConfig::setHttpClient), así que los tests ejercitan el código de
 * producción tal cual está: nada se adapta "para poder testearlo", y NO existe
 * una capa de interfaz paralela. Esta es la única forma de simular MP en todo
 * el proyecto.
 *
 * Guarda el cuerpo enviado a MP, que es lo que permite comprobar las reglas
 * duras 1, 2, 3 y 8 sobre la preferencia que se habría creado de verdad.
 */
final class ClienteHttpMercadoPagoFalso implements MPHttpClient
{
    /** @var array<string, mixed>|null Cuerpo JSON de la última petición. */
    public ?array $ultimoPayload = null;

    public int $peticiones = 0;

    public int $consultasDePago = 0;

    public int $consultasDeOrden = 0;

    public int $busquedasDePago = 0;

    /** Si se fija, `send()` lanza esto en lugar de responder. */
    public ?MPApiException $lanzar = null;

    /** @var array<string, mixed> Respuesta de POST /checkout/preferences. */
    public array $preferencia = [
        'id' => '1234567890-abcd-efgh',
        'init_point' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=PROD',
        'sandbox_init_point' => 'https://sandbox.mercadopago.com.pe/checkout/v1/redirect?pref_id=SBX',
    ];

    /** @var array<string, array<string, mixed>> Pagos por id. */
    public array $pagos = [];

    /** @var array<string, array<string, mixed>> Órdenes comerciales por id. */
    public array $ordenes = [];

    /** Regla 4: la orden solo entrega su pago a partir de esta consulta. */
    public int $ordenEntregaPagoDesdeConsulta = 1;

    /** @param array<string, mixed> $preferencia */
    public function __construct(array $preferencia = [])
    {
        if ($preferencia !== []) {
            $this->preferencia = $preferencia;
        }
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->peticiones++;

        $payload = json_decode((string) $request->getPayload(), true);
        $this->ultimoPayload = is_array($payload) ? $payload : null;

        if ($this->lanzar !== null) {
            throw $this->lanzar;
        }

        $uri = $request->getUri();

        if (str_contains($uri, '/v1/payments/search')) {
            $this->busquedasDePago++;

            return new MPResponse(200, [
                'paging' => ['total' => count($this->pagos)],
                'results' => array_values($this->pagosDeLaBusqueda($uri)),
            ]);
        }

        if (preg_match('#/merchant_orders/(\d+)#', $uri, $coincidencias) === 1) {
            $this->consultasDeOrden++;
            $orden = $this->ordenes[$coincidencias[1]] ?? ['id' => (int) $coincidencias[1], 'payments' => []];

            if ($this->consultasDeOrden < $this->ordenEntregaPagoDesdeConsulta) {
                $orden['payments'] = [];
            }

            return new MPResponse(200, $orden);
        }

        if (preg_match('#/v1/payments/(\d+)#', $uri, $coincidencias) === 1) {
            $this->consultasDePago++;

            if (! isset($this->pagos[$coincidencias[1]])) {
                throw new MPApiException('Not Found', new MPResponse(404, ['message' => 'payment not found']));
            }

            return new MPResponse(200, $this->pagos[$coincidencias[1]]);
        }

        return new MPResponse(201, $this->preferencia);
    }

    public static function queFalla(int $estado = 400, string $mensaje = 'Bad Request'): self
    {
        $falso = new self;
        $falso->lanzar = new MPApiException($mensaje, new MPResponse($estado, ['message' => $mensaje]));

        return $falso;
    }

    /**
     * Registra un pago tal y como lo devolvería la API de Mercado Pago.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function conPago(
        int $id,
        string $estado,
        ?string $referenciaExterna,
        float $monto = 150.00,
        array $metadata = [],
        ?string $fechaUltimaActualizacion = null,
        ?string $fechaAprobacion = null,
    ): self {
        $aprobado = $estado === 'approved';

        $this->pagos[(string) $id] = [
            'id' => $id,
            'status' => $estado,
            'status_detail' => $aprobado ? 'accredited' : 'cc_rejected_other_reason',
            'external_reference' => $referenciaExterna,
            'transaction_amount' => $monto,
            'currency_id' => 'PEN',
            'metadata' => $metadata,
            'payment_method_id' => 'visa',
            'payment_type_id' => 'credit_card',
            'date_approved' => $aprobado ? ($fechaAprobacion ?? '2026-09-27T12:00:00.000-05:00') : null,
            'date_last_updated' => $fechaUltimaActualizacion ?? '2026-09-27T12:00:00.000-05:00',
            'live_mode' => false,
            'fee_details' => [['type' => 'mercadopago_fee', 'fee_payer' => 'collector', 'amount' => 5.31]],
            'transaction_details' => [
                'net_received_amount' => round($monto - 5.31, 2),
                'total_paid_amount' => $monto,
            ],
        ];

        return $this;
    }

    /** @param list<array{id: int, status: string}> $pagos */
    public function conOrden(int $id, array $pagos): self
    {
        $this->ordenes[(string) $id] = ['id' => $id, 'payments' => $pagos];

        return $this;
    }

    /**
     * Filtra por el external_reference que viaja en la query de la búsqueda,
     * igual que hace la API real.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pagosDeLaBusqueda(string $uri): array
    {
        if (preg_match('/external_reference=([^&]*)/', $uri, $coincidencias) !== 1) {
            return $this->pagos;
        }

        $referencia = urldecode($coincidencias[1]);

        return array_filter(
            $this->pagos,
            static fn (array $pago): bool => (string) ($pago['external_reference'] ?? '') === $referencia
        );
    }
}
