<?php

declare(strict_types=1);

namespace App\Services\MercadoPago;

use App\Models\Donacion;
use Carbon\CarbonImmutable;

/**
 * Traduce una donación ya guardada al cuerpo de la preferencia de Checkout Pro.
 *
 * Aquí viven cuatro de las diez reglas duras:
 *
 *   Regla 1 — external_reference = id de la donación, SIEMPRE string numérico.
 *             Es la única llave de cruce con Mercado Pago; sin ella, un pago
 *             cuyo webhook falle es irrecuperable.
 *   Regla 2 — el mismo id se duplica en metadata.donation_id, porque hay flujos
 *             en los que MP no propaga external_reference hasta el payment.
 *   Regla 3 — auto_return = 'approved' SOLO si back_urls.success es HTTPS. Con
 *             HTTP, MP rechaza la creación de la preferencia con un 400.
 *   Regla 8 — en sandbox no se envía el correo real del donante como
 *             payer.email: MP rechaza el pago si el pagador no es un usuario
 *             de prueba.
 */
final class ConstruirPreferencia
{
    public function __construct(private readonly NormalizarBackUrl $normalizarBackUrl) {}

    /** @return array<string, mixed> */
    public function __invoke(Donacion $donacion): array
    {
        $referenciaExterna = (string) $donacion->id;          // Regla 1
        $urlExito = ($this->normalizarBackUrl)((string) config('mercadopago.back_urls.success'), 'exitosa');
        $urlFallo = ($this->normalizarBackUrl)((string) config('mercadopago.back_urls.failure'), 'fallida');
        $urlPendiente = ($this->normalizarBackUrl)((string) config('mercadopago.back_urls.pending'), 'pendiente');

        // El fondo viaja en el título para que el donante lo vea en el
        // checkout de MP y en su comprobante: sabe a qué proyecto va su dinero
        // sin salir de la pasarela.
        $fondo = $donacion->fondo;

        $preferencia = [
            'items' => [[
                'id' => 'donacion-'.$referenciaExterna,
                'title' => 'Donación · '.($fondo?->nombre ?? (string) config('app.name')),
                'quantity' => 1,
                'currency_id' => (string) $donacion->moneda,
                'unit_price' => (float) $donacion->monto_referencial,
            ]],

            'external_reference' => $referenciaExterna,        // Regla 1

            'metadata' => [                                    // Regla 2
                'donation_id' => $referenciaExterna,

                // El destino del dinero también viaja a MP: si alguna vez hay
                // que reconstruir la contabilidad desde el lado de MP, el fondo
                // está ahí y no hay que cruzarlo con nuestra base.
                'fondo_id' => (string) $donacion->fondo_id,
                'fondo_slug' => (string) ($fondo?->slug ?? ''),

                'visible_publico' => $donacion->visible_publico ? 1 : 0,
            ],
        ];

        $backUrls = array_filter([
            'success' => $urlExito,
            'failure' => $urlFallo,
            'pending' => $urlPendiente,
        ], static fn (string $url): bool => $url !== '');

        if ($backUrls !== []) {
            $preferencia['back_urls'] = $backUrls;
        }

        // Regla 3: con success en HTTP, MP devuelve 400 al crear la preferencia.
        if (str_starts_with(mb_strtolower($urlExito), 'https://')) {
            $preferencia['auto_return'] = 'approved';
        }

        // Vigencia del enlace: que nadie pague una preferencia vieja. Usa su
        // propia clave de configuracion, distinta de `expiracion_horas`, que es
        // nuestra barrida interna de pendientes y significa otra cosa.
        $horas = max(1, (int) config('donaciones.preferencia_expira_horas'));
        $desde = CarbonImmutable::now();

        $preferencia['expires'] = true;
        $preferencia['expiration_date_from'] = $desde->format('Y-m-d\TH:i:s.vP');
        $preferencia['expiration_date_to'] = $desde->addHours($horas)->format('Y-m-d\TH:i:s.vP');

        $urlWebhook = trim((string) config('mercadopago.webhook_url'));

        if ($urlWebhook !== '') {
            $preferencia['notification_url'] = $urlWebhook;
        }

        $descriptor = trim((string) config('mercadopago.statement_descriptor'));

        if ($descriptor !== '') {
            $preferencia['statement_descriptor'] = mb_substr($descriptor, 0, 22);
        }

        $pagador = $this->pagador($donacion);

        if ($pagador !== []) {
            $preferencia['payer'] = $pagador;
        }

        return $preferencia;
    }

    /**
     * Datos del pagador. Regla dura 8.
     *
     * En producción van los del donante. En sandbox solo se envía un pagador si
     * es una cuenta de prueba de Mercado Pago: la configurada en
     * MP_SANDBOX_PAYER_EMAIL o un correo @testuser.com. En cualquier otro caso
     * se omite `payer` por completo y MP pide los datos en el checkout.
     *
     * @return array<string, mixed>
     */
    private function pagador(Donacion $donacion): array
    {
        $correo = (string) $donacion->correo;
        $nombre = (string) $donacion->nombre;

        if ($this->esProduccion()) {
            $pagador = ['name' => $nombre, 'email' => $correo];

            if ($this->esDni((string) $donacion->documento)) {
                $pagador['identification'] = ['type' => 'DNI', 'number' => (string) $donacion->documento];
            }

            return $pagador;
        }

        $correoDePrueba = trim((string) config('mercadopago.sandbox_payer_email'));

        if ($correoDePrueba !== '') {
            return ['email' => $correoDePrueba];
        }

        if (str_contains(mb_strtolower($correo), '@testuser.com')) {
            return ['name' => $nombre, 'email' => $correo];
        }

        return [];
    }

    private function esProduccion(): bool
    {
        return mb_strtolower((string) config('mercadopago.env')) === 'production';
    }

    /**
     * Solo se envía `identification` cuando es un DNI peruano: es el único tipo
     * que Checkout Pro acepta sin fricción en Perú. Un RUC o un pasaporte mal
     * tipificado hace que MP rechace el pagador.
     */
    private function esDni(string $documento): bool
    {
        return strlen($documento) === 8 && ctype_digit($documento);
    }
}
