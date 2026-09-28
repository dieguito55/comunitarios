<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

/**
 * Prepara una back_url de Mercado Pago añadiéndole `?donacion=<estado>`, que es
 * lo que la página de retorno lee para saber qué mostrar al donante.
 *
 * Si la URL configurada viene vacía, cae a APP_URL. Devuelve cadena vacía
 * cuando no hay ninguna base: quien construya la preferencia decide entonces si
 * puede seguir (regla dura 3: sin success HTTPS no hay auto_return).
 */
final class NormalizarBackUrl
{
    public function __invoke(string $url, string $estado): string
    {
        $base = trim($url);

        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }

        if ($base === '') {
            return '';
        }

        $partes = parse_url($base);

        if ($partes === false) {
            return $base;
        }

        $query = [];

        if (isset($partes['query']) && $partes['query'] !== '') {
            parse_str($partes['query'], $query);
        }

        $query['donacion'] = $estado;

        $salida = '';

        if (isset($partes['scheme'])) {
            $salida .= $partes['scheme'].'://';
        }

        if (isset($partes['host'])) {
            $salida .= $partes['host'];
        }

        if (isset($partes['port'])) {
            $salida .= ':'.$partes['port'];
        }

        $salida .= (string) ($partes['path'] ?? '');

        $cadenaQuery = http_build_query($query);

        if ($cadenaQuery !== '') {
            $salida .= '?'.$cadenaQuery;
        }

        if (isset($partes['fragment']) && $partes['fragment'] !== '') {
            $salida .= '#'.$partes['fragment'];
        }

        return $salida;
    }
}
