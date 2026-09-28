<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ColorFondo;
use App\Enums\EstadoFondo;
use App\Models\Fondo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Da de alta un fondo desde la consola.
 *
 * Existe para que la organización pueda abrir campañas mientras el panel de
 * administración (fase 3B) no esté. No sustituye al panel: no gestiona medios
 * ni descripciones largas.
 *
 * Los contadores nacen en cero y NO se tocan desde aquí: los mueve
 * ReconciliarDonacion cuando entra dinero de verdad.
 */
class CrearFondo extends Command
{
    protected $signature = 'fondos:crear';

    protected $description = 'Crea un fondo de forma interactiva';

    public function handle(): int
    {
        $nombre = trim((string) $this->ask('Nombre del fondo'));

        if ($nombre === '') {
            $this->error('El nombre es obligatorio.');

            return self::FAILURE;
        }

        $slug = trim((string) $this->ask('Slug para la URL', Str::slug($nombre)));
        $resumen = trim((string) $this->ask('Resumen (una línea para la tarjeta)'));

        $validacion = Validator::make(
            ['slug' => $slug, 'resumen' => $resumen],
            [
                'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:fondos,slug'],
                'resumen' => ['required', 'string', 'max:300'],
            ],
            [
                'slug.regex' => 'El slug solo admite minúsculas, números y guiones simples.',
                'slug.unique' => 'Ya existe un fondo con ese slug.',
            ]
        );

        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $mensaje) {
                $this->error($mensaje);
            }

            return self::FAILURE;
        }

        // Vacío = sin meta pública: la barra de progreso se oculta sola, que es
        // mejor que publicar un objetivo inventado.
        $metaCruda = trim((string) $this->ask('Meta en PEN (vacío = sin meta pública)', ''));

        if ($metaCruda !== '' && (! is_numeric($metaCruda) || (float) $metaCruda <= 0)) {
            $this->error('La meta debe ser un número mayor que cero, o quedarse vacía.');

            return self::FAILURE;
        }

        $color = (string) $this->choice('Color (token de la paleta, no un hex)', ColorFondo::valores(), ColorFondo::TEAL->value);

        $estado = (string) $this->choice(
            'Estado inicial',
            array_column(EstadoFondo::cases(), 'value'),
            EstadoFondo::BORRADOR->value
        );

        $fondo = Fondo::query()->create([
            'slug' => $slug,
            'nombre' => $nombre,
            'resumen' => $resumen,
            'meta' => $metaCruda !== '' ? round((float) $metaCruda, 2) : null,
            'moneda' => (string) config('mercadopago.currency', 'PEN'),
            'color_token' => ColorFondo::from($color),
            'estado' => EstadoFondo::from($estado),
            'orden' => (int) (Fondo::query()->max('orden') ?? 0) + 1,
        ]);

        if ($this->confirm('¿Dejarlo como fondo predeterminado del formulario?', false)) {
            $fondo->marcarComoPredeterminado();
        }

        $this->info("Fondo «{$fondo->nombre}» creado (id {$fondo->id}, slug {$fondo->slug}, estado {$fondo->estado->etiqueta()}).");

        if (! $fondo->aceptaDonaciones()) {
            $this->warn('Está en '.$fondo->estado->etiqueta().': todavía NO recibe donaciones.');
        }

        return self::SUCCESS;
    }
}
