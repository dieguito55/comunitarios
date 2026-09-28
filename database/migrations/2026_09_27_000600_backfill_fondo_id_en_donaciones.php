<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paso 2 de 3: todas las donaciones existentes pasan al fondo predeterminado.
 *
 * Si no hay fondo predeterminado, esta migración FALLA a propósito. La
 * alternativa —seguir adelante— dejaría filas con `fondo_id` nulo que el paso 3
 * no podría convertir en NOT NULL, y el despliegue reventaría más tarde y más
 * lejos, con un error mucho menos claro que este.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pendientes = DB::table('donaciones')->whereNull('fondo_id')->count();

        if ($pendientes === 0) {
            return;
        }

        $fondoId = DB::table('fondos')->where('es_predeterminado', true)->value('id')
            ?? DB::table('fondos')->orderBy('orden')->orderBy('id')->value('id');

        if ($fondoId === null) {
            throw new RuntimeException(
                "Hay {$pendientes} donaciones sin fondo y no existe ningún fondo al que asignarlas. "
                .'Ejecuta primero:  php artisan db:seed --class=FondoAntoniaSeeder'
            );
        }

        DB::table('donaciones')->whereNull('fondo_id')->update(['fondo_id' => $fondoId]);
    }

    public function down(): void
    {
        // No se revierte: no hay forma de saber qué donaciones no tenían fondo
        // antes, y dejarlas en NULL sería peor que dejarlas asignadas.
    }
};
