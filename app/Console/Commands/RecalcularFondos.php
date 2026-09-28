<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Donacion;
use App\Models\Fondo;
use App\Services\Fondos\CalcularMetricasFondo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los contadores denormalizados de cada fondo desde las donaciones
 * reales, y dice qué encontró desviado.
 *
 * Es la reparación de lo que `v_fondos_conciliacion` detecta. Si este comando
 * encuentra diferencias de forma habitual, el problema NO se arregla
 * ejecutándolo más a menudo: hay un bug en quien mueve los contadores.
 *
 * Con `--dry-run` solo informa. Úsalo primero, siempre: así se ve el tamaño del
 * desvío antes de borrar la prueba del delito.
 */
class RecalcularFondos extends Command
{
    protected $signature = 'fondos:recalcular
                            {--dry-run : Solo informa de las diferencias, sin escribir nada}';

    protected $description = 'Recalcula recaudado y donaciones_count de cada fondo desde las donaciones reales';

    public function __construct(private readonly CalcularMetricasFondo $metricas)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $soloInforme = (bool) $this->option('dry-run');
        $estados = Fondo::estadosQueSuman();

        $fondos = Fondo::query()->ordenados()->get();

        if ($fondos->isEmpty()) {
            $this->warn('No hay fondos que recalcular.');

            return self::SUCCESS;
        }

        $filas = [];
        $desviados = 0;

        foreach ($fondos as $fondo) {
            $real = Donacion::query()
                ->where('fondo_id', $fondo->id)
                ->whereIn('estado', $estados)
                ->selectRaw('COALESCE(SUM(COALESCE(monto_real, monto_referencial)), 0) AS total, COUNT(*) AS cuantas')
                ->first();

            $totalReal = round((float) ($real->total ?? 0), 2);
            $cuentaReal = (int) ($real->cuantas ?? 0);

            $diferencia = round((float) $fondo->recaudado - $totalReal, 2);
            $diferenciaCuenta = (int) $fondo->donaciones_count - $cuentaReal;
            $desviado = abs($diferencia) >= 0.005 || $diferenciaCuenta !== 0;

            if ($desviado) {
                $desviados++;
            }

            $filas[] = [
                $fondo->slug,
                number_format((float) $fondo->recaudado, 2, '.', ''),
                number_format($totalReal, 2, '.', ''),
                number_format($diferencia, 2, '.', ''),
                (int) $fondo->donaciones_count.' → '.$cuentaReal,
                $desviado ? ($soloInforme ? 'DESVIADO' : 'CORREGIDO') : 'ok',
            ];

            if ($desviado && ! $soloInforme) {
                DB::transaction(function () use ($fondo, $totalReal, $cuentaReal): void {
                    Fondo::query()->lockForUpdate()->find($fondo->id)?->forceFill([
                        'recaudado' => $totalReal,
                        'donaciones_count' => $cuentaReal,
                    ])->save();
                });

                // Para que la cifra corregida se vea ya, sin esperar la caché.
                $this->metricas->olvidarCache($fondo);
            }
        }

        $this->table(
            ['fondo', 'contador', 'real', 'diferencia', 'donaciones', 'resultado'],
            $filas
        );

        if ($desviados === 0) {
            $this->info('Todos los contadores cuadran.');

            return self::SUCCESS;
        }

        if ($soloInforme) {
            $this->warn("{$desviados} fondo(s) con el contador desviado. Ejecuta sin --dry-run para corregirlos.");

            // Código distinto de 0 para que sirva en una comprobación automática.
            return self::FAILURE;
        }

        $this->warn("{$desviados} fondo(s) corregidos. Si esto se repite, hay un bug en quien mueve los contadores.");

        return self::SUCCESS;
    }
}
