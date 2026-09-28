<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\CanalPago;
use App\Enums\ColorFondo;
use App\Enums\EstadoDonacion;
use App\Enums\EstadoFondo;
use App\Enums\ProveedorPago;
use App\Enums\TipoAportante;
use App\Models\Donacion;
use App\Models\Fondo;

/**
 * Donaciones de prueba con valores realistas. Se comparte entre los tests del
 * webhook y los de la reconciliación para que ambos partan del mismo estado y
 * las diferencias entre ellos sean del comportamiento, no del montaje.
 */
trait CreaDonaciones
{
    /** @param array<string, mixed> $extra */
    protected function donacionPendiente(array $extra = []): Donacion
    {
        return Donacion::query()->create(array_merge([
            'nombre' => 'María Pérez',
            'documento' => '44556677',
            'correo' => 'maria@ejemplo.com',
            'telefono' => '+51 999 888 777',
            'tipo_aportante' => TipoAportante::PERSONA,
            'fondo_id' => $extra['fondo_id'] ?? $this->fondoDePrueba()->id,
            'monto_referencial' => 150.00,
            'moneda' => 'PEN',
            'canal_pago' => CanalPago::MERCADOPAGO,
            'proveedor_pago' => ProveedorPago::MERCADOPAGO,
            'estado' => EstadoDonacion::PENDIENTE,
            'visible_publico' => true,
            'acepta_terminos' => true,
            'mp_preference_id' => 'PREF-'.bin2hex(random_bytes(4)),
        ], $extra));
    }

    /**
     * Fondo activo reutilizable. Se crea una sola vez por test para que varias
     * donaciones compartan destino y los contadores se puedan comprobar.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function fondoDePrueba(array $extra = []): Fondo
    {
        $slug = (string) ($extra['slug'] ?? 'fondo-de-prueba');

        return Fondo::query()->firstOrCreate(
            ['slug' => $slug],
            array_merge([
                'nombre' => 'Fondo de prueba',
                'resumen' => 'Fondo usado por la suite de tests.',
                'moneda' => 'PEN',
                'estado' => EstadoFondo::ACTIVO,
                'color_token' => ColorFondo::TEAL,
                'orden' => 1,
                'es_predeterminado' => true,
            ], $extra)
        );
    }
}
