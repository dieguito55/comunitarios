<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo del rechazo en la verificación manual del canal QR.
 *
 * Solo se usa ahí. En Mercado Pago el motivo ya vive en `mp_status_detail`,
 * que lo escribe la propia pasarela; duplicarlo sería tener dos versiones de
 * la misma verdad.
 *
 * 300 caracteres: es una nota operativa —«la captura no se lee», «el monto no
 * coincide con ningún movimiento»— no un informe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donaciones', function (Blueprint $tabla): void {
            $tabla->string('motivo_rechazo', 300)
                ->nullable()
                ->after('verificado_at');
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $tabla): void {
            $tabla->dropColumn('motivo_rechazo');
        });
    }
};
