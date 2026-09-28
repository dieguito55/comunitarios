<?php

declare(strict_types=1);

namespace App\Http\Controllers\Donaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearDonacionQrRequest;
use App\Services\Donaciones\RegistrarDonacionQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * POST /api/donaciones/qr — el canal de Yape y Plin.
 *
 * ── POR QUÉ EXISTE ESTE CANAL ───────────────────────────────────────────────
 *
 * Mercado Pago Perú no expone una API de QR presencial. Yape dentro de Checkout
 * Pro funciona, pero cobra comisión y obliga a pasar por la pasarela. Este canal
 * es para quien prefiere transferir directo, y para ferias y afiches donde solo
 * hay un QR impreso.
 *
 * ── LO QUE ESTE ENDPOINT NO HACE ────────────────────────────────────────────
 *
 * No confirma nada. Registra una donación PENDIENTE con su comprobante y se
 * acabó: los contadores públicos no se mueven hasta que una persona verifique
 * la captura en el panel. Cualquier otra cosa sería publicar dinero que nadie
 * ha visto entrar.
 *
 * ── LO QUE NUNCA SALE EN LA RESPUESTA ───────────────────────────────────────
 *
 * `comprobante_path`. Revelaría la estructura del disco privado, y el archivo
 * es una captura bancaria con el nombre y el saldo de una persona. Solo viaja
 * el identificador de la donación y un mensaje.
 */
final class CrearDonacionQrController extends Controller
{
    public function __construct(private readonly RegistrarDonacionQr $registrar) {}

    public function __invoke(CrearDonacionQrRequest $peticion): JsonResponse
    {
        $comprobante = $peticion->file('comprobante');

        if (! $comprobante instanceof UploadedFile) {
            // La validación ya lo exige; esto cubre el caso en que PHP descarte
            // el archivo por límites del servidor (upload_max_filesize) y la
            // petición llegue sin él.
            return response()->json([
                'success' => false,
                'error' => 'No recibimos el comprobante. Puede que el archivo sea demasiado grande.',
            ], 422);
        }

        try {
            $donacion = ($this->registrar)(
                $peticion->datosDonacion(),
                $comprobante,
                $peticion->ip(),
            );
        } catch (Throwable $excepcion) {
            logger()->channel('payments')->error('Error interno al registrar la donación por QR', [
                'mensaje' => $excepcion->getMessage(),
                'excepcion' => $excepcion::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'No pudimos registrar tu comprobante en este momento. Inténtalo de nuevo.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'donacion_id' => $donacion->id,
            'mensaje' => 'Recibimos tu comprobante. Nuestro equipo lo verifica y tu aporte '
                .'aparecerá en el contador cuando quede confirmado.',
        ], 201);
    }
}
