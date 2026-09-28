<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Entrada y salida del panel de administración.
 *
 * Usa la autenticación nativa de Laravel, no sesiones montadas a mano: el
 * sistema de referencia gestionaba sus propias cookies y eso es exactamente lo
 * que no conviene reimplementar en algo que protege dinero.
 *
 * Cuatro defensas, cada una contra un ataque concreto:
 *
 *  1. Límite por IP **y** por usuario. Solo por IP, un atacante prueba mil
 *     usuarios desde una máquina; solo por usuario, ataca a uno desde mil IPs.
 *     Hacen falta las dos.
 *  2. `session()->regenerate()` al entrar: contra fijación de sesión, donde el
 *     atacante planta un id de sesión conocido antes del login y lo reutiliza
 *     después.
 *  3. Mensaje IDÉNTICO para usuario inexistente y contraseña incorrecta: si se
 *     distinguieran, el formulario serviría para averiguar qué usuarios existen.
 *  4. Todo intento queda registrado en el canal `admin`, con o sin éxito. Sin
 *     eso, un ataque de fuerza bruta no deja rastro.
 */
final class SesionController extends Controller
{
    /** Intentos permitidos antes de bloquear, por minuto. */
    private const INTENTOS_POR_MINUTO = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    public function mostrar(): View
    {
        return view('admin.sesion.login');
    }

    public function entrar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        $usuario = trim((string) $datos['username']);

        $this->comprobarLimite($peticion, $usuario);

        if (! Auth::guard('admin')->attempt(['username' => $usuario, 'password' => $datos['password']], false)) {
            RateLimiter::hit($this->claveDeIp($peticion), self::BLOQUEO_SEGUNDOS);
            RateLimiter::hit($this->claveDeUsuario($peticion, $usuario), self::BLOQUEO_SEGUNDOS);

            $this->registrar($peticion, $usuario, 'fallido');

            // Un único mensaje para los dos casos. Ver defensa 3.
            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->claveDeIp($peticion));
        RateLimiter::clear($this->claveDeUsuario($peticion, $usuario));

        // Defensa 2: id de sesión nuevo en cuanto hay privilegios.
        $peticion->session()->regenerate();

        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();
        $admin->forceFill(['last_login' => now()])->save();

        $this->registrar($peticion, $usuario, 'correcto', $admin);

        return redirect()->intended(route('admin.resumen'));
    }

    public function salir(Request $peticion): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        Auth::guard('admin')->logout();

        // Se tira la sesión entera y se renueva el token CSRF: si alguien
        // capturó cualquiera de los dos, deja de servirle.
        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        if ($admin !== null) {
            $this->registrar($peticion, (string) $admin->username, 'salida', $admin);
        }

        return redirect()->route('admin.login')->with('estado', 'Has cerrado la sesión.');
    }

    /**
     * Defensa 1. Se comprueban los dos contadores; basta con que uno esté
     * agotado para bloquear.
     */
    private function comprobarLimite(Request $peticion, string $usuario): void
    {
        foreach ([$this->claveDeIp($peticion), $this->claveDeUsuario($peticion, $usuario)] as $clave) {
            if (! RateLimiter::tooManyAttempts($clave, self::INTENTOS_POR_MINUTO)) {
                continue;
            }

            $segundos = RateLimiter::availableIn($clave);

            Log::channel('admin')->warning('Acceso al panel bloqueado por exceso de intentos', [
                'usuario' => $usuario,
                'ip' => $peticion->ip(),
                'segundos_restantes' => $segundos,
            ]);

            throw ValidationException::withMessages([
                'username' => __('auth.throttle', ['seconds' => $segundos]),
            ]);
        }
    }

    private function claveDeIp(Request $peticion): string
    {
        return 'admin-login:ip:'.$peticion->ip();
    }

    /**
     * El usuario se normaliza y se pasa por hash: la clave del limitador acaba
     * en la caché, y no tiene por qué guardar nombres de usuario en claro.
     */
    private function claveDeUsuario(Request $peticion, string $usuario): string
    {
        return 'admin-login:usuario:'.sha1(Str::lower($usuario));
    }

    private function registrar(Request $peticion, string $usuario, string $resultado, ?AdminUser $admin = null): void
    {
        // Nunca la contraseña, obviamente.
        Log::channel('admin')->info('Intento de acceso al panel', [
            'usuario' => $usuario,
            'resultado' => $resultado,
            'admin_id' => $admin?->id,
            'rol' => $admin?->role->value,
            'ip' => $peticion->ip(),
            'agente' => Str::limit((string) $peticion->userAgent(), 200),
            'momento' => now()->toIso8601String(),
        ]);
    }
}
