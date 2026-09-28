<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RolAdmin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GuardarUsuarioAdminRequest;
use App\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Administradores del panel. Solo superadmin.
 *
 * Con dos salvaguardas contra dejar el sistema sin dueño: nadie puede quitarse
 * a sí mismo el rol de superadmin ni borrarse. Si fuera posible, un descuido
 * dejaría el panel sin nadie capaz de publicar un fondo ni de crear otro
 * administrador, y la única salida sería la consola del servidor.
 */
final class UsuarioAdminController extends Controller
{
    public function index(): View
    {
        $this->authorize('verCualquiera', AdminUser::class);

        return view('admin.usuarios.index', [
            'usuarios' => AdminUser::query()->orderBy('username')->get(),
            'roles' => RolAdmin::cases(),
            'yo' => Auth::guard('admin')->user(),
        ]);
    }

    public function crear(): View
    {
        $this->authorize('crear', AdminUser::class);

        return view('admin.usuarios.crear', [
            'usuario' => new AdminUser(['role' => RolAdmin::EDITOR]),
            'roles' => RolAdmin::cases(),
        ]);
    }

    public function guardar(GuardarUsuarioAdminRequest $peticion): RedirectResponse
    {
        $this->authorize('crear', AdminUser::class);

        $usuario = AdminUser::query()->create([
            'username' => $peticion->validated('username'),
            // El cast 'hashed' del modelo aplica bcrypt al asignar.
            'password' => $peticion->validated('password'),
            'role' => $peticion->rol(),
        ]);

        Log::channel('admin')->warning('Administrador creado', [
            'nuevo' => $usuario->username,
            'rol' => $usuario->role->value,
            'creado_por' => Auth::guard('admin')->user()?->username,
        ]);

        return redirect()
            ->route('admin.usuarios.index')
            ->with('exito', "Administrador «{$usuario->username}» creado.");
    }

    public function editar(AdminUser $usuario): View
    {
        $this->authorize('actualizar', $usuario);

        return view('admin.usuarios.editar', [
            'usuario' => $usuario,
            'roles' => RolAdmin::cases(),
            'esYo' => Auth::guard('admin')->user()?->is($usuario) ?? false,
        ]);
    }

    public function actualizar(GuardarUsuarioAdminRequest $peticion, AdminUser $usuario): RedirectResponse
    {
        $this->authorize('actualizar', $usuario);

        $yo = Auth::guard('admin')->user();
        $rolNuevo = $peticion->rol();

        // Salvaguarda: nadie se degrada a sí mismo.
        if ($yo !== null && $yo->is($usuario) && $rolNuevo !== $usuario->role) {
            return back()->withErrors([
                'role' => 'No puedes cambiar tu propio rol. Pídeselo a otro superadministrador: '
                    .'si pudieras degradarte, el sistema podría quedarse sin nadie capaz de publicar un fondo.',
            ])->withInput();
        }

        $usuario->username = (string) $peticion->validated('username');
        $usuario->role = $rolNuevo;

        // Vacía = no se cambia la contraseña.
        $password = (string) $peticion->validated('password');

        if ($password !== '') {
            $usuario->password = $password;
        }

        $usuario->save();

        Log::channel('admin')->warning('Administrador actualizado', [
            'usuario' => $usuario->username,
            'rol' => $usuario->role->value,
            'contrasena_cambiada' => $password !== '',
            'por' => $yo?->username,
        ]);

        return redirect()
            ->route('admin.usuarios.index')
            ->with('exito', "Administrador «{$usuario->username}» actualizado.");
    }

    public function eliminar(AdminUser $usuario): RedirectResponse
    {
        $yo = Auth::guard('admin')->user();

        // Salvaguarda: nadie se borra a sí mismo.
        if ($yo !== null && $yo->is($usuario)) {
            return redirect()
                ->route('admin.usuarios.index')
                ->withErrors(['usuario' => 'No puedes borrar tu propia cuenta.']);
        }

        $this->authorize('eliminar', $usuario);

        $nombre = (string) $usuario->username;
        $usuario->delete();

        Log::channel('admin')->warning('Administrador eliminado', [
            'usuario' => $nombre,
            'por' => $yo?->username,
        ]);

        return redirect()
            ->route('admin.usuarios.index')
            ->with('exito', "Administrador «{$nombre}» eliminado.");
    }
}
