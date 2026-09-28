<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios del panel administrativo.
 *
 * Deuda técnica 1: esta migración NO crea ningún usuario ni contiene hash alguno.
 * El primer superadmin se crea con `php artisan make:superadmin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('password', 255);
            $table->string('role', 20)->default('editor');
            $table->timestamp('last_login')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
