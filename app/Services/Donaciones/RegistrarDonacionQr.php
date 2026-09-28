<?php

declare(strict_types=1);

namespace App\Services\Donaciones;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Models\Donacion;
use App\Rules\ComprobanteSeguro;
use App\Rules\ImagenSegura;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Registra una donación del canal QR y guarda su comprobante.
 *
 * ── LA DONACIÓN NACE SIN DINERO CONFIRMADO ──────────────────────────────────
 *
 * `monto_referencial` es lo que el donante DECLARÓ haber transferido.
 * `monto_real` nace en null y lo fija un administrador al verificar la captura.
 * Aquí es donde esa distinción se gana el sueldo: alguien declara S/ 100 y
 * transfiere S/ 80, y las dos cifras tienen que convivir para siempre —una es
 * lo que dijo, la otra lo que entró.
 *
 * Por eso el estado inicial es PENDIENTE y los contadores del fondo NO se
 * mueven: nada entra en las cifras públicas hasta que una persona lo confirme.
 *
 * ── EL ARCHIVO ──────────────────────────────────────────────────────────────
 *
 * Va al disco PRIVADO `comprobantes`, fuera de public/, y se sirve solo por una
 * ruta autenticada con Policy. Es una captura bancaria con el nombre y el saldo
 * de una persona: una URL adivinable sería una filtración.
 *
 * El nombre lo ponemos nosotros —bytes aleatorios más la extensión del tipo
 * REAL— y jamás el que traía el archivo: el del usuario puede contener rutas,
 * caracteres de control o simplemente el nombre de otra persona.
 */
final class RegistrarDonacionQr
{
    /**
     * @param  array<string, mixed>  $datos  Salida de CrearDonacionQrRequest::datosDonacion()
     */
    public function __invoke(array $datos, UploadedFile $comprobante, ?string $ipOrigen = null): Donacion
    {
        [$ruta, $tipoReal] = $this->guardarComprobante($comprobante);

        try {
            $donacion = DB::transaction(fn (): Donacion => Donacion::query()->create([
                'nombre' => $datos['nombre'],
                'documento' => $datos['documento'],
                'correo' => $datos['correo'],
                'telefono' => $datos['telefono'],
                'tipo_aportante' => $datos['tipo_aportante'],
                'fondo_id' => $datos['fondo_id'],

                // Lo DECLARADO. monto_real se queda en null hasta la verificación.
                'monto_referencial' => $datos['monto'],
                'monto_real' => null,
                'moneda' => $datos['moneda'],

                'canal_pago' => CanalPago::QR_MANUAL,
                'proveedor_pago' => $datos['proveedor_pago'],
                'referencia_pago' => $datos['referencia_pago'],

                'comprobante_path' => $ruta,
                'comprobante_mime' => $tipoReal,

                'estado' => EstadoDonacion::PENDIENTE,

                'visible_publico' => $datos['visible_publico'],
                'acepta_terminos' => $datos['acepta_terminos'],
                'ip_origen' => $ipOrigen,
            ]));
        } catch (Throwable $excepcion) {
            // Si el INSERT falla, el archivo ya subido se queda huérfano en el
            // disco. Se borra aquí porque nadie más va a saber que existe.
            Storage::disk($this->disco())->delete($ruta);

            throw $excepcion;
        }

        Log::channel('payments')->info('Donación por QR registrada, pendiente de verificación', [
            'donacion_id' => $donacion->id,
            'fondo_id' => $donacion->fondo_id,
            'proveedor' => $donacion->proveedor_pago->value,
            'monto_referencial' => (float) $donacion->monto_referencial,
            'tiene_referencia' => $donacion->referencia_pago !== null,
            'comprobante_mime' => $tipoReal,
        ]);

        return $donacion;
    }

    /**
     * Guarda el archivo y devuelve [ruta relativa, tipo real].
     *
     * La ruta es `YYYY/MM/<32 hex>.<ext>`: agrupar por mes mantiene los
     * directorios manejables cuando haya miles, y el nombre aleatorio hace que
     * no se pueda adivinar aunque algún día el disco quedara expuesto.
     *
     * @return array{0: string, 1: string}
     */
    private function guardarComprobante(UploadedFile $archivo): array
    {
        $rutaTemporal = $archivo->getRealPath();

        if ($rutaTemporal === false) {
            throw new RuntimeException('No se pudo leer el comprobante subido.');
        }

        // El tipo se vuelve a leer del contenido. La regla de validación ya lo
        // comprobó, pero de ahí no viaja nada hasta aquí: este servicio no da
        // por bueno un dato que no haya mirado él mismo.
        $tipoReal = ImagenSegura::tipoReal($rutaTemporal);
        $extension = ComprobanteSeguro::extensionDe($tipoReal);

        if ($extension === null) {
            throw new RuntimeException('Tipo de comprobante no admitido: '.$tipoReal);
        }

        $ruta = now()->format('Y/m').'/'.bin2hex(random_bytes(16)).'.'.$extension;

        $guardado = Storage::disk($this->disco())->putFileAs(
            dirname($ruta),
            $archivo,
            basename($ruta)
        );

        if ($guardado === false) {
            throw new RuntimeException('No se pudo guardar el comprobante en el disco privado.');
        }

        return [$ruta, $tipoReal];
    }

    private function disco(): string
    {
        return (string) config('donaciones.comprobante.disco', 'comprobantes');
    }
}
