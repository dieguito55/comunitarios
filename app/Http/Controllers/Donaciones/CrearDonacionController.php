<?php

declare(strict_types=1);

namespace App\Http\Controllers\Donaciones;

use App\Exceptions\MercadoPagoNoConfigurado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CrearDonacionRequest;
use App\Services\MercadoPago\CrearPreferencia;
use Illuminate\Http\JsonResponse;
use MercadoPago\Exceptions\MPApiException;
use Throwable;

/**
 * POST /api/donaciones/mercadopago
 *
 * Registra la donación y devuelve el init_point al que redirigir al donante.
 *
 * El navegador debe guardar `donacion_id` y `preference_id` ANTES de redirigir:
 * al volver del checkout, la URL puede no traer payment_id y esas dos llaves
 * son lo único que permite reconciliar (segunda red de seguridad).
 */
final class CrearDonacionController extends Controller
{
    public function __construct(private readonly CrearPreferencia $crearPreferencia) {}

    public function __invoke(CrearDonacionRequest $request): JsonResponse
    {
        try {
            $resultado = ($this->crearPreferencia)($request->datosDonacion(), $request->ip());
        } catch (MPApiException $excepcion) {
            // Mercado Pago respondió, pero con un error. El detalle va al log;
            // al donante no se le muestra nunca la respuesta cruda de MP.
            logger()->channel('payments')->error('Mercado Pago rechazó la creación de la preferencia', [
                'mensaje' => $excepcion->getMessage(),
                'status_code' => $excepcion->getStatusCode(),
                'respuesta' => $excepcion->getApiResponse()->getContent(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'No se pudo iniciar el pago. Vuelve a intentarlo en un momento.',
            ], 502);
        } catch (MercadoPagoNoConfigurado $excepcion) {
            logger()->channel('payments')->critical($excepcion->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'El pago en línea no está disponible en este momento.',
            ], 503);
        } catch (Throwable $excepcion) {
            logger()->channel('payments')->error('Error interno al crear la donación', [
                'mensaje' => $excepcion->getMessage(),
                'excepcion' => $excepcion::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'No pudimos procesar tu donación en este momento.',
            ], 500);
        }

        return response()->json(['success' => true] + $resultado->aRespuesta());
    }
}
