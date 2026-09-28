<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RolAdmin;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El primer superadmin solo puede nacer aquí: ninguna migración ni seeder lleva
 * contraseñas (deuda técnica 1). En Windows el prompt oculto usa hiddeninput.exe
 * y no se puede alimentar por tubería, así que esta es la verificación real del
 * comando.
 */
class MakeSuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_superadmin_con_la_contrasena_hasheada(): void
    {
        $this->artisan('make:superadmin')
            ->expectsQuestion('Nombre de usuario', 'tesoreria')
            ->expectsQuestion('Contraseña (no se muestra)', 'ClaveDePrueba2026')
            ->expectsQuestion('Repite la contraseña', 'ClaveDePrueba2026')
            ->assertSuccessful();

        $admin = AdminUser::query()->where('username', 'tesoreria')->firstOrFail();

        $this->assertSame(RolAdmin::SUPERADMIN, $admin->role);
        $this->assertTrue($admin->esSuperadmin());

        // La contraseña se guarda hasheada, nunca en claro.
        $this->assertNotSame('ClaveDePrueba2026', $admin->getAuthPassword());
        $this->assertTrue(Hash::check('ClaveDePrueba2026', $admin->getAuthPassword()));
    }

    public function test_rechaza_un_usuario_que_ya_existe(): void
    {
        AdminUser::query()->create([
            'username' => 'tesoreria',
            'password' => 'ClaveDePrueba2026',
            'role' => RolAdmin::SUPERADMIN,
        ]);

        $this->artisan('make:superadmin tesoreria')->assertFailed();

        $this->assertSame(1, AdminUser::query()->where('username', 'tesoreria')->count());
    }

    public function test_rechaza_si_la_confirmacion_no_coincide(): void
    {
        $this->artisan('make:superadmin')
            ->expectsQuestion('Nombre de usuario', 'tesoreria')
            ->expectsQuestion('Contraseña (no se muestra)', 'ClaveDePrueba2026')
            ->expectsQuestion('Repite la contraseña', 'OtraClaveDistinta2026')
            ->assertFailed();

        $this->assertSame(0, AdminUser::query()->count());
    }
}
