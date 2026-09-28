<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CanalPago;
use App\Enums\EstadoDonacion;
use App\Models\AdminUser;
use App\Models\Donacion;

/**
 * Quién puede verificar una donación del canal QR y ver su comprobante.
 *
 * ── POR QUÉ UN EDITOR SÍ PUEDE ──────────────────────────────────────────────
 *
 * En FondoPolicy la línea está en las decisiones de gobierno: publicar una
 * campaña o cerrarla es de la organización, no de quien redacta. Verificar un
 * comprobante es lo contrario: es trabajo operativo, hay que hacerlo todos los
 * días y con poca demora. Si solo pudiera un superadministrador, la cola se
 * atascaría los días que esa persona no entra, y quien donó vería su aporte
 * sin aparecer.
 *
 * Que la decisión quede registrada —`verificado_por` y `verificado_at`— es lo
 * que hace que sea seguro repartirla.
 *
 * ── EL COMPROBANTE ES EL DATO SENSIBLE ──────────────────────────────────────
 *
 * Es una captura bancaria con el nombre y a menudo el saldo de una persona.
 * Vive en un disco privado y solo se sirve por una ruta que pasa por aquí.
 * Nunca por URL directa (deuda técnica 5).
 */
final class DonacionPolicy
{
    /** La cola de verificación es trabajo operativo: la ven los dos roles. */
    public function verCola(AdminUser $admin): bool
    {
        return true;
    }

    /**
     * Ver el comprobante de UNA donación concreta.
     *
     * Solo tiene sentido en las que lo tienen: pedir el de una donación de
     * Mercado Pago no es un permiso denegado, es una pregunta sin respuesta.
     */
    public function verComprobante(AdminUser $admin, Donacion $donacion): bool
    {
        return $donacion->canal_pago === CanalPago::QR_MANUAL
            && trim((string) $donacion->comprobante_path) !== '';
    }

    /**
     * Aprobar o rechazar.
     *
     * Solo sobre donaciones del canal manual y solo mientras sigan pendientes:
     * una ya verificada no se re-verifica desde aquí. Cambiar una decisión ya
     * tomada es otra operación, con otro rastro, y todavía no existe.
     */
    public function verificar(AdminUser $admin, Donacion $donacion): bool
    {
        return $donacion->canal_pago === CanalPago::QR_MANUAL
            && $donacion->estado === EstadoDonacion::PENDIENTE;
    }
}
