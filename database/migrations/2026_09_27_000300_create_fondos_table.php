<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondos: los proyectos a los que se dona.
 *
 * comunitarios.org no agrupa por regiones sino por proyectos, y puede haber
 * varios abiertos a la vez. El fondo es la unidad mínima de destino del dinero:
 * no hay sub-líneas ni rubros dentro. Cada sol tiene dueño desde el primer día.
 *
 * `recaudado` y `donaciones_count` están DENORMALIZADOS a propósito: los mueve
 * ReconciliarDonacion dentro de la misma transacción que aprueba el pago, con
 * bloqueo de la fila. Calcularlos con un SUM en cada visita a la portada no
 * escala y además pelea con la caché. La vista `v_fondos_conciliacion` existe
 * justamente para detectar si alguna vez dejan de cuadrar.
 *
 * `color_token` guarda el NOMBRE de un token de la paleta (`teal`, `coral`…),
 * nunca un hex: el color lo decide el CSS (deuda técnica 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fondos', function (Blueprint $table): void {
            $table->id();

            // Identidad pública
            $table->string('slug', 160)->unique();          // fundacion-antonia
            $table->string('nombre', 200);
            $table->string('resumen', 300);                 // una línea para la tarjeta
            $table->longText('descripcion')->nullable();    // página del fondo

            // Medios de portada. Rutas relativas dentro de public/.
            $table->string('imagen_portada', 255)->nullable();
            $table->string('video', 255)->nullable();

            // null = sin meta pública: la barra de progreso se oculta sola, en
            // vez de mostrar un porcentaje inventado.
            $table->decimal('meta', 12, 2)->nullable();
            $table->char('moneda', 3)->default('PEN');

            // Contadores denormalizados. Ver la nota de arriba.
            $table->decimal('recaudado', 12, 2)->default(0);
            $table->unsignedInteger('donaciones_count')->default(0);

            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            // borrador | activo | pausado | cerrado
            $table->string('estado', 20)->default('borrador');

            // Nombre de token de la paleta, no un color.
            $table->string('color_token', 20)->default('teal');

            $table->smallInteger('orden')->default(0);

            // El que sale preseleccionado en el formulario. Su unicidad se
            // garantiza en código (Fondo::marcarComoPredeterminado): un índice
            // único no serviría porque varios fondos valen `false`.
            $table->boolean('es_predeterminado')->default(false);

            $table->foreignId('creado_por')
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('estado');
            $table->index('orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fondos');
    }
};
