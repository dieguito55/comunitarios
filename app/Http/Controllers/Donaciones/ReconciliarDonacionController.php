<?php

declare(strict_types=1);

namespace App\Http\Controllers\Donaciones;

use App\Exceptions\MercadoPagoNoConfigurado;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReconciliarDonacionRequest;
use App\Models\Donacion;
use App\Services\Donaciones\ReconciliarDonacion;
use App\Services\Donaciones\ResultadoReconciliacion;
use App\Services\MercadoPago\BuscarPagosPorReferencia;
use App\Services\MercadoPago\ConsultarPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use MercadoPago\Exceptions\MPApiException;
use Throwable;

/**
 * POST /api/donaciones/reconciliar — segunda red de seguridad.
 *
 * Lo llama el navegador del donante al volver del checkout, porque el webhook
 * falla más de lo que parece: se cae el servidor, MP no llega, la URL pública
 * cambió. Es idempotente, así que el front puede reintentar sin miedo.
 *
 * ── Es público y sin sesión, así que se trata como HOSTIL ────────────────────
 *
 *  - El cliente no puede AFIRMAR nada. Lo que manda solo sirve para decidir qué
 *    preguntarle a Mercado Pago; el estado siempre sale de la API autenticada
 *    con nuestro token. Lo peor que consigue un atacante es gastarnos una
 *    consulta, y para eso está el límite por IP.
 *
 *  - La respuesta no revela NADA del donante: ni nombre, ni correo, ni monto.
 *    Solo el estado.
 *
 *  - Una donación inexistente responde exactamente igual que una pendiente. Si
 *    respondiera 404, este endpoint serviría para enumerar donaciones ajenas
 *    probando identificadores.
 */
final class ReconciliarDonacionController extends Controller
{
    public function __construct(
        private readonly ConsultarPago $consultarPago,
        private readonly BuscarPagosPorReferencia $buscarPagosPorReferencia,
        private readonly ReconciliarDonacion $reconciliarDonacion,
    ) {}

    public function __invoke(ReconciliarDonacionRequest $peticion): JsonResponse
    {
        try {
            $pagos = $this->pagosACotejar($peticion);
        } catch (MPApiException $excepcion) {
            return $this->errorDeMercadoPago($peticion, $excepcion);
        } catch (MercadoPagoNoConfigurado $excepcion) {
            Log::channel('payments')->critical($excepcion->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'La confirmación de pagos no está disponible en este momento.',
            ], 503);
        } catch (Throwable $excepcion) {
            Log::channel('payments')->error('Error interno reconciliando la donación', [
                'excepcion' => $excepcion::class,
                'mensaje' => $excepcion->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'No pudimos confirmar tu donación en este momento.',
            ], 500);
        }

        $estado = $this->estadoTrasReconciliar($pagos, $peticion);

        return response()->json([
            'success' => true,
            'estado' => $estado['estado'],
            'reconciliado' => $estado['reconciliado'],
        ]);
    }

    /**
     * Resuelve qué pagos hay que cotejar, en orden de preferencia: el pago
     * concreto, la donación, o la preferencia.
     *
     * @return list<array<string, mixed>>
     */
    private function pagosACotejar(ReconciliarDonacionRequest $peticion): array
    {
        if ($peticion->idPago() > 0) {
            return [($this->consultarPago)((string) $peticion->idPago())];
        }

        $idDonacion = $peticion->idDonacion();

        if ($idDonacion === 0 && $peticion->idPreferencia() !== '') {
            // La preferencia solo sirve para encontrar la donación; el pago se
            // busca después por external_reference, que es la llave de verdad.
            $idDonacion = (int) (Donacion::query()
                ->where('mp_preference_id', $peticion->idPreferencia())
                ->value('id') ?? 0);
        }

        return $idDonacion > 0 ? ($this->buscarPagosPorReferencia)((string) $idDonacion) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $pagos
     * @return array{estado: string, reconciliado: bool}
     */
    private function estadoTrasReconciliar(array $pagos, ReconciliarDonacionRequest $peticion): array
    {
        $reconciliado = false;
        $donacion = null;

        foreach ($pagos as $pago) {
            $resultado = ($this->reconciliarDonacion)($pago, 'reconciliacion');

            if ($resultado->donacion !== null) {
                $donacion = $resultado->donacion;
            }

            if ($resultado->resultado === ResultadoReconciliacion::ACTUALIZADO) {
                $reconciliado = true;
            }
        }

        if ($donacion === null && $peticion->idDonacion() > 0) {
            $donacion = Donacion::query()->find($peticion->idDonacion());
        }

        return [
            // Una donación que no existe se ve igual que una pendiente: este
            // endpoint no puede servir para enumerar donaciones ajenas.
            'estado' => $donacion?->estado->value ?? 'pendiente',
            'reconciliado' => $reconciliado,
        ];
    }

    private function errorDeMercadoPago(ReconciliarDonacionRequest $peticion, MPApiException $excepcion): JsonResponse
    {
        Log::channel('payments')->error('Mercado Pago falló durante la reconciliación', [
            'donacion_id' => $peticion->idDonacion(),
            'payment_id' => $peticion->idPago(),
            'status_code' => $excepcion->getStatusCode(),
            'respuesta' => $excepcion->getApiResponse()->getContent(),
        ]);

        return response()->json([
            'success' => false,
            'error' => 'No pudimos confirmar tu donación con Mercado Pago. Inténtalo en un momento.',
        ], 502);
    }
}
