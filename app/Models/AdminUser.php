<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolAdmin;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuario del panel administrativo.
 *
 * Se crea únicamente con `php artisan make:superadmin` o desde el CRUD del panel
 * (fase 5). Ninguna migración ni seeder lleva contraseñas (deuda técnica 1).
 *
 * @property int $id
 * @property string $username
 * @property RolAdmin $role
 */
class AdminUser extends Authenticatable
{
    protected $table = 'admin_users';

    protected $fillable = [
        'username',
        'password',
        'role',
        'last_login',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => RolAdmin::class,
            'last_login' => 'datetime',
        ];
    }

    /** Solo el superadmin corrige estados terminales y administra usuarios. */
    public function esSuperadmin(): bool
    {
        return $this->role === RolAdmin::SUPERADMIN;
    }

    /** Donaciones en efectivo que este admin registró (deuda técnica 8). */
    public function donacionesRegistradas(): HasMany
    {
        return $this->hasMany(Donacion::class, 'registrado_por');
    }

    /** Comprobantes QR que este admin verificó (deuda técnica 8). */
    public function donacionesVerificadas(): HasMany
    {
        return $this->hasMany(Donacion::class, 'verificado_por');
    }
}
