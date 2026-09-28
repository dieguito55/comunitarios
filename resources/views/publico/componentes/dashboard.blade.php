{{--
    Dashboard en vivo de la campaña.

    Componente autónomo: se incluye desde cualquier vista y el JavaScript lo
    reconoce por `data-dashboard`. Se renderiza con las cifras que ya conoce el
    servidor, así que tiene sentido aunque el JS no llegue a ejecutarse; el
    módulo solo lo mantiene fresco cada 30 segundos.

    Para llevarlo a la portada basta con incluir esta misma línea en
    welcome.blade.php — ver el reporte de la fase 4.
--}}

@php
    $construir = app(\App\Services\Publico\ConstruirDashboard::class);
    $datos = $construir();
    $totales = $datos['totales'];
@endphp

<section class="don-dashboard" data-dashboard aria-labelledby="titulo-dashboard">
    <div class="don-contenedor">
        <h2 id="titulo-dashboard">Cómo va la campaña</h2>

        <div class="don-totales">
            <p class="don-cifra">
                <span data-total-recaudado>{{ $totales['moneda'] }} {{ number_format($totales['recaudado'], 2) }}</span>
                <small>Recaudado</small>
            </p>
            <p class="don-cifra">
                <span data-total-donaciones>{{ $totales['donaciones'] }}</span>
                <small>Donaciones</small>
            </p>
            <p class="don-cifra">
                <span data-total-donantes>{{ $totales['donantes_unicos'] }}</span>
                <small>Personas que han donado</small>
            </p>
        </div>

        <h3>Últimas donaciones</h3>

        {{-- aria-live: el feed se actualiza solo, y quien use lector de
             pantalla tiene que enterarse sin recargar. --}}
        <ul class="don-feed" data-feed aria-live="polite">
            @forelse ($datos['recientes'] as $reciente)
                <li>
                    <span>
                        <strong>{{ $reciente['nombre'] }}</strong>
                        <span class="don-feed__cuando">{{ $reciente['hace'] }}</span>
                    </span>
                    <span class="don-feed__monto">
                        {{ $totales['moneda'] }} {{ number_format($reciente['monto'], 2) }}
                    </span>
                </li>
            @empty
                <li class="don-feed__vacio">
                    Todavía no hay donaciones. Puedes ser la primera persona en aportar.
                </li>
            @endforelse
        </ul>
    </div>
</section>
