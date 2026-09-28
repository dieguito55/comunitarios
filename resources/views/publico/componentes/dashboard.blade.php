{{--
    Dashboard en vivo de la campaña.

    Componente autónomo: se incluye desde cualquier vista y el JavaScript lo
    reconoce por `data-dashboard`. Se renderiza con las cifras que ya conoce el
    servidor, así que tiene sentido aunque el JS no llegue a ejecutarse; el
    módulo solo lo mantiene fresco cada 30 segundos.
--}}

@php
    $datos = app(\App\Services\Publico\ConstruirDashboard::class)();
    $totales = $datos['totales'];

    // El símbolo, no el código ISO: el navegador formatea con
    // Intl.NumberFormat('es-PE') y escribe «S/». Si el servidor pintara «PEN»,
    // la cifra cambiaría de forma sola en el primer refresco.
    $simbolo = match ($totales['moneda']) {
        'PEN' => 'S/',
        'USD' => '$',
        'EUR' => '€',
        default => $totales['moneda'],
    };
@endphp

<section class="don-dashboard" data-dashboard aria-labelledby="titulo-dashboard">
    <h2 class="don-dashboard__titulo" id="titulo-dashboard">
        <i data-lucide="trending-up"></i>
        Cómo va la campaña
    </h2>

    <div class="don-totales">
        <article class="don-total">
            <span aria-hidden="true"><i data-lucide="hand-coins"></i></span>
            <div>
                <strong data-total-recaudado>{{ $simbolo }} {{ number_format($totales['recaudado'], 2) }}</strong>
                <p>Recaudado</p>
            </div>
        </article>
        <article class="don-total">
            <span aria-hidden="true"><i data-lucide="heart-handshake"></i></span>
            <div>
                <strong data-total-donaciones>{{ $totales['donaciones'] }}</strong>
                <p>Aportes recibidos</p>
            </div>
        </article>
        <article class="don-total">
            <span aria-hidden="true"><i data-lucide="users-round"></i></span>
            <div>
                <strong data-total-donantes>{{ $totales['donantes_unicos'] }}</strong>
                <p>Personas que han donado</p>
            </div>
        </article>
    </div>

    {{-- aria-live: el feed se actualiza solo, y quien use lector de pantalla
         tiene que enterarse sin recargar. --}}
    <ul class="don-feed" data-feed aria-live="polite">
        @forelse ($datos['recientes'] as $reciente)
            <li>
                <span>
                    <strong>{{ $reciente['nombre'] }}</strong>
                    <span class="don-feed__cuando">{{ $reciente['hace'] }}</span>
                </span>
                <span class="don-feed__monto">
                    {{ $simbolo }} {{ number_format($reciente['monto'], 2) }}
                </span>
            </li>
        @empty
            <li class="don-feed__vacio">
                Todavía no hay donaciones. Puedes ser la primera persona en aportar.
            </li>
        @endforelse
    </ul>
</section>
