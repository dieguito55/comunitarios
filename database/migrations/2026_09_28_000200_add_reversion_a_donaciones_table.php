<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro de una verificación revertida.
 *
 * ── POR QUÉ COLUMNAS NUEVAS Y NO REUTILIZAR verificado_por / verificado_at ──
 *
 * La alternativa era limpiar esas dos al revertir. Se descartó porque borraría
 * quién tomó la primera decisión, que es justo lo que hay que poder auditar:
 * si alguien aprueba S/ 900 por error y otra persona lo revierte, la pregunta
 * que se va a hacer la fundación después es «¿quién aprobó esto?», y esa
 * respuesta no puede desaparecer al corregir el fallo.
 *
 * Con las dos parejas, la historia se lee entera:
 *   verificado_por / verificado_at   → quién decidió y cuándo
 *   revertido_por / revertido_at     → quién deshizo esa decisión y cuándo
 *   motivo_reversion                 → por qué
 *
 * Una donación revertida vuelve a `pendiente`, así que puede verificarse otra
 * vez. Al hacerlo, `verificado_*` se sobrescribe con la decisión nueva —que es
 * lo correcto: es la vigente— y el rastro de la reversión se queda para
 * explicar por qué hubo una segunda.
 *
 * ── LA CLAVE FORÁNEA NO SE AÑADE EN SQLITE, Y NO ES UN ATAJO ────────────────
 *
 * SQLite no sabe añadir una columna con restricción a una tabla existente: la
 * reconstruye entera, y `donaciones` tiene encima la vista
 * `v_fondos_conciliacion`, que hace fallar el renombrado con
 * «no such table: main.donaciones».
 *
 * Producción es MariaDB y ahí sí se añade, con su ON DELETE SET NULL. En la
 * suite de tests, que corre sobre SQLite en memoria, la columna queda como
 * entero indexado. La integridad referencial se mantiene donde vive el dato de
 * verdad, y los tests dejan de depender de una capacidad del motor que no
 * están probando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('revertido_por')->nullable()->after('motivo_rechazo');
            $tabla->timestamp('revertido_at')->nullable()->after('revertido_por');

            // 300 caracteres, como motivo_rechazo: es una nota operativa
            // —«aprobada por error, el comprobante era de otra donación»— no
            // un informe.
            $tabla->string('motivo_reversion', 300)->nullable()->after('revertido_at');

            $tabla->index('revertido_por');
        });

        if ($this->soportaRestricciones()) {
            Schema::table('donaciones', function (Blueprint $tabla): void {
                $tabla->foreign('revertido_por')
                    ->references('id')
                    ->on('admin_users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->soportaRestricciones()) {
            Schema::table('donaciones', function (Blueprint $tabla): void {
                $tabla->dropForeign(['revertido_por']);
            });
        }

        Schema::table('donaciones', function (Blueprint $tabla): void {
            $tabla->dropIndex(['revertido_por']);
            $tabla->dropColumn(['revertido_por', 'revertido_at', 'motivo_reversion']);
        });
    }

    private function soportaRestricciones(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }
};
