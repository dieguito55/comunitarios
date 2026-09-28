@extends('admin.layouts.app')

@section('titulo', 'Resumen')
@section('descripcion', 'Lo que lleva recaudado cada fondo, y si las cifras cuadran.')

@section('contenido')

    {{-- PANEL DE SALUD. Va lo primero a propósito: si el contador de un fondo
         no coincide con la suma real de sus donaciones, hay un error de
         contabilidad y esta es la primera pantalla donde se vería. --}}
    @if ($descuadres->isNotEmpty())
        <div class="admin-aviso admin-aviso--error" role="alert">
            <h2>Las cifras no cuadran</h2>
            <p>
                El contador de {{ $descuadres->count() === 1 ? 'un fondo no coincide' : 'estos fondos no coincide' }}
                con la suma real de sus donaciones. <strong>No publiques estas cifras</strong> hasta resolverlo.
            </p>
            <ul>
                @foreach ($descuadres as $descuadre)
                    <li>
                        <strong>{{ $descuadre->slug }}</strong>:
                        contador {{ number_format((float) $descuadre->recaudado, 2) }},
                        real {{ number_format((float) $descuadre->total_real, 2) }}
                        (diferencia {{ number_format((float) $descuadre->diferencia, 2) }})
                    </li>
                @endforeach
            </ul>
            <p>
                Para repararlo, desde el servidor:
                <code>php artisan fondos:recalcular --dry-run</code> para ver el alcance,
                y luego sin <code>--dry-run</code>.
            </p>
        </div>
    @else
        <div class="admin-aviso admin-aviso--exito" role="status">
            <h2>Las cifras cuadran</h2>
            <p>El contador de cada fondo coincide con la suma real de sus donaciones aprobadas.</p>
        </div>
    @endif

    @if ($sinRedactar->isNotEmpty())
        <div class="admin-aviso admin-aviso--atencion" role="alert">
            <h2>Hay texto sin redactar publicado</h2>
            <p>
                {{ $sinRedactar->count() === 1 ? 'Este fondo está' : 'Estos fondos están' }}
                activo{{ $sinRedactar->count() === 1 ? '' : 's' }} con el resumen todavía en «PENDIENTE»,
                y ese texto es el que ve el donante al elegir a dónde aporta:
            </p>
            <ul>
                @foreach ($sinRedactar as $fondo)
                    <li><a href="{{ route('admin.fondos.editar', $fondo) }}">{{ $fondo->nombre }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-rejilla">
        <div class="admin-tarjeta">
            <p class="admin-cifra">
                {{ number_format($totalRecaudado, 2) }}
                <small>Recaudado en total (PEN)</small>
            </p>
        </div>
        <div class="admin-tarjeta">
            <p class="admin-cifra">
                {{ number_format($totalDonaciones) }}
                <small>Donaciones confirmadas</small>
            </p>
        </div>
        <div class="admin-tarjeta">
            <p class="admin-cifra">
                {{ $tarjetas->count() }}
                <small>Fondos creados</small>
            </p>
        </div>

        {{-- La cola de trabajo, no las cuentas. Este dinero NO está en
             «recaudado» y no lo estará hasta que alguien mire el comprobante.
             Sale aquí porque si no se ve al entrar, no se hace. --}}
        @if ($pendientes['aportes'] > 0)
            <div class="admin-tarjeta admin-tarjeta--atencion">
                <p class="admin-cifra">
                    {{ number_format($pendientes['monto'], 2) }}
                    <small>
                        Por verificar ·
                        {{ $pendientes['aportes'] }} {{ $pendientes['aportes'] === 1 ? 'aporte' : 'aportes' }}
                    </small>
                </p>
                <a class="boton boton--secundario" href="{{ route('admin.verificacion.index') }}">
                    Revisar la cola
                </a>
            </div>
        @endif
    </div>

    <h2>Por fondo</h2>

    @if ($tarjetas->isEmpty())
        <div class="admin-tarjeta">
            <p>Todavía no hay ningún fondo.</p>
            @can('crear', App\Models\Fondo::class)
                <a class="boton" href="{{ route('admin.fondos.crear') }}">Crear el primero</a>
            @endcan
        </div>
    @else
        <div class="admin-rejilla">
            @foreach ($tarjetas as $tarjeta)
                @php($fondo = $tarjeta['fondo'])
                @php($m = $tarjeta['metricas'])

                <article class="admin-tarjeta {{ $fondo->color_token->claseCss() }}">
                    <p>
                        <span class="fondo-muestra" aria-hidden="true"></span>
                        <span class="estado estado--{{ $fondo->estado->value }}">{{ $fondo->estado->etiqueta() }}</span>
                    </p>

                    <h3>
                        <a href="{{ route('admin.fondos.editar', $fondo) }}">{{ $fondo->nombre }}</a>
                    </h3>

                    <p class="admin-cifra">
                        {{ number_format($m['recaudado'], 2) }}
                        <small>{{ $m['moneda'] }} recaudados y confirmados</small>
                    </p>

                    @if ($m['pendientes_aportes'] > 0)
                        <p class="admin-pendiente">
                            <a href="{{ route('admin.verificacion.index', ['fondo' => $fondo->id, 'estado' => 'pendiente']) }}">
                                + {{ $m['moneda'] }} {{ number_format($m['pendiente'], 2) }} por verificar
                                ({{ $m['pendientes_aportes'] }} {{ $m['pendientes_aportes'] === 1 ? 'aporte' : 'aportes' }})
                            </a>
                        </p>
                    @endif

                    <p>
                        {{ $m['donaciones'] }} {{ $m['donaciones'] === 1 ? 'donación' : 'donaciones' }}
                        · {{ $m['donantes_unicos'] }} {{ $m['donantes_unicos'] === 1 ? 'donante' : 'donantes' }}
                    </p>

                    {{-- Sin meta no hay barra: mejor no enseñar nada que un
                         porcentaje inventado. --}}
                    @if ($m['porcentaje'] !== null)
                        <div class="admin-progreso"
                             role="progressbar"
                             aria-valuenow="{{ (int) $m['porcentaje'] }}"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             aria-label="Progreso de {{ $fondo->nombre }}">
                            <div class="admin-progreso__relleno" style="width: {{ $m['porcentaje'] }}%"></div>
                        </div>
                        <p>{{ $m['porcentaje'] }}% de {{ number_format((float) $m['meta'], 2) }}</p>
                    @else
                        <p class="campo__ayuda">Sin meta pública definida.</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

@endsection
