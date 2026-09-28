@extends('publico.layout')

@section('titulo', $titulo)
@section('noindex', true)

@section('cta-cabecera')
    <a class="button button-coral header-cta" href="{{ route('donar') }}">
        Donar <i data-lucide="heart-handshake"></i>
    </a>
@endsection

@section('etiqueta', $codigo)
@section('encabezado', $titulo)

@section('contenido')

    {{--
        ANATOMÍA COMPARTIDA DE LAS PÁGINAS DE ERROR

        Icono, titular claro, una o dos frases sin jerga, y acciones útiles.

        NUNCA detalles técnicos: ni traza, ni nombre de excepción, ni ruta de
        archivo. `APP_DEBUG` está en false en producción y así se queda, pero
        esta plantilla tampoco los enseñaría aunque estuviera en true.

        Se reutiliza el marcado de la pantalla de resultado —tarjeta centrada
        con orbe— para que un error no parezca de otro sitio web.
    --}}

    <section class="don-resultado don-resultado--{{ $tono }}">
        <div class="don-resultado__orbe" aria-hidden="true">
            <i data-lucide="{{ $icono }}"></i>
        </div>

        <h1>{{ $titulo }}</h1>

        <p>{{ $mensaje }}</p>

        @isset($detalle)
            <p class="don-campo__ayuda" style="margin-top: var(--espacio-4)">{{ $detalle }}</p>
        @endisset

        <div class="don-resultado__acciones">
            @foreach ($acciones as $accion)
                <a class="button {{ $loop->first ? 'button-coral' : 'button-ghost' }}" href="{{ $accion['url'] }}">
                    {{ $accion['texto'] }}
                    @isset($accion['icono'])
                        <i data-lucide="{{ $accion['icono'] }}"></i>
                    @endisset
                </a>
            @endforeach
        </div>

        @if ($correoContacto ?? false)
            <p class="don-campo__ayuda">
                ¿Sigue pasando? Escríbenos a {{ $correoContacto }} y lo revisamos.
            </p>
        @endif
    </section>

@endsection
