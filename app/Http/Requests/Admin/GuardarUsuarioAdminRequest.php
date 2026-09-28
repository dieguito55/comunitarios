<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RolAdmin;
use App\Models\AdminUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de administradores.
 *
 * La contraseña mínima son 12 caracteres, igual que en `make:superadmin`: dos
 * puertas al mismo sitio no pueden tener cerraduras distintas.
 */
final class GuardarUsuarioAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $objetivo = $this->usuario();
        $esAlta = $objetivo === null;

        return [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('admin_users', 'username')->ignore($objetivo?->getKey()),
            ],

            // Al editar, dejar la contraseña vacía significa "no la cambies".
            'password' => [$esAlta ? 'required' : 'nullable', 'string', 'min:12', 'confirmed'],

            'role' => ['required', Rule::enum(RolAdmin::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.regex' => 'El nombre de usuario solo admite letras, números, punto, guion y guion bajo.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
        ];
    }

    public function usuario(): ?AdminUser
    {
        $usuario = $this->route('usuario');

        return $usuario instanceof AdminUser ? $usuario : null;
    }

    public function rol(): RolAdmin
    {
        return RolAdmin::from((string) $this->input('role', RolAdmin::EDITOR->value));
    }
}
