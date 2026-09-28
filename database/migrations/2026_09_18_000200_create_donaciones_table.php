<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla única de donaciones: los tres canales (Mercado Pago, QR manual y
 * efectivo/tesorería) conviven aquí, con un solo modelo de estados.
 *
 * Dos invariantes que gobiernan todo el módulo:
 *
 *  1. `id` ES el `external_reference` que se envía a Mercado Pago. Es la única
 *     llave de cruce con MP y de ella dependen las tres redes de seguridad
 *     (webhook, reconciliación y rescate). NO CAMBIAR NUNCA.
 *  2. `monto_referencial` (lo que el donante declaró) y `monto_real` (lo que
 *     efectivamente entró) jamás se sobrescriben entre sí. La contabilidad usa
 *     COALESCE(monto_real, monto_referencial).
 *
 * Los campos de clasificación son VARCHAR con cast a enum en el modelo, no ENUM
 * de MySQL: añadir un caso nuevo a un ENUM exige un ALTER TABLE bloqueante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donaciones', function (Blueprint $table): void {
            // external_reference de Mercado Pago. Ver invariante 1.
            $table->id();

            // ── Identidad del donante ────────────────────────────────────────
            $table->string('nombre', 200);
            $table->string('documento', 20);           // DNI 8 / RUC 11 / pasaporte
            $table->string('correo', 200);
            $table->string('telefono', 30)->nullable();
            $table->string('tipo_aportante', 20)->default('persona');

            // ── Segmentación de campaña ──────────────────────────────────────
            $table->string('categoria', 80)->default('General');

            // ── Dinero. Ver invariante 2. ────────────────────────────────────
            $table->decimal('monto_referencial', 10, 2);
            $table->decimal('monto_real', 10, 2)->nullable();
            $table->char('moneda', 3)->default('PEN');

            // ── Origen del pago ──────────────────────────────────────────────
            $table->string('canal_pago', 20)->default('mercadopago');
            $table->string('proveedor_pago', 30)->default('mercadopago');
            $table->string('referencia_pago', 120)->nullable(); // operación, voucher, nota

            // ── Comprobante del canal QR ─────────────────────────────────────
            // Deuda técnica 5: ruta dentro del disco PRIVADO `comprobantes`,
            // nunca bajo public/. Solo se sirve por ruta autenticada con Policy.
            $table->string('comprobante_path', 255)->nullable();
            $table->string('comprobante_mime', 100)->nullable(); // MIME real detectado

            // ── Estado ───────────────────────────────────────────────────────
            $table->string('estado', 20)->default('pendiente');

            // ── Trazabilidad Mercado Pago ────────────────────────────────────
            // Regla dura 10: la preferencia se guarda apenas MP la devuelve; es
            // el plan B para encontrar el pago vía merchant_orders/search.
            $table->string('mp_preference_id', 200)->nullable();
            $table->string('mp_payment_id', 200)->nullable();
            $table->string('mp_status_detail', 80)->nullable();  // accredited, cc_rejected_*
            $table->decimal('mp_fee', 10, 2)->nullable();          // comisión real de MP
            $table->decimal('mp_net_received', 10, 2)->nullable(); // lo que recibe la organización

            // ── Consentimiento ───────────────────────────────────────────────
            $table->boolean('visible_publico')->default(true); // false = donante anónimo
            $table->boolean('acepta_terminos')->default(false);

            // Deuda técnica 11: los lotes anónimos se marcan con una columna, no
            // con referencias hardcodeadas en el código.
            $table->boolean('es_lote_anonimo')->default(false);

            // ── Auditoría: qué admin tocó el dinero (deuda técnica 8) ────────
            $table->foreignId('registrado_por')   // canal efectivo_manual
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete();

            $table->foreignId('verificado_por')   // canal qr_manual
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete();

            $table->timestamp('verificado_at')->nullable();

            $table->string('ip_origen', 45)->nullable();

            $table->timestamps();

            // ── Índices ──────────────────────────────────────────────────────
            $table->index('estado');
            $table->index('categoria');
            $table->index('canal_pago');
            $table->index('proveedor_pago');
            $table->index('mp_preference_id');

            // Un pago de MP se acredita UNA sola vez, aunque el webhook y la
            // reconciliación lleguen a la vez: la base de datos es el árbitro.
            $table->unique('mp_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donaciones');
    }
};
