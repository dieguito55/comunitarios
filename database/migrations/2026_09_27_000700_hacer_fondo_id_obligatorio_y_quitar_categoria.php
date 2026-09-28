<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 3 de 3: `fondo_id` pasa a obligatorio y `categoria` desaparece.
 *
 * A partir de aquí es imposible registrar una donación sin destino: la base de
 * datos lo impide, no solo la validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table): void {
            $table->unsignedBigInteger('fondo_id')->nullable(false)->change();
        });

        Schema::table('donaciones', function (Blueprint $table): void {
            $table->dropIndex('donaciones_categoria_index');
            $table->dropColumn('categoria');
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table): void {
            $table->string('categoria', 80)->default('General')->after('tipo_aportante');
            $table->index('categoria');
        });

        Schema::table('donaciones', function (Blueprint $table): void {
            $table->unsignedBigInteger('fondo_id')->nullable()->change();
        });
    }
};
