<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ColorFondo;
use App\Enums\EstadoFondo;
use App\Models\Fondo;
use Illuminate\Database\Seeder;

/**
 * El primer fondo de comunitarios.org: "Fundación Antonia".
 *
 * Idempotente (`updateOrCreate` por slug): correrlo dos veces no duplica nada,
 * que es lo que permite dejarlo en el despliegue sin miedo.
 *
 * ⚠️ CUATRO CAMPOS ESTÁN SIN RELLENAR A PROPÓSITO: `resumen`, `descripcion`,
 * `meta`, `fecha_inicio` y `fecha_fin`. No conozco el texto institucional ni la
 * cifra objetivo, y cualquier cosa que inventara acabaría publicada en el sitio
 * de una fundación real. Los rellena la organización.
 *
 * Con `meta` en null la barra de progreso se oculta sola, así que el sitio
 * funciona mientras tanto sin enseñar un porcentaje falso.
 */
class FondoAntoniaSeeder extends Seeder
{
    public function run(): void
    {
        $fondo = Fondo::query()->updateOrCreate(
            ['slug' => 'fundacion-antonia'],
            [
                'nombre' => 'Fundación Antonia',

                // PENDIENTE de la organización. Ver la nota del encabezado.
                'resumen' => 'PENDIENTE: redactar resumen del fondo',
                'descripcion' => null,
                'meta' => null,
                'fecha_inicio' => null,
                'fecha_fin' => null,

                'moneda' => 'PEN',
                'estado' => EstadoFondo::ACTIVO,
                'color_token' => ColorFondo::TEAL,
                'orden' => 1,

                // Rutas relativas dentro de public/. Ambos archivos existen en
                // el repositorio.
                'video' => 'media/fondo_antonia.mp4',
                'imagen_portada' => 'media/comunitarios-fundacion-territorial-puno.jpg',
            ]
        );

        // Deja este como el único predeterminado, sin tocar sus contadores.
        $fondo->marcarComoPredeterminado();

        $this->command?->info("Fondo «{$fondo->nombre}» listo (id {$fondo->id}, slug {$fondo->slug}).");
        $this->command?->warn('PENDIENTE: resumen, descripción, meta y fechas los rellena la organización.');
    }
}
