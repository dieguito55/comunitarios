<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 1 de 3 del cambio `categoria` → `fondo_id`.
 *
 * Se añade la columna NULLABLE para poder rellenarla antes de exigirla. Hacerlo
 * en tres migraciones separadas es lo que permite repetir exactamente la misma
 * secuencia en cPanel sobre una base que ya tiene filas.
 *
 * restrictOnDelete: un fondo con donaciones NO se borra nunca. Perder el
 * destino de un sol ya recibido rompería la rendición de cuentas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table): void {
            $table->foreignId('fondo_id')
                ->nullable()
                ->after('tipo_aportante')
                ->constrained('fondos')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table): void {
            $table->dropForeign(['fondo_id']);
            $table->dropColumn('fondo_id');
        });
    }
};
