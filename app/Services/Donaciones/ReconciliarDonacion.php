<?php

declare(strict_types=1);

namespace App\Services\Donaciones;

use App\Enums\EstadoDonacion;
use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Fondos\MoverContadoresFondo;
use App\Services\MercadoPago\MapearEstadoMp;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ÚNICO lugar del sistema que escribe el estado y el dinero de una donación a
 * partir de un pago de Mercado Pago.
 *
 * Lo usan idéntico las tres redes de seguridad: el webhook (red 1), el endpoint
 * de reconciliación (red 2) y el rescate de la auditoría (red 3). Tener un solo
 * escritor es lo que garantiza que no puedan contradecirse: da igual cuál
 * llegue primero, el resultado es el mismo.
 *
 * Invariantes que defiende, cada una por un motivo concreto:
 *
 *   - `monto_referencial` (lo que el donante declaró) NUNCA se toca.
 *   - `monto_real` solo se escribe cuando el dinero entró de verdad, es decir
 *     cuando el pago quedó aprobado. Un pago rechazado no "entró", así que su
 *     importe no puede quedar registrado como real.
 *   - Las notificaciones viejas se descartan. Mercado Pago no garantiza orden:
 *     un `pending` puede llegar DESPUÉS de un `approved` del mismo pago, y sin
 *     esta comprobación revertiría una donación ya aprobada.
 *   - La fila se bloquea con lockForUpdate(). Sin eso, el webhook y la
 *     reconciliación que llegan a la vez leen el mismo estado inicial y el
 *     total puede subir dos veces.
 */
final class ReconciliarDonacion
{
    /** @var list<string> */
    private const ORIGENES = ['webhook', 'reconciliacion', 'auditoria'];

    public function __construct(
        private readonly MapearEstadoMp $mapearEstado,
        private readonly MoverContadoresFondo $moverContadores,
    ) {}

    /**
     * @param  array<string, mixed>  $pagoMp  Salida de ConsultarPago o BuscarPagosPorReferencia
     */
    public function __invoke(array $pagoMp, string $origen): ResultadoReconciliacion
    {
        $origen = in_array($origen, self::ORIGENES, true) ? $origen : 'desconocido';
        $idDePago = trim((string) ($pagoMp['id'] ?? ''));

        if ($idDePago === '') {
            return ResultadoReconciliacion::huerfano('El pago llegó sin identificador.');
        }

        $idDonacion = $this->localizar($pagoMp);

        if ($idDonacion === 0) {
            Log::channel('payments')->warning('Pago sin donación que le corresponda', [
                'origen' => $origen,
                'mp_payment_id' => $idDePago,
                'external_reference' => $pagoMp['external_reference'] ?? '',
                'estado_mp' => $pagoMp['status'] ?? '',
                'monto' => $pagoMp['transaction_amount'] ?? null,
            ]);

            return ResultadoReconciliacion::huerfano(
                'No existe ninguna donación para este pago (external_reference, metadata ni preferencia).'
            );
        }

        return DB::transaction(function () use ($pagoMp, $origen, $idDePago, $idDonacion): ResultadoReconciliacion {
            // Bloqueo pesimista: el webhook y la red 2 pueden llegar a la vez.
            $donacion = Donacion::query()->lockForUpdate()->find($idDonacion);

            if ($donacion === null) {
                return ResultadoReconciliacion::huerfano('La donación desapareció entre la búsqueda y el bloqueo.');
            }

            $estadoAnterior = $donacion->estado;

            if ($this->esNotificacionVieja($donacion, $pagoMp)) {
                Log::channel('payments')->info('Notificación descartada por llegar tarde', [
                    'origen' => $origen,
                    'donacion_id' => $donacion->id,
                    'mp_payment_id' => $idDePago,
                    'guardado' => optional($donacion->mp_date_last_updated)->toIso8601String(),
                    'entrante' => $pagoMp['date_last_updated'] ?? null,
                ]);

                return ResultadoReconciliacion::obsoleto($donacion, $estadoAnterior);
            }

            $pagoRegistrado = trim((string) $donacion->mp_payment_id);

            if ($pagoRegistrado !== '' && $pagoRegistrado !== $idDePago) {
                Log::channel('payments')->warning('La donación ya estaba vinculada a otro pago', [
                    'origen' => $origen,
                    'donacion_id' => $donacion->id,
                    'mp_payment_id_registrado' => $pagoRegistrado,
                    'mp_payment_id_entrante' => $idDePago,
                    'estado_mp_entrante' => $pagoMp['status'] ?? '',
                ]);

                return ResultadoReconciliacion::conflicto(
                    $donacion,
                    $estadoAnterior,
                    "La donación ya está vinculada al pago {$pagoRegistrado}; llegó el {$idDePago}."
                );
            }

            $estadoNuevo = ($this->mapearEstado)((string) ($pagoMp['status'] ?? ''));
            $cambios = $this->cambios($pagoMp, $estadoNuevo);

            if (! $this->hayAlgoQueEscribir($donacion, $cambios)) {
                return ResultadoReconciliacion::sinCambios($donacion, $estadoAnterior);
            }

            try {
                $donacion->update($cambios);
            } catch (UniqueConstraintViolationException $excepcion) {
                // El índice único de mp_payment_id es el árbitro final: ese pago
                // ya está acreditado en OTRA donación. Acreditarlo dos veces
                // inflaría el total público, así que la base de datos lo impide.
                Log::channel('payments')->error('El pago ya está acreditado en otra donación', [
                    'origen' => $origen,
                    'donacion_id' => $donacion->id,
                    'mp_payment_id' => $idDePago,
                    'error' => $excepcion->getMessage(),
                ]);

                return ResultadoReconciliacion::conflicto(
                    $donacion,
                    $estadoAnterior,
                    "El pago {$idDePago} ya está acreditado en otra donación."
                );
            }

            $donacion->refresh();

            // Los contadores del fondo, en ESTA misma transacción. Si algo
            // falla después, el dinero y su contador se van juntos.
            $movimiento = ($this->moverContadores)($donacion, $estadoAnterior, $donacion->estado);

            Log::channel('payments')->info('Donación reconciliada', [
                'origen' => $origen,
                'donacion_id' => $donacion->id,
                'mp_payment_id' => $idDePago,
                'estado_mp' => $pagoMp['status'] ?? '',
                'estado_anterior' => $estadoAnterior->value,
                'estado_nuevo' => $donacion->estado->value,
                'monto_real' => $donacion->monto_real !== null ? (float) $donacion->monto_real : null,
                'mp_fee' => $donacion->mp_fee !== null ? (float) $donacion->mp_fee : null,
                'fondo_id' => $donacion->fondo_id,
                'movimiento_del_fondo' => $movimiento,
            ]);

            return ResultadoReconciliacion::actualizado($donacion, $estadoAnterior, $donacion->estado);
        });
    }

    /**
     * Regla dura 1 y su plan B. En este orden porque es el orden de fiabilidad:
     * external_reference lo pone MP desde la preferencia; metadata.donation_id
     * es el respaldo para los flujos en que MP no lo propaga; la preferencia es
     * el último recurso.
     *
     * @param  array<string, mixed>  $pagoMp
     */
    private function localizar(array $pagoMp): int
    {
        $referencia = trim((string) ($pagoMp['external_reference'] ?? ''));

        if ($referencia !== '' && ctype_digit($referencia) && $this->existe((int) $referencia)) {
            return (int) $referencia;
        }

        $metadata = is_array($pagoMp['metadata'] ?? null) ? $pagoMp['metadata'] : [];

        foreach (['donation_id', 'donacion_id'] as $clave) {
            $respaldo = trim((string) ($metadata[$clave] ?? ''));

            if ($respaldo !== '' && ctype_digit($respaldo) && $this->existe((int) $respaldo)) {
                return (int) $respaldo;
            }
        }

        $preferencia = trim((string) ($pagoMp['preference_id'] ?? ''));

        if ($preferencia !== '') {
            $id = Donacion::query()->where('mp_preference_id', $preferencia)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return 0;
    }

    private function existe(int $id): bool
    {
        return Donacion::query()->whereKey($id)->exists();
    }

    /**
     * Una notificación es vieja si su `date_last_updated` es anterior al que ya
     * tenemos guardado. Si alguno de los dos falta, no se descarta nada: es
     * preferible reprocesar que perder una actualización.
     *
     * @param  array<string, mixed>  $pagoMp
     */
    private function esNotificacionVieja(Donacion $donacion, array $pagoMp): bool
    {
        $guardado = $donacion->mp_date_last_updated;
        $entrante = $this->fecha($pagoMp['date_last_updated'] ?? null);

        if ($guardado === null || $entrante === null) {
            return false;
        }

        return $entrante->lessThan($guardado);
    }

    /**
     * @param  array<string, mixed>  $pagoMp
     * @return array<string, mixed>
     */
    private function cambios(array $pagoMp, EstadoDonacion $estadoNuevo): array
    {
        $cambios = [
            'estado' => $estadoNuevo,
            'mp_payment_id' => (string) $pagoMp['id'],
            'mp_status_detail' => $pagoMp['status_detail'] ?? null,
            'mp_payment_method_id' => $pagoMp['payment_method_id'] ?? null,
            'mp_payment_type_id' => $pagoMp['payment_type_id'] ?? null,
            'mp_date_approved' => $this->fecha($pagoMp['date_approved'] ?? null),
            'mp_date_last_updated' => $this->fecha($pagoMp['date_last_updated'] ?? null),
            'mp_live_mode' => $pagoMp['live_mode'] ?? null,
        ];

        // El dinero solo se registra cuando entró de verdad.
        if ($estadoNuevo === EstadoDonacion::APROBADO) {
            $cambios['monto_real'] = $pagoMp['transaction_amount'] ?? null;
            $cambios['mp_fee'] = $pagoMp['fee_amount'] ?? null;
            $cambios['mp_net_received'] = $pagoMp['net_received_amount'] ?? null;
        }

        return $cambios;
    }

    /**
     * Evita escrituras y registros inútiles cuando la notificación no aporta
     * nada nuevo, que es el caso normal de un reenvío de Mercado Pago.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function hayAlgoQueEscribir(Donacion $donacion, array $cambios): bool
    {
        foreach ($cambios as $columna => $valor) {
            $actual = $donacion->getAttribute($columna);

            if (($actual === null) !== ($valor === null)) {
                return true;
            }

            if ($actual === null) {
                continue;
            }

            if ($actual instanceof DateTimeInterface && $valor instanceof DateTimeInterface) {
                $iguales = $actual->getTimestamp() === $valor->getTimestamp();
            } elseif (is_numeric($actual) && is_numeric($valor)) {
                $iguales = abs((float) $actual - (float) $valor) < 0.005;
            } else {
                $iguales = $actual == $valor;
            }

            if (! $iguales) {
                return true;
            }
        }

        return false;
    }

    private function fecha(mixed $valor): ?Carbon
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        try {
            // A UTC SIEMPRE. Mercado Pago envía las fechas con el desfase de su
            // zona (-05:00 en Perú) y Laravel, al escribir, formatea el Carbon
            // en la zona que traiga: se guardaría "12:00" en vez del instante
            // real (17:00 UTC) y al releerlo el instante habría cambiado. Eso
            // desplazaría la comparación de antigüedad y las fechas contables.
            // El proyecto guarda en UTC y convierte a America/Lima al mostrar.
            return Carbon::parse($texto)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
