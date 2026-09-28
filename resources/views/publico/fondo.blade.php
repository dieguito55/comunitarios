@extends('publico.layout')

@section('titulo', $fondo->nombre)
@section('descripcion', $fondo->resumen)

@section('contenido')

    <article class="{{ $fondo->color_token->claseCss() }}" data-fondo-slug="{{ $fondo->slug }}">
        <p>
            <span class="estado estado--{{ $fondo->estado->value }}">{{ $fondo->estado->etiqueta() }}</span>
        </p>

        <h1 class="don-titulo">{{ $fondo->nombre }}</h1>
        <p class="don-entradilla">{{ $fondo->resumen }}</p>

        @if ($fondo->imagen_portada)
            <img src="{{ Str::startsWith($fondo->imagen_portada, ['media/', 'uploads/']) ? '/' . $fondo->imagen_portada : '/uploads/fondos/' . $fondo->imagen_portada }}"
                 alt="{{ $fondo->nombre }}"
                 style="width:100%;border-radius:14px"
                 loading="lazy">
        @endif

        <div class="don-paso">
            <p class="don-cifra">
                <span data-fondo-recaudado>{{ $metricas['moneda'] }} {{ number_format($metricas['recaudado'], 2) }}</span>
                <small>
                    recaudados ·
                    <span data-fondo-donaciones>{{ $metricas['donaciones'] }}</span> donaciones ·
                    {{ $metricas['donantes_unicos'] }} {{ $metricas['donantes_unicos'] === 1 ? 'persona' : 'personas' }}
                </small>
            </p>

            @if ($metricas['porcentaje'] !== null)
                <div class="don-progreso"
                     data-fondo-progreso
                     role="progressbar"
                     aria-valuenow="{{ (int) $metricas['porcentaje'] }}"
                     aria-valuemin="0"
                     aria-valuemax="100"
                     aria-label="Progreso de {{ $fondo->nombre }}">
                    <div class="don-progreso__relleno"
                         data-fondo-progreso-relleno
                         style="width: {{ $metricas['porcentaje'] }}%"></div>
                </div>
                <p class="don-progreso__texto">
                    {{ $metricas['porcentaje'] }}% de la meta de {{ number_format((float) $metricas['meta'], 2) }}
                </p>
            @endif

            @if ($fondo->aceptaDonaciones())
                <p><a class="don-boton" href="{{ route('donar.fondo', $fondo) }}">Donar a este proyecto</a></p>
            @else
                <p class="don-campo__ayuda">
                    Este proyecto ya no recibe donaciones. Lo que ves es lo que se recaudó.
                </p>
                <p><a class="don-boton don-boton--secundario" href="{{ route('donar') }}">Ver proyectos abiertos</a></p>
            @endif
        </div>

        @if ($fondo->descripcion)
            <div class="don-paso">
                {!! nl2br(e($fondo->descripcion)) !!}
            </div>
        @endif

        @if ($fondo->medios->isNotEmpty())
            <div class="don-paso">
                <h2>Galería</h2>
                @foreach ($fondo->medios as $medio)
                    <img src="/uploads/fondos/{{ $medio->ruta }}"
                         alt="{{ $medio->alt ?: $fondo->nombre }}"
                         style="width:100%;border-radius:10px;margin-bottom:.75rem"
                         loading="lazy">
                @endforeach
            </div>
        @endif
    </article>

@endsection
