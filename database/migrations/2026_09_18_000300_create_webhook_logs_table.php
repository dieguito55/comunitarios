<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora cruda de cada notificación recibida de Mercado Pago.
 *
 * ESTA TABLA NUNCA SE PURGA: es la prueba forense cuando un pago se pierde.
 * Se escribe ANTES de procesar, para que quede rastro aunque el proceso falle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table): void {
            $table->id();

            // Cuerpo íntegro de la notificación, tal como llegó.
            $table->json('payload');

            // payment | merchant_order | ...
            $table->string('evento', 100)->nullable();

            // received | aprobado | ignored | mp_error | error
            $table->string('status', 50)->nullable();

            // Deuda técnica 9: resultado de validar la cabecera x-signature.
            $table->boolean('firma_valida')->default(false);

            // Cabecera x-request-id de MP: permite rastrear el caso con soporte.
            $table->string('x_request_id', 120)->nullable();

            $table->boolean('procesado')->default(false);

            // MP reintenta ante 5xx (regla dura 5); aquí se cuentan los reintentos.
            $table->unsignedTinyInteger('intentos')->default(0);

            $table->string('error_mensaje', 500)->nullable();

            // Sin updated_at: una notificación es un hecho, no se edita.
            $table->timestamp('created_at')->nullable();

            $table->index('status');
            $table->index('procesado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
