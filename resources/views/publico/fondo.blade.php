@extends('publico.layout')

@section('titulo', $fondo->nombre)
@section('descripcion', $fondo->resumen)

@section('etiqueta', $fondo->estado->etiqueta())
@section('encabezado', $fondo->nombre)
@section('entradilla', $fondo->resumen)

@section('migas')
    <a href="{{ route('donar') }}">Proyectos</a>
    <i data-lucide="chevron-right"></i>
    <span>{{ $fondo->nombre }}</span>
@endsection

@section('cta-cabecera')
    @if ($fondo->aceptaDonaciones())
        <a class="button button-coral header-cta" href="{{ route('donar.fondo', $fondo) }}">
            Donar <i data-lucide="heart-handshake"></i>
        </a>
    @else
        <a class="button button-coral header-cta" href="{{ route('donar') }}">
            Ver proyectos <i data-lucide="arrow-right"></i>
        </a>
    @endif
@endsection

@section('contenido')

    {{--
        CABECERA DEL FONDO

        La imagen va de fondo con un degradado navy encima: sin él, el texto
        cae sobre la parte clara de cualquier fotografía y deja de leerse.

        Las tres cifras viven AQUÍ y no en el panel lateral para no repetirlas:
        son el dato clave del proyecto, y el lateral se queda con el progreso y
        el botón. Llevan `data-contador` para que `progreso.js` las cuente al
        entrar en pantalla; el número ya está escrito, así que sin JavaScript
        se ven correctas desde el primer instante.
    --}}
    <section class="don-portada {{ $fondo->color_token->claseCss() }}">
        @if ($fondo->imagen_portada)
            <img src="{{ Str::startsWith($fondo->imagen_portada, ['media/', 'uploads/']) ? '/' . $fondo->imagen_portada : '/uploads/fondos/' . $fondo->imagen_portada }}"
                 alt="{{ $fondo->nombre }}"
                 loading="lazy">
        @endif

        <div class="don-portada__pie">
            <dl class="don-portada__cifras">
                <div>
                    <dt>Recaudado</dt>
                    <dd data-fondo-recaudado data-contador>{{ $simboloMoneda }} {{ number_format($metricas['recaudado'], 2) }}</dd>
                    @include('publico.componentes.pendiente', [
                        'monto' => $metricas['pendiente'],
                        'aportes' => $metricas['pendientes_aportes'],
                        'simbolo' => $simboloMoneda,
                        'tono' => 'claro',
                    ])
                </div>
                <div>
                    <dt>Aportes</dt>
                    <dd><span data-fondo-donaciones data-contador>{{ $metricas['donaciones'] }}</span></dd>
                </div>
                <div>
                    <dt>Personas</dt>
                    <dd data-contador>{{ $metricas['donantes_unicos'] }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <article class="don-layout" data-fondo-slug="{{ $fondo->slug }}">

        <div>
            @if ($fondo->descripcion)
                <div class="don-panel don-prosa">
                    <h2 class="don-panel__titulo">Sobre el proyecto</h2>
                    {!! nl2br(e($fondo->descripcion)) !!}
                </div>
            @endif

            @if ($fondo->medios->isNotEmpty())
                <div class="don-panel">
                    <h2 class="don-panel__titulo">Galería</h2>
                    <div class="don-galeria">
                        @foreach ($fondo->medios as $medio)
                            <img src="/uploads/fondos/{{ $medio->ruta }}"
                                 alt="{{ $medio->alt ?: $fondo->nombre }}"
                                 loading="lazy">
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Progreso y llamada a la acción, fijos al lado mientras se lee. --}}
        <aside class="don-aside">
            <div class="don-resumen {{ $fondo->color_token->claseCss() }}">
                <span class="pill pill-yellow">RECAUDACIÓN</span>

                {{-- Sin meta no hay barra: un 0 % se lee como un fracaso, no
                     como una campaña que acaba de empezar. En su lugar manda la
                     cifra recaudada, ya visible en la cabecera. --}}
                @if ($metricas['porcentaje'] !== null)
                    <h2>{{ $metricas['porcentaje'] }}% de la meta</h2>

                    <div class="don-resumen__progreso">
                        <span class="don-progreso"
                              data-fondo-progreso
                              role="progressbar"
                              aria-valuenow="{{ (int) $metricas['porcentaje'] }}"
                              aria-valuemin="0"
                              aria-valuemax="100"
                              aria-label="Progreso de {{ $fondo->nombre }}"><span
                                  class="don-progreso__relleno"
                                  data-fondo-progreso-relleno
                                  style="width: {{ $metricas['porcentaje'] }}%"></span>@if ($metricas['porcentaje_pendiente'] !== null)<span
                                  class="don-progreso__pendiente"
                                  style="width: {{ $metricas['porcentaje_pendiente'] }}%"
                                  title="Por verificar"></span>@endif</span>
                        <span class="don-progreso__texto">
                            <span>{{ $simboloMoneda }} {{ number_format($metricas['recaudado'], 2) }}</span>
                            <span>de {{ $simboloMoneda }} {{ number_format((float) $metricas['meta'], 0) }}</span>
                        </span>
                    </div>
                @else
                    <h2>{{ $simboloMoneda }} {{ number_format($metricas['recaudado'], 2) }}</h2>
                    <p class="don-resumen__cerrado">
                        Este proyecto todavía no tiene una meta pública definida.
                    </p>
                @endif

                @if ($fondo->aceptaDonaciones())
                    <a class="button button-coral don-resumen__boton" href="{{ route('donar.fondo', $fondo) }}">
                        Donar a este proyecto <i data-lucide="heart-handshake"></i>
                    </a>
                @else
                    <p class="don-resumen__cerrado">
                        Este proyecto ya no recibe donaciones. Lo que ves es lo que se recaudó.
                    </p>
                    <a class="button button-coral don-resumen__boton" href="{{ route('donar') }}">
                        Ver proyectos abiertos <i data-lucide="arrow-right"></i>
                    </a>
                @endif
            </div>
        </aside>
    </article>

@endsection
