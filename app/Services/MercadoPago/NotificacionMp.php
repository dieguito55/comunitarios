<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use Illuminate\Http\Request;

/**
 * Una notificación de Mercado Pago, ya desmenuzada.
 *
 * Existe porque el middleware de firma y el controlador necesitan exactamente
 * los mismos cuatro datos (topic, recurso, ts y request-id), y sacarlos no es
 * trivial: Mercado Pago los manda de formas distintas según la versión y el
 * origen de la integración.
 *
 * La trampa importante está en `data.id`: viaja en la QUERY STRING, y PHP
 * convierte el punto en guion bajo al poblar $_GET. Si se lee de ahí se obtiene
 * `data_id`, y el manifiesto de la firma sale mal. Por eso se lee de la cadena
 * cruda.
 */
final readonly class NotificacionMp
{
    /**
     * @param  array<string, mixed>  $cuerpo
     */
    private function __construct(
        public string $topic,
        public string $recursoId,
        public string $ts,
        public ?string $requestId,
        public ?string $firma,
        public string $cuerpoCrudo,
        public array $cuerpo,
        public string $queryString,
    ) {}

    public static function desdePeticion(Request $peticion): self
    {
        $crudo = $peticion->getContent();
        $decodificado = json_decode($crudo, true);
        $cuerpo = is_array($decodificado) ? $decodificado : [];

        $queryString = (string) $peticion->server->get('QUERY_STRING', '');
        $firma = $peticion->header('x-signature');
        $requestId = $peticion->header('x-request-id');

        return new self(
            topic: self::topic($peticion, $cuerpo),
            recursoId: self::recursoId($peticion, $cuerpo, $queryString),
            ts: self::ts(is_string($firma) ? $firma : ''),
            requestId: is_string($requestId) && trim($requestId) !== '' ? trim($requestId) : null,
            firma: is_string($firma) && trim($firma) !== '' ? trim($firma) : null,
            cuerpoCrudo: $crudo,
            cuerpo: $cuerpo,
            queryString: $queryString,
        );
    }

    /** Si el evento es de los que mueven dinero. */
    public function esRelevante(): bool
    {
        return in_array($this->topic, ['payment', 'merchant_order'], true) && $this->recursoId !== '';
    }

    /**
     * Lo que se guarda en webhook_logs. Incluye la query string cruda porque sin
     * ella no se puede reconstruir el manifiesto de la firma en una
     * investigación posterior.
     *
     * @return array<string, mixed>
     */
    public function paraAuditoria(): array
    {
        return [
            'cuerpo' => $this->cuerpo !== [] ? $this->cuerpo : $this->cuerpoCrudo,
            'query_string' => $this->queryString,
            'topic' => $this->topic,
            'recurso_id' => $this->recursoId,
            'x_request_id' => $this->requestId,
            'recibido_en' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $cuerpo */
    private static function topic(Request $peticion, array $cuerpo): string
    {
        foreach ([$cuerpo['type'] ?? null, $cuerpo['topic'] ?? null, $peticion->query('type'), $peticion->query('topic')] as $candidato) {
            $valor = mb_strtolower(trim((string) $candidato));

            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $cuerpo */
    private static function recursoId(Request $peticion, array $cuerpo, string $queryString): string
    {
        $datos = is_array($cuerpo['data'] ?? null) ? $cuerpo['data'] : [];

        $candidatos = [
            $datos['id'] ?? null,
            self::dataIdDeLaQueryCruda($queryString),
            $peticion->query('data_id'),
            $peticion->query('id'),
            $cuerpo['resource'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            $valor = trim((string) $candidato);

            // `resource` a veces llega como URL completa de la orden.
            if ($valor !== '' && str_contains($valor, '/')) {
                $valor = (string) preg_replace('#^.*/#', '', $valor);
            }

            if ($valor !== '' && ctype_digit($valor)) {
                return $valor;
            }
        }

        return '';
    }

    private static function dataIdDeLaQueryCruda(string $queryString): string
    {
        if ($queryString !== '' && preg_match('/(?:^|&)data\.id=([^&]*)/', $queryString, $coincidencias) === 1) {
            return trim(urldecode($coincidencias[1]));
        }

        return '';
    }

    /** El `ts` viaja dentro de la cabecera x-signature: `ts=...,v1=...`. */
    private static function ts(string $firma): string
    {
        foreach (explode(',', $firma) as $parte) {
            $trozos = explode('=', $parte, 2);

            if (count($trozos) === 2 && mb_strtolower(trim($trozos[0])) === 'ts') {
                return trim($trozos[1]);
            }
        }

        return '';
    }
}
