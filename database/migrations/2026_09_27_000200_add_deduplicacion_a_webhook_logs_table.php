<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta para deduplicar notificaciones.
 *
 * Mercado Pago reenvía la misma notificación: ante un 5xx reintenta, y también
 * puede duplicar sin que haya habido error. Para reconocer "esta ya la procesé"
 * hay que comparar (topic, recurso, ts), y hasta ahora solo se guardaba el
 * topic en `evento`; el resto vivía enterrado dentro del JSON del payload, que
 * en MariaDB es un LONGTEXT y no se puede indexar de forma útil.
 *
 * Con estas dos columnas la comprobación es un índice, no un escaneo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table): void {
            // data.id de la notificación: id de pago o de orden comercial.
            $table->string('recurso_id', 80)->nullable()->after('evento');

            // El `ts` que viaja dentro de la cabecera x-signature.
            $table->string('ts_notificacion', 40)->nullable()->after('recurso_id');

            $table->index(['evento', 'recurso_id', 'ts_notificacion'], 'webhook_logs_dedup_index');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table): void {
            $table->dropIndex('webhook_logs_dedup_index');
            $table->dropColumn(['recurso_id', 'ts_notificacion']);
        });
    }
};
