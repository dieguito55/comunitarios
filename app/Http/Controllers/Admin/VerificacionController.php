<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VerificarDonacionRequest;
use App\Models\AdminUser;
use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Donaciones\VerificarDonacionQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cola de verificación del canal QR.
 *
 * ── LA COLA VA POR ANTIGÜEDAD, NO POR IMPORTE ───────────────────────────────
 *
 * Las más viejas primero. Quien lleva más esperando a que le confirmen su
 * aporte es quien peor lo está pasando, y ordenar por monto convertiría la cola
 * en una lista de prioridades donde las donaciones pequeñas no se revisan nunca.
 *
 * ── EL COMPROBANTE NO TIENE URL PÚBLICA ─────────────────────────────────────
 *
 * Vive en el disco privado `comprobantes`, fuera de public/. La única forma de
 * verlo es esta ruta, que pasa por `auth:admin` y por DonacionPolicy. Un test
 * comprueba que sin sesión no se sirve el archivo (deuda técnica 5).
 */
final class VerificacionController extends Controller
{
    public function __construct(private readonly VerificarDonacionQr $verificar) {}

    public function index(Request $peticion): View
    {
        $this->authorize('verCola', Donacion::class);

        $estado = $this->estadoFiltrado($peticion);
        $fondoId = (int) $peticion->query('fondo', 0);

        $consulta = Donacion::query()
            ->with(['fondo', 'verificadoPor'])
            ->where('canal_pago', CanalPago::QR_MANUAL)
            ->when($estado !== null, fn ($q) => $q->where('estado', $estado))
            ->when($fondoId > 0, fn ($q) => $q->where('fondo_id', $fondoId))

            // Las más antiguas primero: quien lleva más esperando, primero.
            ->orderBy('created_at');

        return view('admin.verificacion.index', [
            'donaciones' => $consulta->paginate(25)->withQueryString(),
            'fondos' => Fondo::query()->ordenados()->get(),
            'estadoActivo' => $estado,
            'fondoActivo' => $fondoId,
            'pendientes' => self::pendientes(),
        ]);
    }

    /**
     * Sirve el comprobante DESDE EL DISCO PRIVADO.
     *
     * `Storage::download()` y no una redirección: una redirección a una URL del
     * disco solo tendría sentido si el disco fuera público, que es justo lo que
     * no queremos. El archivo lo lee PHP y lo entrega ya autorizado.
     */
    public function comprobante(Donacion $donacion): StreamedResponse
    {
        $this->authorize('verComprobante', $donacion);

        $disco = Storage::disk((string) config('donaciones.comprobante.disco', 'comprobantes'));
        $ruta = (string) $donacion->comprobante_path;

        abort_unless($disco->exists($ruta), 404);

        // `inline` para que el visor pueda mostrarlo sin descargarlo. El nombre
        // que se propone es nuestro, no el del archivo en disco: el hash no le
        // dice nada a nadie.
        return $disco->response($ruta, 'comprobante-donacion-'.$donacion->id.'.'.pathinfo($ruta, PATHINFO_EXTENSION), [
            'Content-Type' => (string) ($donacion->comprobante_mime ?: 'application/octet-stream'),

            // Que ningún proxy ni el navegador guarde una copia de una captura
            // bancaria más allá de la sesión que la pidió.
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verificar(VerificarDonacionRequest $peticion, Donacion $donacion): RedirectResponse
    {
        $this->authorize('verificar', $donacion);

        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        $resultado = $peticion->esAprobacion()
            ? $this->verificar->aprobar($donacion, $admin, $peticion->montoReal())
            : $this->verificar->rechazar($donacion, $admin, $peticion->motivo());

        if ($resultado->yaEstabaVerificada()) {
            $otra = $resultado->donacion?->verificadoPor;

            return back()->with('error', sprintf(
                'Esta donación ya fue revisada por %s el %s. No se ha cambiado nada.',
                $otra?->nombre ?? 'otra persona',
                $resultado->donacion?->verificado_at?->timezone(
                    (string) config('donaciones.zona_horaria_display', 'America/Lima')
                )->format('d/m/Y H:i') ?? 'hace un momento',
            ));
        }

        if (! $resultado->fueDecidida()) {
            return back()->with('error', 'No encontramos esa donación. Puede que otra persona la haya eliminado.');
        }

        $donacionDecidida = $resultado->donacion;

        if ($peticion->esAprobacion() && $donacionDecidida !== null) {
            return back()->with('exito', sprintf(
                'Aprobada. Se registraron %s %s y el fondo ya los refleja.',
                $donacionDecidida->moneda,
                number_format((float) $donacionDecidida->monto_real, 2),
            ));
        }

        return back()->with('exito', 'Rechazada. Los contadores no se han movido.');
    }

    /** Cuántas esperan. Lo usa también el resumen del panel. */
    public static function pendientes(): int
    {
        return Donacion::query()
            ->where('canal_pago', CanalPago::QR_MANUAL)
            ->where('estado', EstadoDonacion::PENDIENTE)
            ->count();
    }

    /** El filtro de estado, o null para «todos». */
    private function estadoFiltrado(Request $peticion): ?EstadoDonacion
    {
        $crudo = trim((string) $peticion->query('estado', EstadoDonacion::PENDIENTE->value));

        if ($crudo === 'todos') {
            return null;
        }

        return EstadoDonacion::tryFrom($crudo) ?? EstadoDonacion::PENDIENTE;
    }
}
