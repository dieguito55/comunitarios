<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del pago que el webhook necesita guardar.
 *
 * `mp_date_last_updated` es la columna crítica: Mercado Pago no garantiza el
 * orden de las notificaciones, así que un `pending` puede llegar DESPUÉS de un
 * `approved` del mismo pago. Sin la marca de tiempo de MP no hay forma de
 * distinguir "esto es nuevo" de "esto llegó tarde", y una donación aprobada
 * volvería a en_proceso sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $table): void {
            // visa, master, yape, account_money…
            $table->string('mp_payment_method_id', 40)->nullable()->after('mp_status_detail');

            // credit_card, debit_card, ticket, bank_transfer…
            $table->string('mp_payment_type_id', 40)->nullable()->after('mp_payment_method_id');

            $table->timestamp('mp_date_approved')->nullable()->after('mp_net_received');

            // Ver la nota de arriba: sin esto no se puede ordenar lo desordenado.
            $table->timestamp('mp_date_last_updated')->nullable()->after('mp_date_approved');

            // false = dinero de prueba. Distingue sandbox de producción en la
            // misma tabla si alguna vez se mezclan.
            $table->boolean('mp_live_mode')->nullable()->after('mp_date_last_updated');
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table): void {
            $table->dropColumn([
                'mp_payment_method_id',
                'mp_payment_type_id',
                'mp_date_approved',
                'mp_date_last_updated',
                'mp_live_mode',
            ]);
        });
    }
};
