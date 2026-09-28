<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Galería de un fondo: las imágenes y vídeos que acompañan a su página, más
 * allá de la portada.
 *
 * Se borra en cascada con el fondo porque no tiene valor por sí sola: son
 * archivos de presentación, no dinero ni rastro contable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fondo_medios', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('fondo_id')
                ->constrained('fondos')
                ->cascadeOnDelete();

            $table->string('tipo', 10);            // imagen | video
            $table->string('ruta', 255);           // relativa dentro de public/
            $table->string('alt', 200)->nullable();
            $table->smallInteger('orden')->default(0);

            $table->timestamps();

            $table->index(['fondo_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fondo_medios');
    }
};
