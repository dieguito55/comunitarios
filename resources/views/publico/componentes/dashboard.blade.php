{{--
    Dashboard en vivo de la campaña.

    Componente autónomo: se incluye desde cualquier vista y el JavaScript lo
    reconoce por `data-dashboard`. Se renderiza con las cifras que ya conoce el
    servidor, así que tiene sentido aunque el JS no llegue a ejecutarse; el
    módulo solo lo mantiene fresco cada 30 segundos.

    Las cifras NO llevan `data-contador`: quien las anima aquí es `dashboard.js`
    en su primera carga, y dos módulos contando el mismo número a la vez se
    pisarían. `progreso.js` ignora a propósito todo lo que cuelgue de
    `[data-dashboard]`.
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
                <p>Recaudado y confirmado</p>
                @include('publico.componentes.pendiente', [
                    'monto' => $totales['pendiente'],
                    'aportes' => $totales['pendientes_aportes'],
                    'simbolo' => $simbolo,
                ])
            </div>
        </article>
        <article class="don-total">
            <span aria-hidden="true"><i data-lucide="heart-handshake"></i></span>
            <div>
                <strong data-total-donaciones>{{ $totales['donaciones'] }}</strong>
                <p>Aportes confirmados</p>
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
            {{--
                ESTADO VACÍO

                «Todavía no hay donaciones» es justo el momento en que más falta
                hace una invitación, y era una línea gris. La ilustración es SVG
                en línea —sin librerías— y toma sus colores de clases, no de
                atributos `fill`, para no escribir ni un color literal.
            --}}
            <li class="don-vacio">
                <svg viewBox="0 0 120 120" role="img" aria-label="Todavía no hay aportes">
                    <circle class="don-vacio__relleno" cx="60" cy="62" r="44"/>
                    <circle class="don-vacio__trazo" cx="60" cy="62" r="44" stroke-dasharray="7 11" stroke-linecap="round"/>
                    <path class="don-vacio__acento"
                          d="M60 84s-19-12-19-25a11 11 0 0 1 19-7 11 11 0 0 1 19 7c0 13-19 25-19 25z"
                          stroke-linejoin="round"/>
                    <path class="don-vacio__acento" d="M94 28l3-7 3 7 7 3-7 3-3 7-3-7-7-3z" stroke-linejoin="round"/>
                </svg>

                <strong>Todavía no hay aportes</strong>
                <p>
                    Esta campaña acaba de empezar. El primero se verá aquí, con su nombre
                    abreviado o como anónimo, según elija quien dona.
                </p>
                <a class="button button-coral" href="{{ route('donar') }}">
                    Ser la primera persona <i data-lucide="heart-handshake"></i>
                </a>
            </li>
        @endforelse
    </ul>
</section>
