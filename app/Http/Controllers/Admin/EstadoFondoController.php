<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CambiarEstadoFondoRequest;
use App\Models\Fondo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Publicar, pausar, cerrar o devolver a borrador un fondo.
 *
 * Solo superadmin: publicar un fondo es decidir que la organización va a pedir
 * dinero para algo. La confirmación consciente (escribir el nombre del fondo) y
 * el bloqueo del resumen "PENDIENTE" están en CambiarEstadoFondoRequest.
 */
final class EstadoFondoController extends Controller
{
    public function __invoke(CambiarEstadoFondoRequest $peticion, Fondo $fondo): RedirectResponse
    {
        $this->authorize('cambiarEstado', $fondo);

        $anterior = $fondo->estado;
        $nuevo = $peticion->estado();

        $fondo->forceFill(['estado' => $nuevo])->save();

        Log::channel('admin')->warning('Estado de fondo cambiado', [
            'fondo_id' => $fondo->id,
            'slug' => $fondo->slug,
            'estado_anterior' => $anterior->value,
            'estado_nuevo' => $nuevo->value,
            'admin' => Auth::guard('admin')->user()?->username,
        ]);

        $mensaje = $nuevo->aceptaDonaciones()
            ? "«{$fondo->nombre}» está PUBLICADO y ya puede recibir donaciones."
            : "«{$fondo->nombre}» pasó a {$nuevo->etiqueta()}. Sigue visible con lo recaudado, pero no admite aportes nuevos.";

        return redirect()->route('admin.fondos.editar', $fondo)->with('exito', $mensaje);
    }
}
