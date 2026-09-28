<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubirMedioFondoRequest;
use App\Models\Fondo;
use App\Models\FondoMedio;
use App\Services\Fondos\GuardarImagenFondo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Galería de un fondo: añadir, quitar y reordenar imágenes.
 *
 * Es contenido, así que el editor también puede gestionarla.
 */
final class MedioFondoController extends Controller
{
    public function __construct(private readonly GuardarImagenFondo $guardarImagen) {}

    public function guardar(SubirMedioFondoRequest $peticion, Fondo $fondo): RedirectResponse
    {
        $this->authorize('gestionarMedios', $fondo);

        $ruta = ($this->guardarImagen)($fondo, $peticion->file('medio'));

        $fondo->medios()->create([
            'tipo' => 'imagen',
            'ruta' => $ruta,
            'alt' => $peticion->input('alt'),
            'orden' => (int) ($fondo->medios()->max('orden') ?? 0) + 1,
        ]);

        return redirect()
            ->route('admin.fondos.editar', $fondo)
            ->with('exito', 'Imagen añadida a la galería.');
    }

    public function eliminar(Fondo $fondo, FondoMedio $medio): RedirectResponse
    {
        $this->authorize('gestionarMedios', $fondo);

        abort_unless($medio->fondo_id === $fondo->id, 404);

        $this->guardarImagen->eliminar($medio->ruta);
        $medio->delete();

        return redirect()
            ->route('admin.fondos.editar', $fondo)
            ->with('exito', 'Imagen eliminada.');
    }

    public function reordenar(Request $peticion, Fondo $fondo): RedirectResponse
    {
        $this->authorize('gestionarMedios', $fondo);

        $datos = $peticion->validate([
            'orden' => ['required', 'array'],
            'orden.*' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($datos['orden'] as $medioId => $posicion) {
            $fondo->medios()->whereKey($medioId)->update(['orden' => (int) $posicion]);
        }

        return redirect()
            ->route('admin.fondos.editar', $fondo)
            ->with('exito', 'Orden de la galería actualizado.');
    }
}
