<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fondo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Marca qué fondo sale preseleccionado en el formulario de donación.
 *
 * Delega en Fondo::marcarComoPredeterminado(), que ya garantiza en una
 * transacción que no quede más de uno marcado.
 */
final class PredeterminadoFondoController extends Controller
{
    public function __invoke(Fondo $fondo): RedirectResponse
    {
        $this->authorize('marcarPredeterminado', $fondo);

        $fondo->marcarComoPredeterminado();

        Log::channel('admin')->info('Fondo predeterminado cambiado', [
            'fondo_id' => $fondo->id,
            'slug' => $fondo->slug,
            'admin' => Auth::guard('admin')->user()?->username,
        ]);

        return redirect()
            ->route('admin.fondos.index')
            ->with('exito', "«{$fondo->nombre}» es ahora el fondo preseleccionado en el formulario.");
    }
}
