<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ColorFondo;
use App\Enums\EstadoFondo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GuardarFondoRequest;
use App\Models\Fondo;
use App\Services\Fondos\CalcularMetricasFondo;
use App\Services\Fondos\GuardarImagenFondo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Alta, edición y borrado de fondos.
 *
 * La autorización va por FondoPolicy: un editor puede mejorar textos e
 * imágenes, pero abrir, publicar o borrar una campaña es decisión de la
 * organización y queda para el superadmin.
 */
final class FondoController extends Controller
{
    public function __construct(
        private readonly GuardarImagenFondo $guardarImagen,
        private readonly CalcularMetricasFondo $metricas,
    ) {}

    public function index(): View
    {
        $this->authorize('verCualquiera', Fondo::class);

        return view('admin.fondos.index', [
            'fondos' => Fondo::query()->ordenados()->get(),
            'metricas' => $this->metricas,
        ]);
    }

    public function crear(): View
    {
        $this->authorize('crear', Fondo::class);

        return view('admin.fondos.crear', [
            'fondo' => new Fondo(['color_token' => ColorFondo::TEAL, 'moneda' => (string) config('mercadopago.currency', 'PEN')]),
            'colores' => ColorFondo::cases(),
        ]);
    }

    public function guardar(GuardarFondoRequest $peticion): RedirectResponse
    {
        $this->authorize('crear', Fondo::class);

        $fondo = new Fondo($peticion->datosDelFondo());
        $fondo->estado = EstadoFondo::BORRADOR;   // Nace sin recibir dinero.
        $fondo->creado_por = Auth::guard('admin')->id();
        $fondo->save();

        if ($peticion->hasFile('imagen_portada')) {
            $fondo->forceFill([
                'imagen_portada' => ($this->guardarImagen)($fondo, $peticion->file('imagen_portada')),
            ])->save();
        }

        $this->registrar('fondo creado', $fondo);

        return redirect()
            ->route('admin.fondos.editar', $fondo)
            ->with('exito', "Fondo «{$fondo->nombre}» creado como borrador. Revísalo y publícalo cuando esté listo.");
    }

    public function editar(Fondo $fondo): View
    {
        $this->authorize('actualizar', $fondo);

        return view('admin.fondos.editar', [
            'fondo' => $fondo->load('medios'),
            'colores' => ColorFondo::cases(),
            'estados' => EstadoFondo::cases(),
            'metricas' => ($this->metricas)($fondo),
            'tieneDonaciones' => $fondo->donaciones()->exists(),
            'imagenes' => $this->guardarImagen,
        ]);
    }

    public function actualizar(GuardarFondoRequest $peticion, Fondo $fondo): RedirectResponse
    {
        $this->authorize('actualizar', $fondo);

        $fondo->fill($peticion->datosDelFondo());

        if ($peticion->hasFile('imagen_portada')) {
            // El servicio borra la anterior solo cuando la nueva ya está en disco.
            $fondo->imagen_portada = ($this->guardarImagen)(
                $fondo,
                $peticion->file('imagen_portada'),
                $fondo->getOriginal('imagen_portada')
            );
        }

        $fondo->save();

        $this->registrar('fondo actualizado', $fondo);

        return redirect()
            ->route('admin.fondos.editar', $fondo)
            ->with('exito', 'Cambios guardados.');
    }

    public function eliminar(Fondo $fondo): RedirectResponse
    {
        // La Policy ya comprueba que esté en borrador y sin donaciones.
        if (Auth::guard('admin')->user()->cannot('eliminar', $fondo)) {
            return redirect()
                ->route('admin.fondos.index')
                ->withErrors([
                    'fondo' => 'Este fondo no se puede borrar: solo se elimina un borrador que nunca recibió donaciones. '
                        .'Un fondo con historial se CIERRA, no se borra, porque su rastro es rendición de cuentas.',
                ]);
        }

        $nombre = (string) $fondo->nombre;

        // La carpeta de imágenes se va con él.
        $this->guardarImagen->eliminarCarpeta($fondo);
        $fondo->medios()->delete();
        $fondo->delete();

        Log::channel('admin')->warning('Fondo eliminado', [
            'fondo' => $nombre,
            'admin' => Auth::guard('admin')->user()?->username,
        ]);

        return redirect()
            ->route('admin.fondos.index')
            ->with('exito', "Fondo «{$nombre}» eliminado.");
    }

    private function registrar(string $accion, Fondo $fondo): void
    {
        Log::channel('admin')->info($accion, [
            'fondo_id' => $fondo->id,
            'slug' => $fondo->slug,
            'admin' => Auth::guard('admin')->user()?->username,
        ]);
    }
}
