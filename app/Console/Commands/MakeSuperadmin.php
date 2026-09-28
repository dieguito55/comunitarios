<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolAdmin;
use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crea el primer usuario del panel.
 *
 * Deuda técnica 1: el sistema de referencia traía el hash del superadmin dentro
 * de schema.sql, de modo que todas las instalaciones compartían contraseña y
 * quedaba escrita en el repositorio. Aquí la contraseña se pide por consola,
 * oculta y con confirmación, y nunca se acepta como argumento: un argumento
 * acabaría en el historial del shell y en los logs del proceso.
 */
class MakeSuperadmin extends Command
{
    protected $signature = 'make:superadmin
                            {username? : Nombre de usuario del superadministrador}';

    protected $description = 'Crea un usuario del panel con rol superadmin';

    public function handle(): int
    {
        $username = (string) ($this->argument('username') ?? '');

        if ($username === '') {
            $username = (string) $this->ask('Nombre de usuario');
        }

        $username = trim($username);

        $validacion = Validator::make(
            ['username' => $username],
            ['username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/']],
            ['username.regex' => 'El usuario solo admite letras, números, punto, guion y guion bajo.']
        );

        if ($validacion->fails()) {
            $this->error((string) $validacion->errors()->first('username'));

            return self::FAILURE;
        }

        if (AdminUser::query()->where('username', $username)->exists()) {
            $this->error("El usuario «{$username}» ya existe.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Contraseña (no se muestra)');
        $confirmacion = (string) $this->secret('Repite la contraseña');

        if ($password === '' || strlen($password) < 12) {
            $this->error('La contraseña debe tener al menos 12 caracteres.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirmacion)) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        // El cast 'hashed' del modelo aplica bcrypt al asignar.
        $admin = AdminUser::query()->create([
            'username' => $username,
            'password' => $password,
            'role' => RolAdmin::SUPERADMIN,
        ]);

        $this->info("Superadmin «{$admin->username}» creado (id {$admin->id}).");

        return self::SUCCESS;
    }
}
