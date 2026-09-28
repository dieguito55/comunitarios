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

    <article class="don-layout {{ $fondo->color_token->claseCss() }}" data-fondo-slug="{{ $fondo->slug }}">

        <div>
            @if ($fondo->imagen_portada)
                <img class="don-portada"
                     src="{{ Str::startsWith($fondo->imagen_portada, ['media/', 'uploads/']) ? '/' . $fondo->imagen_portada : '/uploads/fondos/' . $fondo->imagen_portada }}"
                     alt="{{ $fondo->nombre }}"
                     loading="lazy">
            @endif

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

        {{-- Las cifras, fijas al lado mientras se lee la descripción. --}}
        <aside class="don-aside">
            <div class="don-resumen">
                <span class="pill pill-yellow">RECAUDACIÓN</span>

                <div class="don-resumen__linea don-resumen__total">
                    <span>Recaudado</span>
                    <strong data-fondo-recaudado>{{ $simboloMoneda }} {{ number_format($metricas['recaudado'], 2) }}</strong>
                </div>
                <div class="don-resumen__linea">
                    <span>Aportes</span>
                    <strong><span data-fondo-donaciones>{{ $metricas['donaciones'] }}</span></strong>
                </div>
                <div class="don-resumen__linea">
                    <span>Personas</span>
                    <strong>{{ $metricas['donantes_unicos'] }}</strong>
                </div>

                {{-- Sin meta no hay barra: un 0 % se lee como un fracaso, no
                     como una campaña que acaba de empezar. --}}
                @if ($metricas['porcentaje'] !== null)
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
                                  style="width: {{ $metricas['porcentaje'] }}%"></span></span>
                        <span class="don-progreso__texto">
                            <span>{{ $metricas['porcentaje'] }}% de la meta</span>
                            <span>{{ $simboloMoneda }} {{ number_format((float) $metricas['meta'], 0) }}</span>
                        </span>
                    </div>
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
